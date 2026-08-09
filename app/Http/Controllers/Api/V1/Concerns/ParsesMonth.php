<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Validation\ValidationException;

trait ParsesMonth
{
    /**
     * @return array{0: int, 1: int} [year, month]
     */
    protected function parseYearMonth(string $month): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $month, $matches)) {
            throw ValidationException::withMessages([
                'month' => ['Month must be in YYYY-MM format.'],
            ]);
        }

        $year = (int) $matches[1];
        $monthNumber = (int) $matches[2];

        if ($monthNumber < 1 || $monthNumber > 12) {
            throw ValidationException::withMessages([
                'month' => ['Month must be between 01 and 12.'],
            ]);
        }

        return [$year, $monthNumber];
    }
}
