<?php

namespace App\Services\Settlement;

use App\Enums\ExpenseStatus;
use App\Exceptions\DomainException;
use App\Models\Expense;
use App\Models\House;
use App\Models\User;
use App\Services\House\HouseAccessService;
use App\Services\Monthly\DTO\UserBalance;
use App\Services\Settlement\DTO\OverallOwingPlan;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Lifetime owing across all confirmed expenses (all months),
 * with confirmed settlement payments applied pairwise (directed).
 *
 * Pending payments do not affect balances until the recipient confirms.
 * Overpayment A→B becomes B→A credit and is not redistributed to others.
 */
class OverallOwingService
{
    public function __construct(
        private readonly HouseAccessService $access,
        private readonly SettlementService $settlements,
        private readonly SettlementPaymentService $payments,
    ) {}

    public function forHouse(House $house, User $actor): OverallOwingPlan
    {
        $this->access->assertMember($house, $actor);

        $expenses = Expense::query()
            ->where('house_id', $house->id)
            ->where('status', ExpenseStatus::Confirmed)
            ->with('allocations')
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get();

        $paidByUser = [];
        $responsibilityByUser = [];
        $totalExpenses = '0.00';

        foreach ($expenses as $expense) {
            $amount = Money::of((string) $expense->amount);
            $totalExpenses = Money::add($totalExpenses, $amount);

            $payerId = (int) $expense->paid_by;
            $paidByUser[$payerId] = Money::add($paidByUser[$payerId] ?? '0.00', $amount);

            foreach ($expense->allocations as $allocation) {
                $uid = (int) $allocation->user_id;
                $responsibilityByUser[$uid] = Money::add(
                    $responsibilityByUser[$uid] ?? '0.00',
                    Money::of((string) $allocation->amount)
                );
            }
        }

        $this->assertExpenseTotalsConserve($totalExpenses, $paidByUser, $responsibilityByUser);

        $expenseBalances = collect(
            collect([...array_keys($paidByUser), ...array_keys($responsibilityByUser)])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all()
        )->map(function (int $userId) use ($paidByUser, $responsibilityByUser) {
            $paid = $paidByUser[$userId] ?? '0.00';
            $responsibility = $responsibilityByUser[$userId] ?? '0.00';

            return new UserBalance(
                userId: $userId,
                actualPaid: $paid,
                responsibility: $responsibility,
                balance: Money::sub($paid, $responsibility),
                availabilityDays: 0,
                role: 'member',
            );
        });

        $transfers = $this->payments->applyPaymentsToTransfers(
            $this->settlements->generateTransfers($expenseBalances),
            $this->payments->confirmedForHouse($house)
        );

        $balances = $this->payments->balancesAfterTransfers($expenseBalances, $transfers);
        $this->assertNetZero($balances);

        return new OverallOwingPlan(
            houseId: $house->id,
            totalExpenses: $totalExpenses,
            balances: $balances,
            transfers: $transfers,
        );
    }

    /**
     * @param  array<int, string>  $paidByUser
     * @param  array<int, string>  $responsibilityByUser
     */
    private function assertExpenseTotalsConserve(string $totalExpenses, array $paidByUser, array $responsibilityByUser): void
    {
        $paidSum = '0.00';
        $responsibilitySum = '0.00';

        foreach ($paidByUser as $paid) {
            $paidSum = Money::add($paidSum, $paid);
        }

        foreach ($responsibilityByUser as $responsibility) {
            $responsibilitySum = Money::add($responsibilitySum, $responsibility);
        }

        if (Money::compare($paidSum, $totalExpenses) !== 0) {
            throw DomainException::because('Lifetime paid totals do not match expense total.');
        }

        if (Money::compare($responsibilitySum, $totalExpenses) !== 0) {
            throw DomainException::because('Lifetime responsibility totals do not match expense total.');
        }
    }

    /**
     * @param  Collection<int, UserBalance>  $balances
     */
    private function assertNetZero(Collection $balances): void
    {
        $balanceSum = '0.00';

        foreach ($balances as $balance) {
            $balanceSum = Money::add($balanceSum, $balance->balance);
        }

        if (Money::compare($balanceSum, '0.00') !== 0) {
            throw DomainException::because('Lifetime balances do not net to zero.');
        }
    }
}
