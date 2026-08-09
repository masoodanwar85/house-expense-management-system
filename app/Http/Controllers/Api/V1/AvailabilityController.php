<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAvailabilityRequest;
use App\Http\Resources\Api\V1\AvailabilityPeriodResource;
use App\Models\House;
use App\Models\User;
use App\Services\Availability\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly AvailabilityService $availability) {}

    public function index(Request $request, House $house, User $user): JsonResponse
    {
        $this->authorize('manageAvailability', $house);

        $periods = $this->availability->listForUser($house, $request->user(), $user->id);

        return $this->ok(AvailabilityPeriodResource::collection($periods));
    }

    public function store(StoreAvailabilityRequest $request, House $house, User $user): JsonResponse
    {
        $this->authorize('manageAvailability', $house);

        if ($request->user()->id !== $user->id) {
            $this->authorize('manageMembers', $house);
        }

        $period = $this->availability->create($house, $request->user(), [
            ...$request->validated(),
            'user_id' => $user->id,
        ]);

        return $this->created(new AvailabilityPeriodResource($period));
    }
}
