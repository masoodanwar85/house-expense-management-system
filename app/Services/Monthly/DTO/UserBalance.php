<?php

namespace App\Services\Monthly\DTO;

final class UserBalance
{
    public function __construct(
        public readonly int $userId,
        public readonly string $actualPaid,
        public readonly string $responsibility,
        public readonly string $balance,
        public readonly int $availabilityDays,
        public readonly string $role,
    ) {}

    public function isCreditor(): bool
    {
        return bccomp($this->balance, '0', 2) === 1;
    }

    public function isDebtor(): bool
    {
        return bccomp($this->balance, '0', 2) === -1;
    }

    public function isSettled(): bool
    {
        return bccomp($this->balance, '0', 2) === 0;
    }

    /**
     * @return array{
     *     user_id: int,
     *     actual_paid: string,
     *     responsibility: string,
     *     balance: string,
     *     availability_days: int,
     *     role: string
     * }
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'actual_paid' => $this->actualPaid,
            'responsibility' => $this->responsibility,
            'balance' => $this->balance,
            'availability_days' => $this->availabilityDays,
            'role' => $this->role,
        ];
    }
}
