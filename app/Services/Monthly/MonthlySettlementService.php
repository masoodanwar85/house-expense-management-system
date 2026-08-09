<?php

namespace App\Services\Monthly;

use App\Enums\ExpenseStatus;
use App\Enums\MonthlySettlementStatus;
use App\Exceptions\DomainException;
use App\Models\Expense;
use App\Models\House;
use App\Models\MonthlySettlement;
use App\Models\User;
use App\Services\Availability\AvailableDaysCalculator;
use App\Services\House\HouseAccessService;
use App\Services\House\HouseMemberService;
use App\Services\Monthly\DTO\MonthSettlementSummary;
use App\Services\Monthly\DTO\UserBalance;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonthlySettlementService
{
    public function __construct(
        private readonly HouseAccessService $access,
        private readonly HouseMemberService $memberService,
        private readonly AvailableDaysCalculator $daysCalculator,
    ) {}

    public function summarize(House $house, User $actor, int $year, int $month): MonthSettlementSummary
    {
        $this->access->assertMember($house, $actor);
        $this->assertValidMonth($year, $month);

        return $this->buildSummary($house, $year, $month);
    }

    public function close(House $house, User $actor, int $year, int $month): MonthSettlementSummary
    {
        $this->access->assertOwner($house, $actor);
        $this->assertValidMonth($year, $month);

        return DB::transaction(function () use ($house, $actor, $year, $month) {
            $record = MonthlySettlement::query()
                ->where('house_id', $house->id)
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($record?->status === MonthlySettlementStatus::Closed) {
                throw DomainException::because('Month is already closed.');
            }

            $this->assertNoDraftExpenses($house, $year, $month);

            $summary = $this->buildSummary($house, $year, $month);

            if ($record === null) {
                $record = new MonthlySettlement([
                    'house_id' => $house->id,
                    'year' => $year,
                    'month' => $month,
                ]);
            }

            $record->status = MonthlySettlementStatus::Closed;
            $record->total_expenses = $summary->totalExpenses;
            $record->closed_at = now();
            $record->closed_by = $actor->id;
            $record->save();

            return $this->buildSummary($house, $year, $month);
        });
    }

    public function reopen(House $house, User $actor, int $year, int $month): MonthSettlementSummary
    {
        $this->access->assertOwner($house, $actor);
        $this->assertValidMonth($year, $month);

        return DB::transaction(function () use ($house, $year, $month) {
            $record = MonthlySettlement::query()
                ->where('house_id', $house->id)
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($record === null || $record->status !== MonthlySettlementStatus::Closed) {
                throw DomainException::because('Month is not closed.');
            }

            $record->status = MonthlySettlementStatus::Open;
            $record->closed_at = null;
            $record->closed_by = null;
            $record->save();

            return $this->buildSummary($house, $year, $month);
        });
    }

    private function buildSummary(House $house, int $year, int $month): MonthSettlementSummary
    {
        [$monthStart, $monthEnd] = $this->monthBounds($year, $month);

        $expenses = $this->confirmedExpensesForMonth($house, $monthStart, $monthEnd);
        $members = $this->memberService->membersOverlapping($house, $monthStart, $monthEnd);

        $userIds = $members->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        foreach ($expenses as $expense) {
            $userIds[] = (int) $expense->paid_by;
            foreach ($expense->allocations as $allocation) {
                $userIds[] = (int) $allocation->user_id;
            }
        }

        $userIds = collect($userIds)->unique()->sort()->values();

        $daysByUser = $this->daysCalculator->daysForUsers($house, $userIds, $monthStart, $monthEnd);
        $membershipByUser = $members->keyBy('user_id');

        $paidByUser = [];
        $responsibilityByUser = [];

        foreach ($userIds as $userId) {
            $paidByUser[$userId] = '0.00';
            $responsibilityByUser[$userId] = '0.00';
        }

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

        $balances = $userIds->map(function (int $userId) use ($paidByUser, $responsibilityByUser, $daysByUser, $membershipByUser) {
            $paid = $paidByUser[$userId] ?? '0.00';
            $responsibility = $responsibilityByUser[$userId] ?? '0.00';

            return new UserBalance(
                userId: $userId,
                actualPaid: $paid,
                responsibility: $responsibility,
                balance: Money::sub($paid, $responsibility),
                availabilityDays: $daysByUser[$userId] ?? 0,
                role: $membershipByUser->get($userId)?->role?->value ?? 'former_member',
            );
        });

        $this->assertBalancesConserve($totalExpenses, $balances);

        $record = MonthlySettlement::query()
            ->where('house_id', $house->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $status = $record?->status ?? MonthlySettlementStatus::Open;

        return new MonthSettlementSummary(
            houseId: $house->id,
            year: $year,
            month: $month,
            monthStart: $monthStart->toDateString(),
            monthEnd: $monthEnd->toDateString(),
            status: $status,
            totalExpenses: $totalExpenses,
            balances: $balances,
            expenses: $expenses,
            record: $record,
        );
    }

    /**
     * @return Collection<int, Expense>
     */
    private function confirmedExpensesForMonth(House $house, Carbon $monthStart, Carbon $monthEnd): Collection
    {
        return Expense::query()
            ->where('house_id', $house->id)
            ->where('status', ExpenseStatus::Confirmed)
            ->whereDate('expense_date', '>=', $monthStart->toDateString())
            ->whereDate('expense_date', '<=', $monthEnd->toDateString())
            ->with(['allocations', 'payer', 'category'])
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get();
    }

    private function assertNoDraftExpenses(House $house, int $year, int $month): void
    {
        [$monthStart, $monthEnd] = $this->monthBounds($year, $month);

        $draftCount = Expense::query()
            ->where('house_id', $house->id)
            ->where('status', ExpenseStatus::Draft)
            ->where(function ($query) use ($monthStart, $monthEnd) {
                $query->where(function ($withPeriod) use ($monthStart, $monthEnd) {
                    $withPeriod
                        ->whereNotNull('period_start_date')
                        ->whereDate('period_start_date', '<=', $monthEnd->toDateString())
                        ->whereDate('period_end_date', '>=', $monthStart->toDateString());
                })->orWhere(function ($singleDay) use ($monthStart, $monthEnd) {
                    $singleDay
                        ->whereNull('period_start_date')
                        ->whereDate('expense_date', '>=', $monthStart->toDateString())
                        ->whereDate('expense_date', '<=', $monthEnd->toDateString());
                });
            })
            ->count();

        if ($draftCount > 0) {
            throw DomainException::because(
                'Cannot close month while draft expenses still overlap this month.'
            );
        }
    }

    /**
     * @param  Collection<int, UserBalance>  $balances
     */
    private function assertBalancesConserve(string $totalExpenses, Collection $balances): void
    {
        $paidSum = '0.00';
        $responsibilitySum = '0.00';
        $balanceSum = '0.00';

        foreach ($balances as $balance) {
            $paidSum = Money::add($paidSum, $balance->actualPaid);
            $responsibilitySum = Money::add($responsibilitySum, $balance->responsibility);
            $balanceSum = Money::add($balanceSum, $balance->balance);
        }

        if (Money::compare($paidSum, $totalExpenses) !== 0) {
            throw DomainException::because('Paid totals do not match monthly expense total.');
        }

        if (Money::compare($responsibilitySum, $totalExpenses) !== 0) {
            throw DomainException::because('Responsibility totals do not match monthly expense total.');
        }

        if (Money::compare($balanceSum, '0.00') !== 0) {
            throw DomainException::because('Monthly balances do not net to zero.');
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthBounds(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();

        return [$start, $end];
    }

    private function assertValidMonth(int $year, int $month): void
    {
        if ($month < 1 || $month > 12 || $year < 1970 || $year > 2100) {
            throw ValidationException::withMessages([
                'month' => ['Provide a valid year and month.'],
            ]);
        }
    }
}
