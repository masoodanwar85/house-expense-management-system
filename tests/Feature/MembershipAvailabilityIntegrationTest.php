<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\HouseMemberRole;
use App\Models\HouseMember;
use App\Models\MemberAvailabilityPeriod;
use App\Models\User;
use App\Services\Allocation\AllocationRuleService;
use App\Services\Availability\AvailableDaysCalculator;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\Expense\ExpenseService;
use App\Services\House\HouseMemberService;
use App\Services\House\HouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipAvailabilityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unavailable_periods_do_not_count_as_available_days(): void
    {
        $owner = User::factory()->create();
        $house = app(HouseService::class)->create($owner, ['name' => 'House']);
        MemberAvailabilityPeriod::query()->where('house_id', $house->id)->delete();

        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $owner->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-10',
            'status' => AvailabilityStatus::Available,
            'created_by' => $owner->id,
        ]);

        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $owner->id,
            'start_date' => '2026-08-11',
            'end_date' => '2026-08-20',
            'status' => AvailabilityStatus::Unavailable,
            'created_by' => $owner->id,
        ]);

        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $owner->id,
            'start_date' => '2026-08-21',
            'end_date' => '2026-08-31',
            'status' => AvailabilityStatus::Available,
            'created_by' => $owner->id,
        ]);

        // Spec example: 1-10 available + 21-31 available = 21 days.
        $this->assertEquals(
            21,
            app(AvailableDaysCalculator::class)->daysForUser($house, $owner->id, '2026-08-01', '2026-08-31')
        );
    }

    public function test_member_who_left_is_excluded_from_later_expense_allocations(): void
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        $leaver = User::factory()->create(['name' => 'Leaver']);
        $stayer = User::factory()->create(['name' => 'Stayer']);

        $house = app(HouseService::class)->create($owner, ['name' => 'House']);
        MemberAvailabilityPeriod::query()->where('house_id', $house->id)->delete();

        foreach ([$leaver, $stayer] as $user) {
            HouseMember::factory()->create([
                'house_id' => $house->id,
                'user_id' => $user->id,
                'role' => HouseMemberRole::Member,
                'joined_at' => '2026-08-01 00:00:00',
            ]);
        }

        HouseMember::query()
            ->where('house_id', $house->id)
            ->where('user_id', $owner->id)
            ->update(['joined_at' => '2026-08-01 00:00:00']);

        foreach ([$owner, $leaver, $stayer] as $user) {
            MemberAvailabilityPeriod::factory()->create([
                'house_id' => $house->id,
                'user_id' => $user->id,
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-31',
                'status' => AvailabilityStatus::Available,
                'created_by' => $owner->id,
            ]);
        }

        app(HouseMemberService::class)->leave(
            $house,
            $owner,
            $leaver,
            \Illuminate\Support\Carbon::parse('2026-08-10 23:59:59')
        );

        $category = app(ExpenseCategoryService::class)->create($house, $owner, [
            'name' => 'Water',
            'code' => 'water',
        ]);
        app(AllocationRuleService::class)->create($category, $owner, [
            'rule_type' => 'fixed',
            'configuration' => ['apply_to' => 'all_members'],
            'effective_from' => '2026-01-01',
        ]);

        // Expense after leave date — leaver membership does not overlap Aug 15-31.
        $expense = app(ExpenseService::class)->create($house, $owner, [
            'expense_category_id' => $category->id,
            'title' => 'Late August water',
            'amount' => '3000.00',
            'expense_date' => '2026-08-31',
            'period_start_date' => '2026-08-15',
            'period_end_date' => '2026-08-31',
            'paid_by' => $owner->id,
        ]);
        $confirmed = app(ExpenseService::class)->confirm($expense, $owner);

        $userIds = $confirmed->allocations->pluck('user_id')->all();
        $this->assertContains($owner->id, $userIds);
        $this->assertContains($stayer->id, $userIds);
        $this->assertNotContains($leaver->id, $userIds);

        foreach ($confirmed->allocations as $row) {
            $this->assertEquals('1500.00', (string) $row->amount);
        }
    }

    public function test_join_mid_month_member_is_included_only_when_membership_overlaps(): void
    {
        $owner = User::factory()->create();
        $joiner = User::factory()->create();

        $house = app(HouseService::class)->create($owner, ['name' => 'House']);
        MemberAvailabilityPeriod::query()->where('house_id', $house->id)->delete();

        HouseMember::query()
            ->where('house_id', $house->id)
            ->where('user_id', $owner->id)
            ->update(['joined_at' => '2026-08-01 00:00:00']);

        HouseMember::factory()->create([
            'house_id' => $house->id,
            'user_id' => $joiner->id,
            'role' => HouseMemberRole::Member,
            'joined_at' => '2026-08-20 00:00:00',
        ]);

        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $owner->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => AvailabilityStatus::Available,
            'created_by' => $owner->id,
        ]);

        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $joiner->id,
            'start_date' => '2026-08-20',
            'end_date' => '2026-08-31',
            'status' => AvailabilityStatus::Available,
            'created_by' => $owner->id,
        ]);

        $category = app(ExpenseCategoryService::class)->create($house, $owner, [
            'name' => 'Internet',
            'code' => 'internet',
        ]);
        app(AllocationRuleService::class)->create($category, $owner, [
            'rule_type' => 'fixed',
            'configuration' => ['apply_to' => 'all_members'],
            'effective_from' => '2026-01-01',
        ]);

        // Early August expense — joiner not yet a member.
        $early = app(ExpenseService::class)->create($house, $owner, [
            'expense_category_id' => $category->id,
            'title' => 'Early internet',
            'amount' => '1000.00',
            'expense_date' => '2026-08-10',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-10',
        ]);
        $early = app(ExpenseService::class)->confirm($early, $owner);

        $this->assertCount(1, $early->allocations);
        $this->assertEquals($owner->id, $early->allocations->first()->user_id);
        $this->assertEquals('1000.00', (string) $early->allocations->first()->amount);

        // Late August expense — both members overlap.
        $late = app(ExpenseService::class)->create($house, $owner, [
            'expense_category_id' => $category->id,
            'title' => 'Late internet',
            'amount' => '2000.00',
            'expense_date' => '2026-08-31',
            'period_start_date' => '2026-08-20',
            'period_end_date' => '2026-08-31',
        ]);
        $late = app(ExpenseService::class)->confirm($late, $owner);

        $this->assertCount(2, $late->allocations);
        $this->assertEqualsCanonicalizing(
            [$owner->id, $joiner->id],
            $late->allocations->pluck('user_id')->all()
        );
    }
}
