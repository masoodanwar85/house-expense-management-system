<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\HouseMemberRole;
use App\Models\HouseMember;
use App\Models\MemberAvailabilityPeriod;
use App\Models\User;
use App\Services\Allocation\AllocationRuleService;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\Expense\ExpenseService;
use App\Services\House\HouseService;
use App\Services\Settlement\OverallOwingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverallOwingTest extends TestCase
{
    use RefreshDatabase;

    public function test_overall_owing_aggregates_confirmed_expenses_across_months(): void
    {
        $setup = $this->seedTwoMemberHouse();
        $expenses = app(ExpenseService::class);

        $aug = $expenses->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'August bill',
            'amount' => '100.00',
            'expense_date' => '2026-08-15',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-31',
            'paid_by' => $setup['owner']->id,
        ]);
        $expenses->confirm($aug, $setup['owner']);

        $sep = $expenses->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'September bill',
            'amount' => '40.00',
            'expense_date' => '2026-09-10',
            'period_start_date' => '2026-09-01',
            'period_end_date' => '2026-09-30',
            'paid_by' => $setup['member']->id,
        ]);
        $expenses->confirm($sep, $setup['owner']);

        $plan = app(OverallOwingService::class)->forHouse($setup['house'], $setup['owner']);

        $this->assertSame('140.00', $plan->totalExpenses);

        $ownerBalance = $plan->balances->firstWhere('userId', $setup['owner']->id);
        $memberBalance = $plan->balances->firstWhere('userId', $setup['member']->id);

        // Owner paid 100, share 50+20=70 → net +30
        // Member paid 40, share 50+20=70 → net -30
        $this->assertSame('100.00', $ownerBalance->actualPaid);
        $this->assertSame('70.00', $ownerBalance->responsibility);
        $this->assertSame('30.00', $ownerBalance->balance);

        $this->assertSame('40.00', $memberBalance->actualPaid);
        $this->assertSame('70.00', $memberBalance->responsibility);
        $this->assertSame('-30.00', $memberBalance->balance);

        $this->assertCount(1, $plan->transfers);
        $this->assertSame($setup['member']->id, $plan->transfers->first()->fromUserId);
        $this->assertSame($setup['owner']->id, $plan->transfers->first()->toUserId);
        $this->assertSame('30.00', $plan->transfers->first()->amount);
    }

    public function test_overall_owing_nets_opposite_monthly_debts(): void
    {
        $setup = $this->seedTwoMemberHouse();
        $expenses = app(ExpenseService::class);

        $aug = $expenses->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'August',
            'amount' => '100.00',
            'expense_date' => '2026-08-15',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-31',
            'paid_by' => $setup['owner']->id,
        ]);
        $expenses->confirm($aug, $setup['owner']);

        $sep = $expenses->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['category']->id,
            'title' => 'September',
            'amount' => '100.00',
            'expense_date' => '2026-09-15',
            'period_start_date' => '2026-09-01',
            'period_end_date' => '2026-09-30',
            'paid_by' => $setup['member']->id,
        ]);
        $expenses->confirm($sep, $setup['owner']);

        $plan = app(OverallOwingService::class)->forHouse($setup['house'], $setup['owner']);

        $this->assertSame('200.00', $plan->totalExpenses);
        $this->assertTrue($plan->transfers->isEmpty());

        foreach ($plan->balances as $balance) {
            $this->assertSame('0.00', $balance->balance);
        }
    }

    /**
     * @return array{
     *     house: \App\Models\House,
     *     owner: User,
     *     member: User,
     *     category: \App\Models\ExpenseCategory
     * }
     */
    private function seedTwoMemberHouse(): array
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        $member = User::factory()->create(['name' => 'Member']);

        $house = app(HouseService::class)->create($owner, ['name' => 'Pair House', 'currency' => 'PKR']);
        MemberAvailabilityPeriod::query()->where('house_id', $house->id)->delete();

        HouseMember::factory()->create([
            'house_id' => $house->id,
            'user_id' => $member->id,
            'role' => HouseMemberRole::Member,
            'joined_at' => '2026-08-01 00:00:00',
        ]);

        HouseMember::query()
            ->where('house_id', $house->id)
            ->where('user_id', $owner->id)
            ->update(['joined_at' => '2026-08-01 00:00:00']);

        foreach ([$owner->id, $member->id] as $userId) {
            MemberAvailabilityPeriod::factory()->create([
                'house_id' => $house->id,
                'user_id' => $userId,
                'start_date' => '2026-08-01',
                'end_date' => '2026-09-30',
                'status' => AvailabilityStatus::Available,
                'created_by' => $owner->id,
            ]);
        }

        $category = app(ExpenseCategoryService::class)->create($house, $owner, [
            'name' => 'Shared',
            'code' => 'shared',
        ]);

        app(AllocationRuleService::class)->create($category, $owner, [
            'rule_type' => 'fixed',
            'configuration' => ['apply_to' => 'all_members'],
            'effective_from' => '2026-01-01',
        ]);

        return [
            'house' => $house->fresh(),
            'owner' => $owner,
            'member' => $member,
            'category' => $category,
        ];
    }
}
