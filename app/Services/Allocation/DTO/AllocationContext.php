<?php

namespace App\Services\Allocation\DTO;

/**
 * Immutable inputs for allocation math (no Eloquent dependency).
 */
final class AllocationContext
{
    /**
     * @param  list<int>  $memberUserIds
     * @param  array<int, int>  $availableDaysByUser
     */
    public function __construct(
        public readonly string $amount,
        public readonly array $memberUserIds,
        public readonly array $availableDaysByUser,
        public readonly int $periodLengthDays,
    ) {}

    public function daysFor(int $userId): int
    {
        return (int) ($this->availableDaysByUser[$userId] ?? 0);
    }

    public function totalPersonDays(): int
    {
        $total = 0;

        foreach ($this->memberUserIds as $userId) {
            $total += $this->daysFor($userId);
        }

        return $total;
    }
}
