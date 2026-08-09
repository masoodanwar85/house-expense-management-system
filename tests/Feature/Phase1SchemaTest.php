<?php

namespace Tests\Feature;

use App\Enums\AllocationRuleType;
use App\Enums\AvailabilityStatus;
use App\Enums\ExpenseStatus;
use App\Enums\HouseMemberRole;
use App\Enums\MonthlySettlementStatus;
use App\Models\AllocationRule;
use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\ExpenseCategory;
use App\Models\House;
use App\Models\HouseMember;
use App\Models\MemberAvailabilityPeriod;
use App\Models\MonthlySettlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase1SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_relationships_persist(): void
    {
        $owner = User::factory()->create(['phone' => '03001234567']);
        $member = User::factory()->create();

        $house = House::factory()->create([
            'owner_id' => $owner->id,
            'currency' => 'PKR',
        ]);

        HouseMember::factory()->create([
            'house_id' => $house->id,
            'user_id' => $owner->id,
            'role' => HouseMemberRole::Owner,
        ]);

        HouseMember::factory()->create([
            'house_id' => $house->id,
            'user_id' => $member->id,
            'role' => HouseMemberRole::Member,
        ]);

        MemberAvailabilityPeriod::query()->create([
            'house_id' => $house->id,
            'user_id' => $member->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-20',
            'status' => AvailabilityStatus::Available,
            'created_by' => $owner->id,
        ]);

        $category = ExpenseCategory::query()->create([
            'house_id' => $house->id,
            'name' => 'Electricity',
            'code' => 'electricity',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $rule = AllocationRule::query()->create([
            'expense_category_id' => $category->id,
            'rule_type' => AllocationRuleType::Hybrid,
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 10, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 90],
                ],
            ],
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'version' => 1,
            'created_by' => $owner->id,
        ]);

        $expense = Expense::query()->create([
            'house_id' => $house->id,
            'expense_category_id' => $category->id,
            'paid_by' => $owner->id,
            'title' => 'August bill',
            'amount' => '20000.00',
            'expense_date' => '2026-08-30',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-31',
            'status' => ExpenseStatus::Draft,
            'allocation_rule_id' => null,
            'created_by' => $owner->id,
        ]);

        ExpenseAllocation::query()->create([
            'expense_id' => $expense->id,
            'user_id' => $member->id,
            'amount' => '2300.00',
            'allocation_details' => [
                'rule_version' => 1,
                'components' => ['fixed' => '500.00', 'per_day' => '1800.00'],
            ],
        ]);

        MonthlySettlement::query()->create([
            'house_id' => $house->id,
            'year' => 2026,
            'month' => 8,
            'status' => MonthlySettlementStatus::Open,
            'total_expenses' => '0.00',
        ]);

        $this->assertTrue($rule->coversPeriod('2026-08-01', '2026-08-31'));
        $this->assertEquals('2026-08-01', $expense->coverageStart()->toDateString());
        $this->assertEquals('2026-08-31', $expense->coverageEnd()->toDateString());
        $this->assertCount(2, $house->members);
        $this->assertCount(1, $house->categories);
        $this->assertCount(1, $category->allocationRules);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'phone' => '03001234567']);
    }
}
