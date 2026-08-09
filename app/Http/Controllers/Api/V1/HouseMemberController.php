<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreHouseMemberRequest;
use App\Http\Resources\Api\V1\HouseMemberResource;
use App\Models\House;
use App\Models\User;
use App\Services\House\HouseMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HouseMemberController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly HouseMemberService $members) {}

    public function index(Request $request, House $house): JsonResponse
    {
        $this->authorize('view', $house);

        $members = $this->members->list($house, $request->user());

        return $this->ok(HouseMemberResource::collection($members));
    }

    public function store(StoreHouseMemberRequest $request, House $house): JsonResponse
    {
        $this->authorize('manageMembers', $house);

        $member = $this->members->add($house, $request->user(), $request->validated());

        return $this->created(new HouseMemberResource($member));
    }

    public function leave(Request $request, House $house, User $user): JsonResponse
    {
        // Leaving yourself is allowed for members; removing others requires owner (service enforces).
        if ($request->user()->id !== $user->id) {
            $this->authorize('manageMembers', $house);
        } else {
            $this->authorize('view', $house);
        }

        $member = $this->members->leave($house, $request->user(), $user);

        return $this->ok(new HouseMemberResource($member));
    }
}
