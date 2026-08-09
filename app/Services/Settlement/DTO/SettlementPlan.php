<?php

namespace App\Services\Settlement\DTO;

use App\Services\Monthly\DTO\MonthSettlementSummary;
use App\Services\Monthly\DTO\UserBalance;
use Illuminate\Support\Collection;

final class SettlementPlan
{
    /**
     * @param  Collection<int, UserBalance>  $balances
     * @param  Collection<int, SettlementTransfer>  $transfers
     */
    public function __construct(
        public readonly int $houseId,
        public readonly int $year,
        public readonly int $month,
        public readonly string $totalExpenses,
        public readonly Collection $balances,
        public readonly Collection $transfers,
        public readonly ?MonthSettlementSummary $summary = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'house_id' => $this->houseId,
            'year' => $this->year,
            'month' => $this->month,
            'total_expenses' => $this->totalExpenses,
            'balances' => $this->balances->map(fn (UserBalance $b) => $b->toArray())->all(),
            'transfers' => $this->transfers->map(fn (SettlementTransfer $t) => $t->toArray())->all(),
        ];
    }
}
