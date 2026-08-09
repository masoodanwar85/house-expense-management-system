<?php

namespace App\Services\Settlement\DTO;

final class SettlementTransfer
{
    public function __construct(
        public readonly int $fromUserId,
        public readonly int $toUserId,
        public readonly string $amount,
    ) {}

    /**
     * @return array{from_user_id: int, to_user_id: int, amount: string}
     */
    public function toArray(): array
    {
        return [
            'from_user_id' => $this->fromUserId,
            'to_user_id' => $this->toUserId,
            'amount' => $this->amount,
        ];
    }
}
