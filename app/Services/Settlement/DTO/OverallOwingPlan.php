<?php

namespace App\Services\Settlement\DTO;

use App\Services\Monthly\DTO\UserBalance;
use Illuminate\Support\Collection;

/**
 * Lifetime house settlement: all confirmed expenses across months.
 *
 * @phpstan-type BalanceList Collection<int, UserBalance>
 * @phpstan-type TransferList Collection<int, SettlementTransfer>
 */
final class OverallOwingPlan
{
    /**
     * @param  Collection<int, UserBalance>  $balances
     * @param  Collection<int, SettlementTransfer>  $transfers
     */
    public function __construct(
        public readonly int $houseId,
        public readonly string $totalExpenses,
        public readonly Collection $balances,
        public readonly Collection $transfers,
    ) {}

    /**
     * @return array{
     *     house_id: int,
     *     total_expenses: string,
     *     balances: list<array<string, mixed>>,
     *     transfers: list<array{from_user_id: int, to_user_id: int, amount: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'house_id' => $this->houseId,
            'total_expenses' => $this->totalExpenses,
            'balances' => $this->balances->map(fn (UserBalance $b) => $b->toArray())->values()->all(),
            'transfers' => $this->transfers->map(fn (SettlementTransfer $t) => $t->toArray())->values()->all(),
        ];
    }
}
