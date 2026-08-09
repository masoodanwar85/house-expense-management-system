<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreHouseRequest;
use App\Http\Requests\Api\V1\UpdateHouseRequest;
use App\Http\Resources\Api\V1\HouseResource;
use App\Models\House;
use App\Services\House\HouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HouseController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly HouseService $houses) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', House::class);

        $houses = $this->houses->listForUser($request->user());

        return $this->ok(HouseResource::collection($houses));
    }

    public function store(StoreHouseRequest $request): JsonResponse
    {
        $this->authorize('create', House::class);

        $house = $this->houses->create($request->user(), $request->validated());

        return $this->created(new HouseResource($house));
    }

    public function show(Request $request, House $house): JsonResponse
    {
        $this->authorize('view', $house);

        $house = $this->houses->get($house, $request->user());

        return $this->ok(new HouseResource($house));
    }

    public function update(UpdateHouseRequest $request, House $house): JsonResponse
    {
        $this->authorize('update', $house);

        $house = $this->houses->update($house, $request->user(), $request->validated());

        return $this->ok(new HouseResource($house));
    }
}
