<?php

namespace App\Services\Allocation\DTO;

final class UserAllocationResult
{
    /**
     * @param  array<string, string>  $components  e.g. ['fixed' => '500.00', 'per_day' => '1800.00']
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $amount,
        public readonly array $components,
        public readonly int $availabilityDays,
    ) {}

    /**
     * @return array{user_id: int, amount: string, components: array<string, string>, availability_days: int}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'amount' => $this->amount,
            'components' => $this->components,
            'availability_days' => $this->availabilityDays,
        ];
    }
}
