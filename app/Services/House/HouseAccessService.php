<?php

namespace App\Services\House;

use App\Enums\HouseMemberRole;
use App\Exceptions\DomainException;
use App\Models\House;
use App\Models\HouseMember;
use App\Models\User;
use Illuminate\Support\Carbon;

class HouseAccessService
{
    public function membership(House $house, User|int $user, ?Carbon $at = null): ?HouseMember
    {
        $userId = $user instanceof User ? $user->id : $user;
        $at ??= now();

        return HouseMember::query()
            ->where('house_id', $house->id)
            ->where('user_id', $userId)
            ->overlappingPeriod($at, $at)
            ->latest('joined_at')
            ->first();
    }

    public function isMember(House $house, User|int $user, ?Carbon $at = null): bool
    {
        return $this->membership($house, $user, $at) !== null;
    }

    public function isOwner(House $house, User|int $user, ?Carbon $at = null): bool
    {
        $membership = $this->membership($house, $user, $at);

        return $membership?->role === HouseMemberRole::Owner
            || $house->owner_id === ($user instanceof User ? $user->id : $user);
    }

    public function assertMember(House $house, User $user, ?Carbon $at = null): HouseMember
    {
        $membership = $this->membership($house, $user, $at);

        if ($membership === null) {
            throw DomainException::because('You are not an active member of this house.');
        }

        return $membership;
    }

    public function assertOwner(House $house, User $user, ?Carbon $at = null): void
    {
        if (! $this->isOwner($house, $user, $at)) {
            throw DomainException::because('Only the house owner can perform this action.');
        }
    }
}
