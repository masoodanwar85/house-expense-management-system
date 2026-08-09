<?php

namespace App\Services\Monthly\DTO;

use App\Enums\MonthlySettlementStatus;
use App\Models\MonthlySettlement;
use Illuminate\Support\Collection;

final class MonthSettlementSummary
{
    /**
     * @param  Collection<int, UserBalance>  $balances
     * @param  Collection<int, \App\Models\Expense>  $expenses
     */
    public function __construct(
        public readonly int $houseId,
        public readonly int $year,
        public readonly int $month,
        public readonly string $monthStart,
        public readonly string $monthEnd,
        public readonly MonthlySettlementStatus $status,
        public readonly string $totalExpenses,
        public readonly Collection $balances,
        public readonly Collection $expenses,
        public readonly ?MonthlySettlement $record,
    ) {}

    /**
     * @return Collection<int, UserBalance>
     */
    public function creditors(): Collection
    {
        return $this->balances->filter(fn (UserBalance $b) => $b->isCreditor())->values();
    }

    /**
     * @return Collection<int, UserBalance>
     */
    public function debtors(): Collection
    {
        return $this->balances->filter(fn (UserBalance $b) => $b->isDebtor())->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'house_id' => $this->houseId,
            'year' => $this->year,
            'month' => $this->month,
            'month_start' => $this->monthStart,
            'month_end' => $this->monthEnd,
            'status' => $this->status->value,
            'total_expenses' => $this->totalExpenses,
            'balances' => $this->balances->map(fn (UserBalance $b) => $b->toArray())->all(),
            'expense_ids' => $this->expenses->pluck('id')->all(),
            'monthly_settlement_id' => $this->record?->id,
        ];
    }
}
