<?php

namespace App\Services\Expense;

use App\Enums\ExpenseStatus;
use App\Exceptions\DomainException;
use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\ExpenseCategory;
use App\Models\House;
use App\Models\User;
use App\Services\Allocation\AllocationEngine;
use App\Services\Allocation\AllocationRuleResolver;
use App\Services\Allocation\DTO\UserAllocationResult;
use App\Services\House\HouseAccessService;
use App\Services\Monthly\MonthLockService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(
        private readonly HouseAccessService $access,
        private readonly MonthLockService $monthLock,
        private readonly AllocationRuleResolver $ruleResolver,
        private readonly AllocationEngine $allocationEngine,
    ) {}

    /**
     * @return Collection<int, Expense>
     */
    public function list(House $house, User $actor, ?string $month = null, ?ExpenseStatus $status = null): Collection
    {
        $this->access->assertMember($house, $actor);

        $query = Expense::query()
            ->where('house_id', $house->id)
            ->with(['category', 'payer', 'allocations.user', 'allocationRule'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($month !== null) {
            if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
                throw ValidationException::withMessages([
                    'month' => ['Month must be YYYY-MM.'],
                ]);
            }

            $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $query->where(function ($builder) use ($start, $end) {
                $builder->where(function ($withPeriod) use ($start, $end) {
                    $withPeriod
                        ->whereNotNull('period_start_date')
                        ->whereDate('period_start_date', '<=', $end->toDateString())
                        ->whereDate('period_end_date', '>=', $start->toDateString());
                })->orWhere(function ($singleDay) use ($start, $end) {
                    $singleDay
                        ->whereNull('period_start_date')
                        ->whereDate('expense_date', '>=', $start->toDateString())
                        ->whereDate('expense_date', '<=', $end->toDateString());
                });
            });
        }

        return $query->get();
    }

    /**
     * @param  array{
     *     expense_category_id: int,
     *     paid_by?: int,
     *     title: string,
     *     description?: string|null,
     *     amount: float|int|string,
     *     expense_date: string,
     *     period_start_date?: string|null,
     *     period_end_date?: string|null
     * }  $data
     */
    public function create(House $house, User $actor, array $data): Expense
    {
        $this->access->assertMember($house, $actor);

        $normalized = $this->normalizePayload($house, $actor, $data);
        $this->monthLock->assertRangeIsEditable(
            $house,
            $normalized['period_start_date'] ?? $normalized['expense_date'],
            $normalized['period_end_date'] ?? $normalized['expense_date']
        );

        return Expense::query()->create([
            ...$normalized,
            'house_id' => $house->id,
            'status' => ExpenseStatus::Draft,
            'allocation_rule_id' => null,
            'created_by' => $actor->id,
            'confirmed_at' => null,
        ])->load(['category', 'payer']);
    }

    /**
     * @param  array{
     *     expense_category_id?: int,
     *     paid_by?: int,
     *     title?: string,
     *     description?: string|null,
     *     amount?: float|int|string,
     *     expense_date?: string,
     *     period_start_date?: string|null,
     *     period_end_date?: string|null
     * }  $data
     */
    public function update(Expense $expense, User $actor, array $data): Expense
    {
        $expense->loadMissing('house');
        $this->assertCanEdit($expense, $actor);

        if ($expense->status === ExpenseStatus::Cancelled) {
            throw DomainException::because('Cancelled expenses cannot be edited.');
        }

        $merged = [
            'expense_category_id' => $data['expense_category_id'] ?? $expense->expense_category_id,
            'paid_by' => $data['paid_by'] ?? $expense->paid_by,
            'title' => $data['title'] ?? $expense->title,
            'description' => array_key_exists('description', $data) ? $data['description'] : $expense->description,
            'amount' => $data['amount'] ?? $expense->amount,
            'expense_date' => $data['expense_date'] ?? $expense->expense_date->toDateString(),
            'period_start_date' => array_key_exists('period_start_date', $data)
                ? $data['period_start_date']
                : $expense->period_start_date?->toDateString(),
            'period_end_date' => array_key_exists('period_end_date', $data)
                ? $data['period_end_date']
                : $expense->period_end_date?->toDateString(),
        ];

        $normalized = $this->normalizePayload($expense->house, $actor, $merged);

        $this->monthLock->assertRangeIsEditable(
            $expense->house,
            $normalized['period_start_date'] ?? $normalized['expense_date'],
            $normalized['period_end_date'] ?? $normalized['expense_date']
        );

        return DB::transaction(function () use ($expense, $normalized) {
            $expense->fill($normalized);
            $expense->save();

            if ($expense->status === ExpenseStatus::Confirmed) {
                $this->recalculateAllocations($expense->fresh(['house', 'category']));
            }

            return $expense->fresh(['category', 'payer', 'allocations.user', 'allocationRule']);
        });
    }

    public function confirm(Expense $expense, User $actor): Expense
    {
        $expense->loadMissing(['house', 'category']);
        $this->assertCanEdit($expense, $actor);

        if ($expense->status === ExpenseStatus::Confirmed) {
            throw DomainException::because('Expense is already confirmed.');
        }

        if ($expense->status === ExpenseStatus::Cancelled) {
            throw DomainException::because('Cancelled expenses cannot be confirmed.');
        }

        $this->monthLock->assertRangeIsEditable(
            $expense->house,
            $expense->coverageStart(),
            $expense->coverageEnd()
        );

        return DB::transaction(function () use ($expense) {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $locked->load(['house', 'category']);

            $rule = $this->ruleResolver->resolveForExpense($locked);
            $allocations = $this->allocationEngine->allocateExpense($locked, $rule);

            $this->persistAllocations($locked, $rule->id, $rule->version, $allocations);

            $locked->status = ExpenseStatus::Confirmed;
            $locked->allocation_rule_id = $rule->id;
            $locked->confirmed_at = now();
            $locked->save();

            return $locked->fresh(['category', 'payer', 'allocations.user', 'allocationRule']);
        });
    }

    public function cancel(Expense $expense, User $actor): Expense
    {
        $expense->loadMissing('house');
        $this->access->assertOwner($expense->house, $actor);

        if ($expense->status === ExpenseStatus::Cancelled) {
            throw DomainException::because('Expense is already cancelled.');
        }

        $this->monthLock->assertRangeIsEditable(
            $expense->house,
            $expense->coverageStart(),
            $expense->coverageEnd()
        );

        return DB::transaction(function () use ($expense) {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            ExpenseAllocation::query()->where('expense_id', $locked->id)->delete();

            $locked->status = ExpenseStatus::Cancelled;
            $locked->allocation_rule_id = null;
            $locked->confirmed_at = null;
            $locked->save();

            return $locked->fresh(['category', 'payer']);
        });
    }

    /**
     * Undo a mistaken cancel by restoring the expense to draft.
     * Allocations are not restored; the expense must be confirmed again.
     */
    public function reinstate(Expense $expense, User $actor): Expense
    {
        $expense->loadMissing('house');
        $this->access->assertOwner($expense->house, $actor);

        if ($expense->status !== ExpenseStatus::Cancelled) {
            throw DomainException::because('Only cancelled expenses can be reinstated.');
        }

        $this->monthLock->assertRangeIsEditable(
            $expense->house,
            $expense->coverageStart(),
            $expense->coverageEnd()
        );

        return DB::transaction(function () use ($expense) {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            $locked->status = ExpenseStatus::Draft;
            $locked->allocation_rule_id = null;
            $locked->confirmed_at = null;
            $locked->save();

            return $locked->fresh(['category', 'payer', 'allocations.user', 'allocationRule']);
        });
    }

    /**
     * @return Collection<int, ExpenseAllocation>
     */
    public function allocations(Expense $expense, User $actor): Collection
    {
        $expense->loadMissing('house');
        $this->access->assertMember($expense->house, $actor);

        return $expense->allocations()->with('user')->orderBy('user_id')->get();
    }

    /**
     * @param  list<UserAllocationResult>  $allocations
     */
    private function persistAllocations(
        Expense $expense,
        int $ruleId,
        int $ruleVersion,
        array $allocations,
    ): void {
        ExpenseAllocation::query()->where('expense_id', $expense->id)->delete();

        foreach ($allocations as $row) {
            ExpenseAllocation::query()->create([
                'expense_id' => $expense->id,
                'user_id' => $row->userId,
                'amount' => $row->amount,
                'allocation_details' => [
                    'rule_id' => $ruleId,
                    'rule_version' => $ruleVersion,
                    'components' => $row->components,
                    'availability_days' => $row->availabilityDays,
                ],
            ]);
        }

        $expense->allocation_rule_id = $ruleId;
    }

    private function recalculateAllocations(Expense $expense): void
    {
        $rule = $this->ruleResolver->resolveForExpense($expense);
        $allocations = $this->allocationEngine->allocateExpense($expense, $rule);
        $this->persistAllocations($expense, $rule->id, $rule->version, $allocations);
        $expense->allocation_rule_id = $rule->id;
        $expense->save();
    }

    private function assertCanEdit(Expense $expense, User $actor): void
    {
        $isOwner = $this->access->isOwner($expense->house, $actor);
        $isPayer = $expense->paid_by === $actor->id || $expense->created_by === $actor->id;

        if ($expense->status === ExpenseStatus::Confirmed && ! $isOwner) {
            throw DomainException::because('Only the house owner can edit confirmed expenses.');
        }

        if ($expense->status === ExpenseStatus::Draft && ! $isOwner && ! $isPayer) {
            throw DomainException::because('You cannot edit this expense.');
        }

        if (! $this->access->isMember($expense->house, $actor)) {
            throw DomainException::because('You are not an active member of this house.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     expense_category_id: int,
     *     paid_by: int,
     *     title: string,
     *     description: string|null,
     *     amount: string,
     *     expense_date: string,
     *     period_start_date: string|null,
     *     period_end_date: string|null
     * }
     */
    private function normalizePayload(House $house, User $actor, array $data): array
    {
        $category = ExpenseCategory::query()
            ->where('house_id', $house->id)
            ->whereKey($data['expense_category_id'])
            ->first();

        if ($category === null) {
            throw ValidationException::withMessages([
                'expense_category_id' => ['Category does not belong to this house.'],
            ]);
        }

        if (! $category->is_active) {
            throw ValidationException::withMessages([
                'expense_category_id' => ['Category is inactive.'],
            ]);
        }

        $paidBy = (int) ($data['paid_by'] ?? $actor->id);

        if ($paidBy !== $actor->id && ! $this->access->isOwner($house, $actor)) {
            throw DomainException::because('Only the house owner can record expenses for another member.');
        }

        if (! $this->access->isMember($house, $paidBy, Carbon::parse($data['expense_date']))) {
            throw ValidationException::withMessages([
                'paid_by' => ['Payer must be an active house member on the expense date.'],
            ]);
        }

        $amount = Money::of($data['amount']);

        if (Money::compare($amount, '0.00') !== 1) {
            throw ValidationException::withMessages([
                'amount' => ['Amount must be greater than zero.'],
            ]);
        }

        $expenseDate = Carbon::parse($data['expense_date'])->toDateString();
        $periodStart = isset($data['period_start_date']) && $data['period_start_date'] !== null && $data['period_start_date'] !== ''
            ? Carbon::parse($data['period_start_date'])->toDateString()
            : null;
        $periodEnd = isset($data['period_end_date']) && $data['period_end_date'] !== null && $data['period_end_date'] !== ''
            ? Carbon::parse($data['period_end_date'])->toDateString()
            : null;

        if (($periodStart === null) xor ($periodEnd === null)) {
            throw ValidationException::withMessages([
                'period_end_date' => ['Provide both period_start_date and period_end_date, or neither.'],
            ]);
        }

        if ($periodStart !== null && $periodStart > $periodEnd) {
            throw ValidationException::withMessages([
                'period_end_date' => ['period_end_date must be on or after period_start_date.'],
            ]);
        }

        return [
            'expense_category_id' => $category->id,
            'paid_by' => $paidBy,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'amount' => $amount,
            'expense_date' => $expenseDate,
            'period_start_date' => $periodStart,
            'period_end_date' => $periodEnd,
        ];
    }
}
