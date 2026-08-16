<?php

namespace App\Services\Settlement;

use App\Enums\SettlementPaymentStatus;
use App\Exceptions\DomainException;
use App\Models\House;
use App\Models\SettlementPayment;
use App\Models\User;
use App\Services\House\HouseAccessService;
use App\Services\Monthly\DTO\UserBalance;
use App\Services\Settlement\DTO\SettlementTransfer;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records cash/settlement payments between members.
 * Pending until the recipient confirms; only confirmed payments reduce owing
 * (for the tagged month and in lifetime totals).
 */
class SettlementPaymentService
{
    public function __construct(
        private readonly HouseAccessService $access,
    ) {}

    /**
     * @return Collection<int, SettlementPayment>
     */
    public function listForHouse(House $house, User $actor, ?SettlementPaymentStatus $status = null): Collection
    {
        $this->access->assertMember($house, $actor);

        $query = SettlementPayment::query()
            ->where('house_id', $house->id)
            ->with(['fromUser', 'toUser', 'recorder', 'confirmer'])
            ->orderByDesc('id');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /**
     * Payer records a payment they made to another member toward a given month's debt.
     *
     * @param  array{
     *     to_user_id: int,
     *     amount: float|int|string,
     *     year: int,
     *     month: int,
     *     note?: string|null
     * }  $data
     */
    public function record(House $house, User $actor, array $data): SettlementPayment
    {
        $this->access->assertMember($house, $actor);

        $toUserId = (int) $data['to_user_id'];
        $amount = Money::of($data['amount']);
        $year = (int) $data['year'];
        $month = (int) $data['month'];

        if ($month < 1 || $month > 12 || $year < 1970 || $year > 2100) {
            throw ValidationException::withMessages([
                'month' => ['Provide a valid year and month for this payment.'],
            ]);
        }

        if (Money::compare($amount, '0.00') !== 1) {
            throw ValidationException::withMessages([
                'amount' => ['Payment amount must be greater than zero.'],
            ]);
        }

        if ($toUserId === (int) $actor->id) {
            throw ValidationException::withMessages([
                'to_user_id' => ['You cannot record a payment to yourself.'],
            ]);
        }

        $payee = User::query()->findOrFail($toUserId);
        $this->access->assertMember($house, $payee);

        $note = isset($data['note']) && $data['note'] !== ''
            ? trim((string) $data['note'])
            : null;

        return SettlementPayment::query()->create([
            'house_id' => $house->id,
            'from_user_id' => $actor->id,
            'to_user_id' => $toUserId,
            'year' => $year,
            'month' => $month,
            'amount' => $amount,
            'status' => SettlementPaymentStatus::Pending,
            'note' => $note,
            'recorded_by' => $actor->id,
        ])->load(['fromUser', 'toUser']);
    }

    public function confirm(SettlementPayment $payment, User $actor): SettlementPayment
    {
        return DB::transaction(function () use ($payment, $actor) {
            $locked = $this->lockPendingRow($payment);
            $this->access->assertMember($locked->house, $actor);

            if ((int) $actor->id !== (int) $locked->to_user_id) {
                throw DomainException::because('Only the recipient can confirm this payment.');
            }

            $locked->status = SettlementPaymentStatus::Confirmed;
            $locked->confirmed_by = $actor->id;
            $locked->confirmed_at = now();
            $locked->rejected_at = null;
            $locked->save();

            return $locked->fresh(['fromUser', 'toUser', 'confirmer']);
        });
    }

    public function reject(SettlementPayment $payment, User $actor): SettlementPayment
    {
        return DB::transaction(function () use ($payment, $actor) {
            $locked = $this->lockPendingRow($payment);
            $this->access->assertMember($locked->house, $actor);

            if ((int) $actor->id !== (int) $locked->to_user_id) {
                throw DomainException::because('Only the recipient can reject this payment.');
            }

            $locked->status = SettlementPaymentStatus::Rejected;
            $locked->rejected_at = now();
            $locked->confirmed_by = null;
            $locked->confirmed_at = null;
            $locked->save();

            return $locked->fresh(['fromUser', 'toUser']);
        });
    }

    public function cancel(SettlementPayment $payment, User $actor): SettlementPayment
    {
        return DB::transaction(function () use ($payment, $actor) {
            $locked = $this->lockPendingRow($payment);
            $this->access->assertMember($locked->house, $actor);

            $isPayer = (int) $actor->id === (int) $locked->from_user_id;
            $isOwner = $this->access->isOwner($locked->house, $actor);

            if (! $isPayer && ! $isOwner) {
                throw DomainException::because('Only the payer or house owner can cancel this payment.');
            }

            $locked->status = SettlementPaymentStatus::Cancelled;
            $locked->save();

            return $locked->fresh(['fromUser', 'toUser']);
        });
    }

    /**
     * @return Collection<int, SettlementPayment>
     */
    public function confirmedForHouse(House $house): Collection
    {
        return SettlementPayment::query()
            ->where('house_id', $house->id)
            ->where('status', SettlementPaymentStatus::Confirmed)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, SettlementPayment>
     */
    public function confirmedForMonth(House $house, int $year, int $month): Collection
    {
        return SettlementPayment::query()
            ->where('house_id', $house->id)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', SettlementPaymentStatus::Confirmed)
            ->orderBy('id')
            ->get();
    }

    /**
     * Apply directed confirmed payments onto expense-suggested transfers.
     *
     * Paying A→B reduces what A owes B. Any excess becomes B→A credit
     * (carries to later months) and is NOT redistributed to other members.
     *
     * @param  Collection<int, SettlementTransfer>  $transfers
     * @param  iterable<SettlementPayment>  $payments
     * @return Collection<int, SettlementTransfer>
     */
    public function applyPaymentsToTransfers(Collection $transfers, iterable $payments): Collection
    {
        /** @var array<int, array<int, string>> $debt from => to => amount */
        $debt = [];

        foreach ($transfers as $transfer) {
            $from = $transfer->fromUserId;
            $to = $transfer->toUserId;
            $debt[$from][$to] = Money::add($debt[$from][$to] ?? '0.00', $transfer->amount);
        }

        foreach ($payments as $payment) {
            $from = (int) $payment->from_user_id;
            $to = (int) $payment->to_user_id;
            $remaining = Money::of((string) $payment->amount);

            $owed = $debt[$from][$to] ?? '0.00';
            $applied = Money::compare($remaining, $owed) <= 0 ? $remaining : $owed;

            if (Money::compare($applied, '0.00') === 1) {
                $left = Money::sub($owed, $applied);
                if (Money::compare($left, '0.00') === 1) {
                    $debt[$from][$to] = $left;
                } else {
                    unset($debt[$from][$to]);
                    if ($debt[$from] === []) {
                        unset($debt[$from]);
                    }
                }
                $remaining = Money::sub($remaining, $applied);
            }

            if (Money::compare($remaining, '0.00') === 1) {
                $debt[$to][$from] = Money::add($debt[$to][$from] ?? '0.00', $remaining);
            }
        }

        return $this->transfersFromDebtMatrix($this->netOpposingPairDebts($debt));
    }

    /**
     * Rebuild display nets from remaining pairwise transfers (Paid/Share stay expense-based).
     *
     * @param  Collection<int, UserBalance>  $expenseBalances
     * @param  Collection<int, SettlementTransfer>  $transfers
     * @return Collection<int, UserBalance>
     */
    public function balancesAfterTransfers(Collection $expenseBalances, Collection $transfers): Collection
    {
        /** @var array<int, UserBalance> $byUser */
        $byUser = [];
        /** @var array<int, string> $net */
        $net = [];

        foreach ($expenseBalances as $balance) {
            $byUser[$balance->userId] = $balance;
            $net[$balance->userId] = '0.00';
        }

        foreach ($transfers as $transfer) {
            $from = $transfer->fromUserId;
            $to = $transfer->toUserId;
            $amount = $transfer->amount;

            $byUser[$from] ??= $this->emptyBalance($from);
            $byUser[$to] ??= $this->emptyBalance($to);
            $net[$from] = Money::sub($net[$from] ?? '0.00', $amount);
            $net[$to] = Money::add($net[$to] ?? '0.00', $amount);
        }

        foreach ($byUser as $userId => $balance) {
            $byUser[$userId] = new UserBalance(
                userId: $balance->userId,
                actualPaid: $balance->actualPaid,
                responsibility: $balance->responsibility,
                balance: $net[$userId] ?? '0.00',
                availabilityDays: $balance->availabilityDays,
                role: $balance->role,
            );
        }

        return collect($byUser)->sortKeys()->values();
    }

    /**
     * @param  array<int, array<int, string>>  $debt
     * @return array<int, array<int, string>>
     */
    private function netOpposingPairDebts(array $debt): array
    {
        /** @var array<string, array{lo: int, hi: int, lo_owes_hi: string, hi_owes_lo: string}> $pairs */
        $pairs = [];

        foreach ($debt as $from => $tos) {
            foreach ($tos as $to => $amount) {
                if (Money::compare($amount, '0.00') !== 1) {
                    continue;
                }

                $lo = min((int) $from, (int) $to);
                $hi = max((int) $from, (int) $to);
                $key = $lo.':'.$hi;

                if (! isset($pairs[$key])) {
                    $pairs[$key] = [
                        'lo' => $lo,
                        'hi' => $hi,
                        'lo_owes_hi' => '0.00',
                        'hi_owes_lo' => '0.00',
                    ];
                }

                if ((int) $from === $lo) {
                    $pairs[$key]['lo_owes_hi'] = Money::add($pairs[$key]['lo_owes_hi'], $amount);
                } else {
                    $pairs[$key]['hi_owes_lo'] = Money::add($pairs[$key]['hi_owes_lo'], $amount);
                }
            }
        }

        $result = [];

        foreach ($pairs as $pair) {
            $cmp = Money::compare($pair['lo_owes_hi'], $pair['hi_owes_lo']);

            if ($cmp === 1) {
                $result[$pair['lo']][$pair['hi']] = Money::sub($pair['lo_owes_hi'], $pair['hi_owes_lo']);
            } elseif ($cmp === -1) {
                $result[$pair['hi']][$pair['lo']] = Money::sub($pair['hi_owes_lo'], $pair['lo_owes_hi']);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, string>>  $debt
     * @return Collection<int, SettlementTransfer>
     */
    private function transfersFromDebtMatrix(array $debt): Collection
    {
        $transfers = [];

        foreach ($debt as $from => $tos) {
            foreach ($tos as $to => $amount) {
                if (Money::compare($amount, '0.00') === 1) {
                    $transfers[] = new SettlementTransfer(
                        fromUserId: (int) $from,
                        toUserId: (int) $to,
                        amount: $amount,
                    );
                }
            }
        }

        usort($transfers, function (SettlementTransfer $a, SettlementTransfer $b) {
            return [$a->fromUserId, $a->toUserId] <=> [$b->fromUserId, $b->toUserId];
        });

        return collect($transfers)->values();
    }

    private function emptyBalance(int $userId): UserBalance
    {
        return new UserBalance(
            userId: $userId,
            actualPaid: '0.00',
            responsibility: '0.00',
            balance: '0.00',
            availabilityDays: 0,
            role: 'member',
        );
    }

    private function lockPendingRow(SettlementPayment $payment): SettlementPayment
    {
        $locked = SettlementPayment::query()
            ->whereKey($payment->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status !== SettlementPaymentStatus::Pending) {
            throw DomainException::because('Only pending payments can be updated.');
        }

        return $locked->load('house');
    }
}
