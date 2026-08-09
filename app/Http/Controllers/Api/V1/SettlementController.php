<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ParsesMonth;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Models\House;
use App\Services\Settlement\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettlementController extends Controller
{
    use ParsesMonth;
    use RespondsWithApi;

    public function __construct(private readonly SettlementService $settlements) {}

    public function show(Request $request, House $house): JsonResponse
    {
        $this->authorize('viewSettlement', $house);

        $month = $request->query('month');

        if (! is_string($month) || $month === '') {
            throw ValidationException::withMessages([
                'month' => ['The month query parameter is required (YYYY-MM).'],
            ]);
        }

        [$year, $monthNumber] = $this->parseYearMonth($month);
        $plan = $this->settlements->forMonth($house, $request->user(), $year, $monthNumber);

        return $this->ok($plan->toArray());
    }
}
