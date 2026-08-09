<?php

namespace App\Services\Monthly;

use App\Enums\MonthlySettlementStatus;
use App\Exceptions\DomainException;
use App\Models\House;
use App\Models\MonthlySettlement;
use Illuminate\Support\Carbon;

class MonthLockService
{
    public function assertRangeIsEditable(House $house, Carbon|string $from, Carbon|string $to): void
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            if ($this->isClosed($house, $cursor->year, $cursor->month)) {
                throw DomainException::because(
                    sprintf('Month %04d-%02d is closed and cannot be modified.', $cursor->year, $cursor->month)
                );
            }

            $cursor->addMonth();
        }
    }

    public function isClosed(House $house, int $year, int $month): bool
    {
        return MonthlySettlement::query()
            ->where('house_id', $house->id)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', MonthlySettlementStatus::Closed)
            ->exists();
    }

    public function ensureOpenRecord(House $house, int $year, int $month): MonthlySettlement
    {
        return MonthlySettlement::query()->firstOrCreate(
            [
                'house_id' => $house->id,
                'year' => $year,
                'month' => $month,
            ],
            [
                'status' => MonthlySettlementStatus::Open,
                'total_expenses' => '0.00',
            ]
        );
    }
}
