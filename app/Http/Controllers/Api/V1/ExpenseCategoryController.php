<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExpenseCategoryRequest;
use App\Http\Requests\Api\V1\UpdateExpenseCategoryRequest;
use App\Http\Resources\Api\V1\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use App\Models\House;
use App\Services\Expense\ExpenseCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly ExpenseCategoryService $categories) {}

    public function index(Request $request, House $house): JsonResponse
    {
        $this->authorize('view', $house);

        $activeOnly = $request->boolean('active_only');
        $categories = $this->categories->list($house, $request->user(), $activeOnly);

        return $this->ok(ExpenseCategoryResource::collection($categories));
    }

    public function store(StoreExpenseCategoryRequest $request, House $house): JsonResponse
    {
        $this->authorize('manageCategories', $house);

        $category = $this->categories->create($house, $request->user(), $request->validated());

        return $this->created(new ExpenseCategoryResource($category));
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $category): JsonResponse
    {
        $this->authorize('update', $category);

        $category = $this->categories->update($category, $request->user(), $request->validated());

        return $this->ok(new ExpenseCategoryResource($category));
    }
}
