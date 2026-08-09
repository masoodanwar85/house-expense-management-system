<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExpenseStatus;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExpenseRequest;
use App\Http\Requests\Api\V1\UpdateExpenseRequest;
use App\Http\Resources\Api\V1\ExpenseAllocationResource;
use App\Http\Resources\Api\V1\ExpenseResource;
use App\Models\Expense;
use App\Models\House;
use App\Services\Expense\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request, House $house): JsonResponse
    {
        $this->authorize('view', $house);

        $status = $request->filled('status')
            ? ExpenseStatus::tryFrom($request->string('status')->toString())
            : null;

        $expenses = $this->expenses->list(
            $house,
            $request->user(),
            $request->query('month'),
            $status,
        );

        return $this->ok(ExpenseResource::collection($expenses));
    }

    public function store(StoreExpenseRequest $request, House $house): JsonResponse
    {
        $this->authorize('createExpense', $house);

        $expense = $this->expenses->create($house, $request->user(), $request->validated());

        return $this->created(new ExpenseResource($expense));
    }

    public function show(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        $expense->load(['category', 'payer', 'allocationRule', 'allocations.user']);

        return $this->ok(new ExpenseResource($expense));
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);

        $expense = $this->expenses->update($expense, $request->user(), $request->validated());

        return $this->ok(new ExpenseResource($expense));
    }

    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('cancel', $expense);

        $expense = $this->expenses->cancel($expense, $request->user());

        return $this->ok(new ExpenseResource($expense));
    }

    public function confirm(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('confirm', $expense);

        $expense = $this->expenses->confirm($expense, $request->user());

        return $this->ok(new ExpenseResource($expense));
    }

    public function reinstate(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('reinstate', $expense);

        $expense = $this->expenses->reinstate($expense, $request->user());

        return $this->ok(new ExpenseResource($expense));
    }

    public function allocations(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('viewAllocations', $expense);

        $allocations = $this->expenses->allocations($expense, $request->user());

        return $this->ok(ExpenseAllocationResource::collection($allocations));
    }
}
