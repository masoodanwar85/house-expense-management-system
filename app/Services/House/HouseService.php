<?php

namespace App\Services\House;

use App\Enums\AvailabilityStatus;
use App\Enums\HouseMemberRole;
use App\Models\House;
use App\Models\HouseMember;
use App\Models\MemberAvailabilityPeriod;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HouseService
{
    public function __construct(
        private readonly HouseAccessService $access,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, currency?: string, timezone?: string}  $data
     */
    public function create(User $owner, array $data): House
    {
        return DB::transaction(function () use ($owner, $data) {
            $house = House::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'owner_id' => $owner->id,
                'currency' => $data['currency'] ?? 'PKR',
                'timezone' => $data['timezone'] ?? 'Asia/Karachi',
            ]);

            HouseMember::query()->create([
                'house_id' => $house->id,
                'user_id' => $owner->id,
                'role' => HouseMemberRole::Owner,
                'joined_at' => now(),
                'left_at' => null,
            ]);

            MemberAvailabilityPeriod::query()->create([
                'house_id' => $house->id,
                'user_id' => $owner->id,
                'start_date' => now()->toDateString(),
                'end_date' => null,
                'status' => AvailabilityStatus::Available,
                'created_by' => $owner->id,
            ]);

            return $house->load(['owner', 'members.user']);
        });
    }

    /**
     * @param  array{name?: string, description?: string|null, currency?: string, timezone?: string}  $data
     */
    public function update(House $house, User $actor, array $data): House
    {
        $this->access->assertOwner($house, $actor);

        if (array_key_exists('name', $data)) {
            $house->name = $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $house->description = $data['description'];
        }

        if (array_key_exists('currency', $data)) {
            $house->currency = $data['currency'];
        }

        if (array_key_exists('timezone', $data)) {
            $house->timezone = $data['timezone'];
        }

        $house->save();

        return $house->refresh()->load(['owner', 'members.user']);
    }

    /**
     * @return Collection<int, House>
     */
    public function listForUser(User $user): Collection
    {
        return House::query()
            ->where(function ($query) use ($user) {
                $query->where('owner_id', $user->id)
                    ->orWhereHas('members', function ($members) use ($user) {
                        $members->where('user_id', $user->id)->whereNull('left_at');
                    });
            })
            ->with(['owner', 'members.user'])
            ->orderBy('name')
            ->get();
    }

    public function get(House $house, User $actor): House
    {
        $this->access->assertMember($house, $actor);

        return $house->load(['owner', 'members.user']);
    }
}
