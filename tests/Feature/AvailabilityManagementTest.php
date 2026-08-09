<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\HouseMemberRole;
use App\Enums\MonthlySettlementStatus;
use App\Exceptions\DomainException;
use App\Models\House;
use App\Models\HouseMember;
use App\Models\MemberAvailabilityPeriod;
use App\Models\MonthlySettlement;
use App\Models\User;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\AvailableDaysCalculator;
use App\Services\House\HouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AvailabilityManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_month_availability_days(): void
    {
        [$house, $user] = $this->houseWithMember();

        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $user->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => AvailabilityStatus::Available,
            'created_by' => $user->id,
        ]);

        $days = app(AvailableDaysCalculator::class)->daysForUser($house, $user->id, '2026-08-01', '2026-08-31');
        $this->assertEquals(31, $days);
    }

    public function test_partial_month_join_and_leave(): void
    {
        [$house, $user] = $this->houseWithMember();

        // Join mid-month: Aug 11-31 = 21 days
        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $user->id,
            'start_date' => '2026-08-11',
            'end_date' => '2026-08-31',
            'status' => AvailabilityStatus::Available,
            'created_by' => $user->id,
        ]);

        $this->assertEquals(
            21,
            app(AvailableDaysCalculator::class)->daysForUser($house, $user->id, '2026-08-01', '2026-08-31')
        );

        // Separate user leaves mid-month: Aug 1-20 = 20 days
        $leaver = User::factory()->create();
        HouseMember::factory()->create([
            'house_id' => $house->id,
            'user_id' => $leaver->id,
            'role' => HouseMemberRole::Member,
            'joined_at' => '2026-08-01',
        ]);

        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $leaver->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-20',
            'status' => AvailabilityStatus::Available,
            'created_by' => $leaver->id,
        ]);

        $this->assertEquals(
            20,
            app(AvailableDaysCalculator::class)->daysForUser($house, $leaver->id, '2026-08-01', '2026-08-31')
        );
    }

    public function test_zero_availability_days(): void
    {
        [$house, $user] = $this->houseWithMember();

        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $user->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => AvailabilityStatus::Unavailable,
            'created_by' => $user->id,
        ]);

        $this->assertEquals(
            0,
            app(AvailableDaysCalculator::class)->daysForUser($house, $user->id, '2026-08-01', '2026-08-31')
        );
    }

    public function test_multiple_availability_periods_are_summed(): void
    {
        [$house, $user] = $this->houseWithMember();

        // 1-10 available (10), 11-20 unavailable, 21-31 available (11) => 21
        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $user->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-10',
            'status' => AvailabilityStatus::Available,
            'created_by' => $user->id,
        ]);

        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $user->id,
            'start_date' => '2026-08-11',
            'end_date' => '2026-08-20',
            'status' => AvailabilityStatus::Unavailable,
            'created_by' => $user->id,
        ]);

        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $user->id,
            'start_date' => '2026-08-21',
            'end_date' => '2026-08-31',
            'status' => AvailabilityStatus::Available,
            'created_by' => $user->id,
        ]);

        $this->assertEquals(
            21,
            app(AvailableDaysCalculator::class)->daysForUser($house, $user->id, '2026-08-01', '2026-08-31')
        );
    }

    public function test_overlapping_availability_is_rejected(): void
    {
        $owner = User::factory()->create();
        $house = app(HouseService::class)->create($owner, ['name' => 'Home']);

        // HouseService already created open-ended available period from today.
        // Create a clean house with controlled periods instead.
        $house = House::factory()->create(['owner_id' => $owner->id]);
        HouseMember::factory()->create([
            'house_id' => $house->id,
            'user_id' => $owner->id,
            'role' => HouseMemberRole::Owner,
            'joined_at' => '2026-08-01',
        ]);

        app(AvailabilityService::class)->create($house, $owner, [
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-15',
            'status' => 'available',
        ]);

        $this->expectException(ValidationException::class);

        app(AvailabilityService::class)->create($house, $owner, [
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-20',
            'status' => 'unavailable',
        ]);
    }

    public function test_inclusive_date_boundaries(): void
    {
        [$house, $user] = $this->houseWithMember();

        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $user->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-20',
            'status' => AvailabilityStatus::Available,
            'created_by' => $user->id,
        ]);

        $this->assertEquals(20, app(AvailableDaysCalculator::class)->daysForUser(
            $house,
            $user->id,
            '2026-08-01',
            '2026-08-20'
        ));

        $this->assertEquals(11, app(AvailableDaysCalculator::class)->periodLength('2026-08-21', '2026-08-31'));
    }

    public function test_non_member_cannot_have_availability(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $house = House::factory()->create(['owner_id' => $owner->id]);

        HouseMember::factory()->create([
            'house_id' => $house->id,
            'user_id' => $owner->id,
            'role' => HouseMemberRole::Owner,
            'joined_at' => '2026-08-01',
        ]);

        $this->expectException(DomainException::class);

        app(AvailabilityService::class)->create($house, $owner, [
            'user_id' => $outsider->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-10',
        ]);
    }

    public function test_closed_month_blocks_availability_changes(): void
    {
        $owner = User::factory()->create();
        $house = House::factory()->create(['owner_id' => $owner->id]);

        HouseMember::factory()->create([
            'house_id' => $house->id,
            'user_id' => $owner->id,
            'role' => HouseMemberRole::Owner,
            'joined_at' => '2026-08-01',
        ]);

        MonthlySettlement::query()->create([
            'house_id' => $house->id,
            'year' => 2026,
            'month' => 8,
            'status' => MonthlySettlementStatus::Closed,
            'total_expenses' => '0.00',
            'closed_at' => now(),
            'closed_by' => $owner->id,
        ]);

        $this->expectException(DomainException::class);

        app(AvailabilityService::class)->create($house, $owner, [
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-10',
        ]);
    }

    public function test_full_period_availability_check(): void
    {
        [$house, $user] = $this->houseWithMember();

        MemberAvailabilityPeriod::factory()->create([
            'house_id' => $house->id,
            'user_id' => $user->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => AvailabilityStatus::Available,
            'created_by' => $user->id,
        ]);

        $calc = app(AvailableDaysCalculator::class);

        $this->assertTrue($calc->isAvailableFullPeriod($house, $user->id, '2026-08-01', '2026-08-31'));
        $this->assertFalse($calc->isAvailableFullPeriod($house, $user->id, '2026-07-01', '2026-08-31'));
    }

    /**
     * @return array{0: House, 1: User}
     */
    private function houseWithMember(): array
    {
        $user = User::factory()->create();
        $house = House::factory()->create(['owner_id' => $user->id]);

        HouseMember::factory()->create([
            'house_id' => $house->id,
            'user_id' => $user->id,
            'role' => HouseMemberRole::Owner,
            'joined_at' => '2026-08-01 00:00:00',
            'left_at' => null,
        ]);

        return [$house, $user];
    }
}
