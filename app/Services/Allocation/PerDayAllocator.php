<?php

namespace App\Services\Allocation;

use App\Exceptions\DomainException;
use App\Services\Allocation\DTO\AllocationContext;
use App\Support\Money;

class PerDayAllocator implements AllocatorInterface
{
    public function allocate(string $amount, AllocationContext $context, array $configuration = []): array
    {
        $amount = Money::of($amount);
        $weights = [];

        foreach ($context->memberUserIds as $userId) {
            $weights[$userId] = $context->daysFor($userId);
        }

        $totalDays = array_sum($weights);

        if ($totalDays <= 0) {
            if (Money::compare($amount, '0.00') === 0) {
                return array_fill_keys($context->memberUserIds, '0.00');
            }

            throw DomainException::because('Cannot allocate per-day amount: total person-days is zero.');
        }

        $shares = Money::allocateByWeights($amount, $weights);

        // Ensure every member key exists.
        foreach ($context->memberUserIds as $userId) {
            $shares[$userId] = $shares[$userId] ?? '0.00';
        }

        return $shares;
    }
}
