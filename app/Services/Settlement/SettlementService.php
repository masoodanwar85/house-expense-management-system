<?php

namespace App\Services\Settlement;

use App\Exceptions\DomainException;
use App\Models\House;
use App\Models\User;
use App\Services\Monthly\DTO\UserBalance;
use App\Services\Monthly\MonthlySettlementService;
use App\Services\Settlement\DTO\SettlementPlan;
use App\Services\Settlement\DTO\SettlementTransfer;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Builds a minimal set of debtor→creditor transfers from net balances.
 *
 * Algorithm (greedy, deterministic):
 * 1. Separate positive balances (creditors) and negative balances (debtors).
 * 2. Sort each side by absolute amount descending, then user_id ascending.
 * 3. Repeatedly settle min(debt, credit) between the current largest pair.
 *
 * Transfers are computed, not persisted. Closing a month does not freeze them;
 * regenerating after reopen/edit reflects current stored allocations.
 * Confirmed settlement payments are applied pairwise onto suggested transfers:
 * paying A→B reduces that debt; overpayment becomes B→A credit (not reshuffled
 * to other members via global re-netting).
 */
class SettlementService
{
    public function __construct(
        private readonly MonthlySettlementService $monthlySettlementService,
        private readonly SettlementPaymentService $payments,
    ) {}

    public function forMonth(House $house, User $actor, int $year, int $month): SettlementPlan
    {
        $summary = $this->monthlySettlementService->summarize($house, $actor, $year, $month);

        $transfers = $this->payments->applyPaymentsToTransfers(
            $this->generateTransfers($summary->balances),
            $this->payments->confirmedForMonth($house, $year, $month)
        );

        $balances = $this->payments->balancesAfterTransfers($summary->balances, $transfers);
        $this->assertNetZero($balances);

        return new SettlementPlan(
            houseId: $summary->houseId,
            year: $summary->year,
            month: $summary->month,
            totalExpenses: $summary->totalExpenses,
            balances: $balances,
            transfers: $transfers,
            summary: $summary,
        );
    }

    /**
     * @param  Collection<int, UserBalance>|iterable<UserBalance|array{user_id: int, balance: string}>  $balances
     * @return Collection<int, SettlementTransfer>
     */
    public function generateTransfers(iterable $balances): Collection
    {
        $creditors = [];
        $debtors = [];

        foreach ($balances as $row) {
            if ($row instanceof UserBalance) {
                $userId = $row->userId;
                $balance = Money::of($row->balance);
            } else {
                $userId = (int) $row['user_id'];
                $balance = Money::of($row['balance']);
            }

            $cmp = Money::compare($balance, '0.00');

            if ($cmp === 1) {
                $creditors[] = ['user_id' => $userId, 'remaining' => $balance];
            } elseif ($cmp === -1) {
                $debtors[] = [
                    'user_id' => $userId,
                    'remaining' => Money::sub('0.00', $balance), // absolute debt
                ];
            }
        }

        $this->sortByRemainingDescThenUserId($creditors);
        $this->sortByRemainingDescThenUserId($debtors);

        $transfers = [];
        $i = 0;
        $j = 0;

        while ($i < count($debtors) && $j < count($creditors)) {
            $pay = Money::compare($debtors[$i]['remaining'], $creditors[$j]['remaining']) <= 0
                ? $debtors[$i]['remaining']
                : $creditors[$j]['remaining'];

            if (Money::compare($pay, '0.00') === 1) {
                $transfers[] = new SettlementTransfer(
                    fromUserId: $debtors[$i]['user_id'],
                    toUserId: $creditors[$j]['user_id'],
                    amount: $pay,
                );
            }

            $debtors[$i]['remaining'] = Money::sub($debtors[$i]['remaining'], $pay);
            $creditors[$j]['remaining'] = Money::sub($creditors[$j]['remaining'], $pay);

            if (Money::compare($debtors[$i]['remaining'], '0.00') === 0) {
                $i++;
            }

            if (Money::compare($creditors[$j]['remaining'], '0.00') === 0) {
                $j++;
            }
        }

        // Residual pennies after greedy matching indicate non-zero-sum input.
        foreach ([...$debtors, ...$creditors] as $side) {
            if (Money::compare($side['remaining'], '0.00') !== 0) {
                throw DomainException::because('Balances do not net to zero; cannot generate settlements.');
            }
        }

        return collect($transfers)->values();
    }

    /**
     * @param  Collection<int, UserBalance>  $balances
     */
    private function assertNetZero(Collection $balances): void
    {
        $sum = '0.00';

        foreach ($balances as $balance) {
            $sum = Money::add($sum, $balance->balance);
        }

        if (Money::compare($sum, '0.00') !== 0) {
            throw DomainException::because('Monthly balances after payments do not net to zero.');
        }
    }

    /**
     * @param  list<array{user_id: int, remaining: string}>  $rows
     */
    private function sortByRemainingDescThenUserId(array &$rows): void
    {
        usort($rows, function (array $a, array $b): int {
            $cmp = Money::compare($b['remaining'], $a['remaining']);

            if ($cmp !== 0) {
                return $cmp;
            }

            return $a['user_id'] <=> $b['user_id'];
        });
    }
}
