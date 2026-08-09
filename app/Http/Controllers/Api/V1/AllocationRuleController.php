<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAllocationRuleRequest;
use App\Http\Requests\Api\V1\StoreAllocationRuleVersionRequest;
use App\Http\Resources\Api\V1\AllocationRuleResource;
use App\Models\ExpenseCategory;
use App\Services\Allocation\AllocationRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllocationRuleController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly AllocationRuleService $rules) {}

    public function index(Request $request, ExpenseCategory $category): JsonResponse
    {
        $this->authorize('view', $category);

        $rules = $this->rules->listForCategory($category, $request->user());

        return $this->ok(AllocationRuleResource::collection($rules));
    }

    public function store(StoreAllocationRuleRequest $request, ExpenseCategory $category): JsonResponse
    {
        $this->authorize('manageRules', $category);

        $rule = $this->rules->create($category, $request->user(), $request->validated());

        return $this->created(new AllocationRuleResource($rule));
    }

    public function storeVersion(StoreAllocationRuleVersionRequest $request, ExpenseCategory $category): JsonResponse
    {
        $this->authorize('manageRules', $category);

        $rule = $this->rules->createVersion($category, $request->user(), $request->validated());

        return $this->created(new AllocationRuleResource($rule));
    }
}
