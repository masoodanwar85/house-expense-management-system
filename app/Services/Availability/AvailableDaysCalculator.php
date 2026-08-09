<?php

namespace App\Services\Availability;

use App\Models\House;
use App\Models\MemberAvailabilityPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calculates inclusive available days for a user within a date range.
 * Assumes non-overlapping periods (enforced by AvailabilityService).
 */
class AvailableDaysCalculator
{
    public function daysForUser(House $house, int $userId, Carbon|string $from, Carbon|string $to): int
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($from->greaterThan($to)) {
            return 0;
        }

        return (int) MemberAvailabilityPeriod::query()
            ->where('house_id', $house->id)
            ->where('user_id', $userId)
            ->available()
            ->overlapping($from, $to)
            ->get()
            ->sum(fn (MemberAvailabilityPeriod $period) => $period->overlapDays($from, $to));
    }

    /**
     * @param  Collection<int, int>  $userIds
     * @return array<int, int> user_id => days
     */
    public function daysForUsers(House $house, Collection|array $userIds, Carbon|string $from, Carbon|string $to): array
    {
        $result = [];

        foreach ($userIds as $userId) {
            $result[(int) $userId] = $this->daysForUser($house, (int) $userId, $from, $to);
        }

        return $result;
    }

    /**
     * True when the user is available on every calendar day in [from, to].
     */
    public function isAvailableFullPeriod(House $house, int $userId, Carbon|string $from, Carbon|string $to): bool
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        $expected = (int) $from->diffInDays($to) + 1;

        return $this->daysForUser($house, $userId, $from, $to) === $expected;
    }

    public function periodLength(Carbon|string $from, Carbon|string $to): int
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($from->greaterThan($to)) {
            return 0;
        }

        return (int) $from->diffInDays($to) + 1;
    }
}
