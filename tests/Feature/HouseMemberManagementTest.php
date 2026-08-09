<?php

namespace Tests\Feature;

use App\Enums\HouseMemberRole;
use App\Exceptions\DomainException;
use App\Models\House;
use App\Models\HouseMember;
use App\Models\User;
use App\Services\House\HouseMemberService;
use App\Services\House\HouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HouseMemberManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_house_adds_owner_membership_and_availability(): void
    {
        $owner = User::factory()->create();

        $house = app(HouseService::class)->create($owner, [
            'name' => 'Family House',
            'description' => 'Shared home',
        ]);

        $this->assertDatabaseHas('houses', [
            'id' => $house->id,
            'owner_id' => $owner->id,
            'currency' => 'PKR',
        ]);

        $this->assertDatabaseHas('house_members', [
            'house_id' => $house->id,
            'user_id' => $owner->id,
            'role' => HouseMemberRole::Owner->value,
        ]);

        $this->assertDatabaseHas('member_availability_periods', [
            'house_id' => $house->id,
            'user_id' => $owner->id,
            'status' => 'available',
        ]);
    }

    public function test_owner_can_add_and_remove_members(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $house = app(HouseService::class)->create($owner, ['name' => 'Villa']);

        $membership = app(HouseMemberService::class)->add($house, $owner, [
            'user_id' => $member->id,
        ]);

        $this->assertEquals(HouseMemberRole::Member, $membership->role);
        $this->assertNull($membership->left_at);

        $left = app(HouseMemberService::class)->leave($house, $owner, $member);
        $this->assertNotNull($left->left_at);
    }

    public function test_cannot_add_duplicate_active_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $house = app(HouseService::class)->create($owner, ['name' => 'Villa']);

        app(HouseMemberService::class)->add($house, $owner, ['user_id' => $member->id]);

        $this->expectException(DomainException::class);

        app(HouseMemberService::class)->add($house, $owner, ['user_id' => $member->id]);
    }

    public function test_owner_cannot_leave_house(): void
    {
        $owner = User::factory()->create();
        $house = app(HouseService::class)->create($owner, ['name' => 'Villa']);

        $this->expectException(DomainException::class);

        app(HouseMemberService::class)->leave($house, $owner, $owner);
    }

    public function test_non_owner_cannot_add_members(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $house = app(HouseService::class)->create($owner, ['name' => 'Villa']);

        app(HouseMemberService::class)->add($house, $owner, ['user_id' => $member->id]);

        $this->expectException(DomainException::class);

        app(HouseMemberService::class)->add($house, $member, ['user_id' => $outsider->id]);
    }

    public function test_members_overlapping_respects_left_at(): void
    {
        $owner = User::factory()->create();
        $leaver = User::factory()->create();
        $house = House::factory()->create(['owner_id' => $owner->id]);

        HouseMember::factory()->create([
            'house_id' => $house->id,
            'user_id' => $owner->id,
            'role' => HouseMemberRole::Owner,
            'joined_at' => '2026-08-01 00:00:00',
            'left_at' => null,
        ]);

        HouseMember::factory()->create([
            'house_id' => $house->id,
            'user_id' => $leaver->id,
            'role' => HouseMemberRole::Member,
            'joined_at' => '2026-08-01 00:00:00',
            'left_at' => '2026-08-15 00:00:00',
        ]);

        $members = app(HouseMemberService::class)->membersOverlapping($house, '2026-08-01', '2026-08-31');
        $this->assertCount(2, $members);

        $late = app(HouseMemberService::class)->membersOverlapping($house, '2026-08-20', '2026-08-31');
        $this->assertCount(1, $late);
        $this->assertEquals($owner->id, $late->first()->user_id);
    }
}
