<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\ExpenseStatus;
use App\Enums\HouseMemberRole;
use App\Enums\MonthlySettlementStatus;
use App\Exceptions\DomainException;
use App\Models\ExpenseAllocation;
use App\Models\HouseMember;
use App\Models\MemberAvailabilityPeriod;
use App\Models\MonthlySettlement;
use App\Models\User;
use App\Services\Allocation\AllocationRuleService;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\Expense\ExpenseService;
use App\Services\House\HouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirming_expense_persists_allocations_and_rule_version(): void
    {
        $setup = $this->seedHouseWithHybridElectricity();

        $expense = app(ExpenseService::class)->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'August Electricity',
            'amount' => '20000.00',
            'expense_date' => '2026-08-30',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
            'paid_by' => $setup['owner']->id,
        ]);

        $this->assertEquals(ExpenseStatus::Draft, $expense->status);

        $confirmed = app(ExpenseService::class)->confirm($expense, $setup['owner']);

        $this->assertEquals(ExpenseStatus::Confirmed, $confirmed->status);
        $this->assertNotNull($confirmed->allocation_rule_id);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertDatabaseCount('expense_allocations', 4);

        $byUser = $confirmed->allocations->keyBy('user_id');

        $this->assertEquals('2300.00', (string) $byUser[$setup['users']['A']->id]->amount);
        $this->assertEquals('5900.00', (string) $byUser[$setup['users']['B']->id]->amount);
        $this->assertEquals('5900.00', (string) $byUser[$setup['users']['C']->id]->amount);
        $this->assertEquals('5900.00', (string) $byUser[$setup['users']['D']->id]->amount);

        $details = $byUser[$setup['users']['A']->id]->allocation_details;
        $this->assertEquals(1, $details['rule_version']);
        $this->assertEquals('500.00', $details['components']['fixed']);
        $this->assertEquals('1800.00', $details['components']['per_day']);
        $this->assertEquals(10, $details['availability_days']);

        $sum = $confirmed->allocations->sum(fn ($row) => (float) $row->amount);
        $this->assertEquals(20000.0, round($sum, 2));
    }

    public function test_confirmed_expense_keeps_historical_rule_after_version_change(): void
    {
        $setup = $this->seedHouseWithHybridElectricity();
        $service = app(ExpenseService::class);

        $expense = $service->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'Early August bill',
            'amount' => '20000.00',
            'expense_date' => '2026-08-10',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-10',
            'paid_by' => $setup['owner']->id,
        ]);

        $confirmed = $service->confirm($expense, $setup['owner']);
        $originalRuleId = $confirmed->allocation_rule_id;
        $originalAmountA = (string) $confirmed->allocations->firstWhere('user_id', $setup['users']['A']->id)->amount;

        app(AllocationRuleService::class)->createVersion($setup['category'], $setup['owner'], [
            'rule_type' => 'hybrid',
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 20, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 80],
                ],
            ],
            'effective_from' => '2026-08-16',
        ]);

        $confirmed->refresh()->load('allocations');

        $this->assertEquals($originalRuleId, $confirmed->allocation_rule_id);
        $this->assertEquals(
            $originalAmountA,
            (string) $confirmed->allocations->firstWhere('user_id', $setup['users']['A']->id)->amount
        );
    }

    public function test_updating_confirmed_expense_recalculates_allocations(): void
    {
        $setup = $this->seedHouseWithHybridElectricity();
        $service = app(ExpenseService::class);

        $expense = $service->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'Electricity',
            'amount' => '20000.00',
            'expense_date' => '2026-08-30',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
        ]);

        $service->confirm($expense, $setup['owner']);

        $updated = $service->update($expense->fresh(), $setup['owner'], [
            'amount' => '10000.00',
        ]);

        $this->assertEquals(ExpenseStatus::Confirmed, $updated->status);
        $this->assertEquals('10000.00', (string) $updated->amount);

        $sum = $updated->allocations->sum(fn ($row) => (float) $row->amount);
        $this->assertEquals(10000.0, round($sum, 2));
        $this->assertCount(4, $updated->allocations);
    }

    public function test_cancel_removes_allocations(): void
    {
        $setup = $this->seedHouseWithHybridElectricity();
        $service = app(ExpenseService::class);

        $expense = $service->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'Electricity',
            'amount' => '20000.00',
            'expense_date' => '2026-08-30',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
        ]);

        $service->confirm($expense, $setup['owner']);
        $this->assertDatabaseCount('expense_allocations', 4);

        $cancelled = $service->cancel($expense->fresh(), $setup['owner']);

        $this->assertEquals(ExpenseStatus::Cancelled, $cancelled->status);
        $this->assertDatabaseCount('expense_allocations', 0);
    }

    public function test_reinstate_restores_cancelled_expense_to_draft(): void
    {
        $setup = $this->seedHouseWithHybridElectricity();
        $service = app(ExpenseService::class);

        $expense = $service->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'Electricity',
            'amount' => '20000.00',
            'expense_date' => '2026-08-30',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
        ]);

        $service->confirm($expense, $setup['owner']);
        $service->cancel($expense->fresh(), $setup['owner']);

        $reinstated = $service->reinstate($expense->fresh(), $setup['owner']);

        $this->assertEquals(ExpenseStatus::Draft, $reinstated->status);
        $this->assertNull($reinstated->allocation_rule_id);
        $this->assertNull($reinstated->confirmed_at);
        $this->assertDatabaseCount('expense_allocations', 0);

        $confirmedAgain = $service->confirm($reinstated, $setup['owner']);
        $this->assertEquals(ExpenseStatus::Confirmed, $confirmedAgain->status);
        $this->assertDatabaseCount('expense_allocations', 4);
    }

    public function test_closed_month_blocks_confirmation(): void
    {
        $setup = $this->seedHouseWithHybridElectricity();
        $service = app(ExpenseService::class);

        $expense = $service->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'Electricity',
            'amount' => '20000.00',
            'expense_date' => '2026-08-30',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
        ]);

        MonthlySettlement::query()->create([
            'house_id' => $setup['house']->id,
            'year' => 2026,
            'month' => 8,
            'status' => MonthlySettlementStatus::Closed,
            'total_expenses' => '0.00',
            'closed_at' => now(),
            'closed_by' => $setup['owner']->id,
        ]);

        $this->expectException(DomainException::class);

        $service->confirm($expense, $setup['owner']);
    }

    public function test_single_day_expense_without_period_uses_expense_date(): void
    {
        $setup = $this->seedHouseWithHybridElectricity();

        // Ensure availability covers Aug 15.
        $expense = app(ExpenseService::class)->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'One-day charge',
            'amount' => '100.00',
            'expense_date' => '2026-08-15',
        ]);

        $confirmed = app(ExpenseService::class)->confirm($expense, $setup['owner']);

        $this->assertEquals('2026-08-15', $confirmed->coverageStart()->toDateString());
        $this->assertEquals('2026-08-15', $confirmed->coverageEnd()->toDateString());
        $this->assertGreaterThan(0, ExpenseAllocation::query()->where('expense_id', $confirmed->id)->count());
    }

    public function test_non_owner_cannot_edit_confirmed_expense(): void
    {
        $setup = $this->seedHouseWithHybridElectricity();
        $service = app(ExpenseService::class);

        $expense = $service->create($setup['house'], $setup['users']['B'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'Electricity',
            'amount' => '20000.00',
            'expense_date' => '2026-08-30',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
        ]);

        $service->confirm($expense, $setup['owner']);

        $this->expectException(DomainException::class);

        $service->update($expense->fresh(), $setup['users']['B'], [
            'amount' => '15000.00',
        ]);
    }

    /**
     * @return array{
     *     owner: User,
     *     house: \App\Models\House,
     *     category: \App\Models\ExpenseCategory,
     *     users: array{A: User, B: User, C: User, D: User}
     * }
     */
    private function seedHouseWithHybridElectricity(): array
    {
        $a = User::factory()->create(['name' => 'A']);
        $b = User::factory()->create(['name' => 'B']);
        $c = User::factory()->create(['name' => 'C']);
        $d = User::factory()->create(['name' => 'D']);

        $house = app(HouseService::class)->create($a, ['name' => 'Family House']);

        // Replace auto-created open availability with controlled August periods.
        MemberAvailabilityPeriod::query()->where('house_id', $house->id)->delete();

        foreach ([$b, $c, $d] as $user) {
            HouseMember::factory()->create([
                'house_id' => $house->id,
                'user_id' => $user->id,
                'role' => HouseMemberRole::Member,
                'joined_at' => '2026-08-01 00:00:00',
            ]);
        }

        HouseMember::query()
            ->where('house_id', $house->id)
            ->where('user_id', $a->id)
            ->update(['joined_at' => '2026-08-01 00:00:00']);

        $days = [
            $a->id => ['2026-08-01', '2026-08-10'], // 10 days
            $b->id => ['2026-08-01', '2026-08-30'], // 30
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

        return [
            'owner' => $a,
            'house' => $house->fresh(),
            'category' => $category,
            'users' => ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d],
        ];
    }
}
