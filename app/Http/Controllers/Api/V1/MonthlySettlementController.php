<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ParsesMonth;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Models\House;
use App\Services\Monthly\MonthlySettlementService;
use App\Services\Settlement\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonthlySettlementController extends Controller
{
    use ParsesMonth;
    use RespondsWithApi;

    public function __construct(
        private readonly MonthlySettlementService $monthly,
        private readonly SettlementService $settlements,
    ) {}

    public function show(Request $request, House $house, string $month): JsonResponse
    {
        $this->authorize('viewSettlement', $house);

        [$year, $monthNumber] = $this->parseYearMonth($month);
        $summary = $this->monthly->summarize($house, $request->user(), $year, $monthNumber);
        $plan = $this->settlements->forMonth($house, $request->user(), $year, $monthNumber);

        return $this->ok([
            ...$summary->toArray(),
            'transfers' => $plan->transfers->map->toArray()->all(),
        ]);
    }

    public function close(Request $request, House $house, string $month): JsonResponse
    {
        $this->authorize('closeMonth', $house);

        [$year, $monthNumber] = $this->parseYearMonth($month);
        $summary = $this->monthly->close($house, $request->user(), $year, $monthNumber);

        return $this->ok($summary->toArray());
    }

    public function reopen(Request $request, House $house, string $month): JsonResponse
    {
        $this->authorize('reopenMonth', $house);

        [$year, $monthNumber] = $this->parseYearMonth($month);
        $summary = $this->monthly->reopen($house, $request->user(), $year, $monthNumber);

        return $this->ok($summary->toArray());
    }
}
