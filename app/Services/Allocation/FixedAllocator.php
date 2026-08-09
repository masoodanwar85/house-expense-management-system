<?php

namespace App\Services\Allocation;

use App\Enums\FixedApplyTo;
use App\Exceptions\DomainException;
use App\Services\Allocation\DTO\AllocationContext;
use App\Support\Money;

class FixedAllocator implements AllocatorInterface
{
    public function allocate(string $amount, AllocationContext $context, array $configuration = []): array
    {
        $amount = Money::of($amount);
        $applyTo = FixedApplyTo::tryFrom($configuration['apply_to'] ?? '')
            ?? throw DomainException::because('Fixed allocation requires apply_to.');

        $participants = $this->participants($context, $applyTo);

        if ($participants === []) {
            throw DomainException::because('Fixed allocation has no eligible participants.');
        }

        $shares = Money::allocateEqually($amount, $participants);
        $result = array_fill_keys($context->memberUserIds, '0.00');

        foreach ($shares as $userId => $share) {
            $result[$userId] = $share;
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    private function participants(AllocationContext $context, FixedApplyTo $applyTo): array
    {
        return match ($applyTo) {
            FixedApplyTo::AllMembers => array_values($context->memberUserIds),
            FixedApplyTo::ActiveMembers => array_values(array_filter(
                $context->memberUserIds,
                fn (int $userId) => $context->daysFor($userId) > 0
            )),
            FixedApplyTo::FullPeriodMembers => array_values(array_filter(
                $context->memberUserIds,
                fn (int $userId) => $context->daysFor($userId) === $context->periodLengthDays
                    && $context->periodLengthDays > 0
            )),
        };
    }
}
