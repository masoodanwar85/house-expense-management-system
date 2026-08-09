<?php

namespace App\Services\House;

use App\Enums\HouseMemberRole;
use App\Exceptions\DomainException;
use App\Models\House;
use App\Models\HouseMember;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class HouseMemberService
{
    public function __construct(
        private readonly HouseAccessService $access,
    ) {}

    /**
     * @return Collection<int, HouseMember>
     */
    public function list(House $house, User $actor): Collection
    {
        $this->access->assertMember($house, $actor);

        return HouseMember::query()
            ->where('house_id', $house->id)
            ->with('user')
            ->orderBy('joined_at')
            ->get();
    }

    /**
     * @param  array{user_id: int, role?: string, joined_at?: string}  $data
     */
    public function add(House $house, User $actor, array $data): HouseMember
    {
        $this->access->assertOwner($house, $actor);

        $user = User::query()->findOrFail($data['user_id']);
        $joinedAt = isset($data['joined_at'])
            ? Carbon::parse($data['joined_at'])
            : now();

        $role = HouseMemberRole::tryFrom($data['role'] ?? HouseMemberRole::Member->value)
            ?? HouseMemberRole::Member;

        if ($role === HouseMemberRole::Owner) {
            throw DomainException::because('Use house ownership transfer to assign the owner role.');
        }

        if ($this->access->isMember($house, $user, $joinedAt)) {
            throw DomainException::because('User is already an active member of this house.');
        }

        return HouseMember::query()->create([
            'house_id' => $house->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => $joinedAt,
            'left_at' => null,
        ])->load('user');
    }

    public function leave(House $house, User $actor, User $target, ?Carbon $leftAt = null): HouseMember
    {
        $leftAt ??= now();

        if ($actor->id !== $target->id) {
            $this->access->assertOwner($house, $actor);
        } else {
            $this->access->assertMember($house, $actor);
        }

        if ($house->owner_id === $target->id) {
            throw DomainException::because('House owner cannot leave without transferring ownership.');
        }

        $membership = $this->access->membership($house, $target, $leftAt);

        if ($membership === null) {
            throw DomainException::because('User is not an active member of this house.');
        }

        $membership->left_at = $leftAt;
        $membership->save();

        return $membership->refresh()->load('user');
    }

    /**
     * Members whose membership overlaps an inclusive date range.
     *
     * @return Collection<int, HouseMember>
     */
    public function membersOverlapping(House $house, Carbon|string $from, Carbon|string $to): Collection
    {
        return HouseMember::query()
            ->where('house_id', $house->id)
            ->overlappingPeriod($from, $to)
            ->with('user')
            ->orderBy('user_id')
            ->get();
    }
}
