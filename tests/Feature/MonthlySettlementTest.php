<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\ExpenseStatus;
use App\Enums\HouseMemberRole;
use App\Enums\MonthlySettlementStatus;
use App\Exceptions\DomainException;
use App\Models\HouseMember;
use App\Models\MemberAvailabilityPeriod;
use App\Models\User;
use App\Services\Allocation\AllocationRuleService;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\Expense\ExpenseService;
use App\Services\House\HouseService;
use App\Services\Monthly\MonthlySettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlySettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_balances_use_stored_allocations(): void
    {
        $setup = $this->seedAugustHouse();
        $service = app(MonthlySettlementService::class);

        $summary = $service->summarize($setup['house'], $setup['owner'], 2026, 8);
        $byUser = $summary->balances->keyBy('userId');

        $this->assertEquals('20000.00', $summary->totalExpenses);
        $this->assertEquals(MonthlySettlementStatus::Open, $summary->status);

        // Owner A paid the bill.
        $this->assertEquals('20000.00', $byUser[$setup['users']['A']->id]->actualPaid);
        $this->assertEquals('2300.00', $byUser[$setup['users']['A']->id]->responsibility);
        $this->assertEquals('17700.00', $byUser[$setup['users']['A']->id]->balance);
        $this->assertEquals(10, $byUser[$setup['users']['A']->id]->availabilityDays);

        foreach (['B', 'C', 'D'] as $key) {
            $this->assertEquals('0.00', $byUser[$setup['users'][$key]->id]->actualPaid);
            $this->assertEquals('5900.00', $byUser[$setup['users'][$key]->id]->responsibility);
            $this->assertEquals('-5900.00', $byUser[$setup['users'][$key]->id]->balance);
            $this->assertEquals(30, $byUser[$setup['users'][$key]->id]->availabilityDays);
        }

        $this->assertCount(1, $summary->creditors());
        $this->assertCount(3, $summary->debtors());
    }

    public function test_multiple_expenses_aggregate_correctly(): void
    {
        $setup = $this->seedAugustHouse(confirmElectricity: false);
        $expenseService = app(ExpenseService::class);

        // Water: 100% per_day, paid by B.
        $water = app(ExpenseCategoryService::class)->create($setup['house'], $setup['owner'], [
            'name' => 'Water',
            'code' => 'water',
        ]);

        app(AllocationRuleService::class)->create($water, $setup['owner'], [
            'rule_type' => 'per_day',
            'configuration' => [],
            'effective_from' => '2026-01-01',
        ]);

        $electricity = $expenseService->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'Electricity',
            'amount' => '20000.00',
            'expense_date' => '2026-08-30',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
            'paid_by' => $setup['owner']->id,
        ]);
        $expenseService->confirm($electricity, $setup['owner']);

        $waterExpense = $expenseService->create($setup['house'], $setup['users']['B'], [
            'expense_category_id' => $water->id,
            'title' => 'Water',
            'amount' => '10000.00',
            'expense_date' => '2026-08-28',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
            'paid_by' => $setup['users']['B']->id,
        ]);
        $expenseService->confirm($waterExpense, $setup['owner']);

        $summary = app(MonthlySettlementService::class)->summarize($setup['house'], $setup['owner'], 2026, 8);
        $byUser = $summary->balances->keyBy('userId');

        $this->assertEquals('30000.00', $summary->totalExpenses);

        // Electricity: A 2300 / B,C,D 5900; Water: A 1000 / B,C,D 3000
        $this->assertEquals('20000.00', $byUser[$setup['users']['A']->id]->actualPaid);
        $this->assertEquals('3300.00', $byUser[$setup['users']['A']->id]->responsibility);
        $this->assertEquals('16700.00', $byUser[$setup['users']['A']->id]->balance);

        $this->assertEquals('10000.00', $byUser[$setup['users']['B']->id]->actualPaid);
        $this->assertEquals('8900.00', $byUser[$setup['users']['B']->id]->responsibility);
        $this->assertEquals('1100.00', $byUser[$setup['users']['B']->id]->balance);

        $this->assertEquals('-8900.00', $byUser[$setup['users']['C']->id]->balance);
        $this->assertEquals('-8900.00', $byUser[$setup['users']['D']->id]->balance);
    }

    public function test_draft_and_other_month_expenses_are_excluded(): void
    {
        $setup = $this->seedAugustHouse();
        $expenseService = app(ExpenseService::class);

        $draft = $expenseService->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'Draft bill',
            'amount' => '5000.00',
            'expense_date' => '2026-08-20',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-20',
        ]);

        $this->assertEquals(ExpenseStatus::Draft, $draft->status);

        // Need July availability for the out-of-month confirmed expense.
        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $setup['house']->id,
            'user_id' => $setup['owner']->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'status' => AvailabilityStatus::Available,
            'created_by' => $setup['owner']->id,
        ]);

        // July confirmed expense should not affect August.
        $july = $expenseService->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'July bill',
            'amount' => '1000.00',
            'expense_date' => '2026-07-15',
            'period_start_date' => '2026-07-01',
            'period_end_date' => '2026-07-15',
        ]);
        $expenseService->confirm($july, $setup['owner']);

        $summary = app(MonthlySettlementService::class)->summarize($setup['house'], $setup['owner'], 2026, 8);

        $this->assertEquals('20000.00', $summary->totalExpenses);
        $this->assertCount(1, $summary->expenses);
    }

    public function test_close_persists_totals_and_rejects_double_close(): void
    {
        $setup = $this->seedAugustHouse();
        $service = app(MonthlySettlementService::class);

        $closed = $service->close($setup['house'], $setup['owner'], 2026, 8);

        $this->assertEquals(MonthlySettlementStatus::Closed, $closed->status);
        $this->assertEquals('20000.00', $closed->totalExpenses);
        $this->assertNotNull($closed->record?->closed_at);
        $this->assertEquals($setup['owner']->id, $closed->record?->closed_by);
        $this->assertTrue(app(\App\Services\Monthly\MonthLockService::class)->isClosed($setup['house'], 2026, 8));

        $this->expectException(DomainException::class);
        $service->close($setup['house'], $setup['owner'], 2026, 8);
    }

    public function test_reopen_allows_expense_edits_again(): void
    {
        $setup = $this->seedAugustHouse();
        $monthly = app(MonthlySettlementService::class);
        $expenses = app(ExpenseService::class);

        $monthly->close($setup['house'], $setup['owner'], 2026, 8);

        $expense = $setup['expense'];

        try {
            $expenses->update($expense->fresh(), $setup['owner'], ['amount' => '15000.00']);
            $this->fail('Expected closed month to block updates.');
        } catch (DomainException) {
            // expected
        }

        $reopened = $monthly->reopen($setup['house'], $setup['owner'], 2026, 8);
        $this->assertEquals(MonthlySettlementStatus::Open, $reopened->status);
        $this->assertNull($reopened->record?->closed_at);

        $updated = $expenses->update($expense->fresh(), $setup['owner'], ['amount' => '15000.00']);
        $this->assertEquals('15000.00', (string) $updated->amount);

        $summary = $monthly->summarize($setup['house'], $setup['owner'], 2026, 8);
        $this->assertEquals('15000.00', $summary->totalExpenses);
    }

    public function test_cannot_close_with_overlapping_drafts(): void
    {
        $setup = $this->seedAugustHouse();

        app(ExpenseService::class)->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'Unconfirmed',
            'amount' => '100.00',
            'expense_date' => '2026-08-10',
        ]);

        $this->expectException(DomainException::class);

        app(MonthlySettlementService::class)->close($setup['house'], $setup['owner'], 2026, 8);
    }

    public function test_spanning_expense_settles_by_expense_date_month(): void
    {
        $setup = $this->seedAugustHouse(confirmElectricity: false);
        $expenseService = app(ExpenseService::class);

        // Coverage touches August + September; expense_date is September.
        $expense = $expenseService->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'Cross-month bill',
            'amount' => '20000.00',
            'expense_date' => '2026-09-05',
            'period_start_date' => '2026-08-20',
            'period_end_date' => '2026-09-10',
            'paid_by' => $setup['owner']->id,
        ]);
        $expenseService->confirm($expense, $setup['owner']);

        $monthly = app(MonthlySettlementService::class);

        $august = $monthly->summarize($setup['house'], $setup['owner'], 2026, 8);
        $september = $monthly->summarize($setup['house'], $setup['owner'], 2026, 9);

        $this->assertEquals('0.00', $august->totalExpenses);
        $this->assertCount(0, $august->expenses);
        $this->assertEquals('20000.00', $september->totalExpenses);
        $this->assertCount(1, $september->expenses);
    }

    /**
     * @return array{
     *     owner: User,
     *     house: \App\Models\House,
     *     category: \App\Models\ExpenseCategory,
     *     expense: \App\Models\Expense|null,
     *     users: array{A: User, B: User, C: User, D: User}
     * }
     */
    private function seedAugustHouse(bool $confirmElectricity = true): array
    {
        $a = User::factory()->create(['name' => 'A']);
        $b = User::factory()->create(['name' => 'B']);
        $c = User::factory()->create(['name' => 'C']);
        $d = User::factory()->create(['name' => 'D']);

        $house = app(HouseService::class)->create($a, ['name' => 'Family House']);
        MemberAvailabilityPeriod::query()->where('house_id', $house->id)->delete();

        foreach ([$b, $c, $d] as $user) {
            HouseMember::factory()->create([
                'house_id' => $house->id,
                'user_id' => $user->id,
                'role' => HouseMemberRole::Member,
                'joined_at' => '2026-07-01 00:00:00',
            ]);
        }

        HouseMember::query()
            ->where('house_id', $house->id)
            ->where('user_id', $a->id)
            ->update(['joined_at' => '2026-07-01 00:00:00']);

        $days = [
            $a->id => ['2026-08-01', '2026-08-10'],
            $b->id => ['2026-08-01', '2026-08-30'],
            $c->id => ['2026-08-01', '2026-08-30'],
            $d->id => ['2026-08-01', '2026-08-30'],
        ];

        foreach ($days as $userId => [$start, $end]) {
            MemberAvailabilityPeriod::factory()->create([
                'house_id' => $house->id,
                'user_id' => $userId,
                'start_date' => $start,
                'end_date' => $end,
                'status' => AvailabilityStatus::Available,
                'created_by' => $a->id,
            ]);
        }

        // September presence for spanning-expense test / membership continuity.
        foreach ([$a, $b, $c, $d] as $user) {
            MemberAvailabilityPeriod::factory()->create([
                'house_id' => $house->id,
                'user_id' => $user->id,
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-30',
                'status' => AvailabilityStatus::Available,
                'created_by' => $a->id,
            ]);
        }

        $category = app(ExpenseCategoryService::class)->create($house, $a, [
            'name' => 'Electricity',
            'code' => 'electricity',
        ]);

        app(AllocationRuleService::class)->create($category, $a, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 10, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 90],
                ],
            ],
            'effective_from' => '2026-01-01',
        ]);

        $expense = null;

        if ($confirmElectricity) {
            $expense = app(ExpenseService::class)->create($house, $a, [
                'expense_category_id' => $category->id,
                'title' => 'August Electricity',
                'amount' => '20000.00',
                'expense_date' => '2026-08-30',
                'period_start_date' => '2026-08-01',
                'period_end_date' => '2026-08-30',
                'paid_by' => $a->id,
            ]);
            $expense = app(ExpenseService::class)->confirm($expense, $a);
        }

        return [
            'owner' => $a,
            'house' => $house->fresh(),
            'category' => $category,
            'expense' => $expense,
            'users' => ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d],
        ];
    }
}
