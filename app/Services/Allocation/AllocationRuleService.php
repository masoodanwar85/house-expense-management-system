<?php

namespace App\Services\Allocation;

use App\Enums\AllocationRuleType;
use App\Exceptions\DomainException;
use App\Models\AllocationRule;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\House\HouseAccessService;
use App\Services\Monthly\MonthLockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AllocationRuleService
{
    public function __construct(
        private readonly HouseAccessService $access,
        private readonly AllocationRuleConfigValidator $configValidator,
        private readonly MonthLockService $monthLock,
    ) {}

    /**
     * @return Collection<int, AllocationRule>
     */
    public function listForCategory(ExpenseCategory $category, User $actor): Collection
    {
        $this->access->assertMember($category->house, $actor);

        return AllocationRule::query()
            ->where('expense_category_id', $category->id)
            ->orderBy('version')
            ->get();
    }

    /**
     * Create the first rule version for a category (or an explicitly dated initial version).
     *
     * @param  array{
     *     rule_type: string,
     *     configuration?: array<string, mixed>,
     *     effective_from: string,
     *     effective_to?: string|null
     * }  $data
     */
    public function create(ExpenseCategory $category, User $actor, array $data): AllocationRule
    {
        $this->access->assertOwner($category->house, $actor);

        $ruleType = AllocationRuleType::tryFrom($data['rule_type'])
            ?? throw ValidationException::withMessages([
                'rule_type' => ['Invalid rule_type. Allowed: per_day, fixed, hybrid.'],
            ]);

        $configuration = $this->configValidator->validate($ruleType, $data['configuration'] ?? []);
        $effectiveFrom = Carbon::parse($data['effective_from'])->startOfDay();
        $effectiveTo = isset($data['effective_to']) && $data['effective_to'] !== null && $data['effective_to'] !== ''
            ? Carbon::parse($data['effective_to'])->startOfDay()
            : null;

        if ($effectiveTo !== null && $effectiveTo->lt($effectiveFrom)) {
            throw ValidationException::withMessages([
                'effective_to' => ['effective_to must be on or after effective_from.'],
            ]);
        }

        $this->monthLock->assertRangeIsEditable(
            $category->house,
            $effectiveFrom,
            $effectiveTo ?? $effectiveFrom
        );

        $this->assertNoOverlap($category->id, $effectiveFrom, $effectiveTo);

        $nextVersion = (int) AllocationRule::query()
            ->where('expense_category_id', $category->id)
            ->max('version') + 1;

        return AllocationRule::query()->create([
            'expense_category_id' => $category->id,
            'rule_type' => $ruleType,
            'configuration' => $configuration,
            'effective_from' => $effectiveFrom->toDateString(),
            'effective_to' => $effectiveTo?->toDateString(),
            'version' => max(1, $nextVersion),
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Create a new version. Closes the currently open rule in the same transaction.
     * Never mutates historical configuration payloads.
     *
     * @param  array{
     *     rule_type: string,
     *     configuration?: array<string, mixed>,
     *     effective_from: string
     * }  $data
     */
    public function createVersion(ExpenseCategory $category, User $actor, array $data): AllocationRule
    {
        $this->access->assertOwner($category->house, $actor);

        $effectiveFrom = Carbon::parse($data['effective_from'])->startOfDay();

        return DB::transaction(function () use ($category, $actor, $data, $effectiveFrom) {
            $current = AllocationRule::query()
                ->where('expense_category_id', $category->id)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                // No open-ended rule — allow creating a version if ranges don't overlap.
                return $this->create($category, $actor, [
                    'rule_type' => $data['rule_type'],
                    'configuration' => $data['configuration'] ?? [],
                    'effective_from' => $effectiveFrom->toDateString(),
                    'effective_to' => null,
                ]);
            }

            if ($effectiveFrom->lte($current->effective_from)) {
                throw ValidationException::withMessages([
                    'effective_from' => ['New version effective_from must be after the current rule start.'],
                ]);
            }

            $closeTo = $effectiveFrom->copy()->subDay();

            if ($closeTo->lt($current->effective_from)) {
                throw ValidationException::withMessages([
                    'effective_from' => ['New version leaves no valid coverage for the previous rule.'],
                ]);
            }

            $this->monthLock->assertRangeIsEditable($category->house, $closeTo, $effectiveFrom);

            // Close old rule — dates only, never configuration.
            $current->effective_to = $closeTo->toDateString();
            $current->save();

            return $this->create($category, $actor, [
                'rule_type' => $data['rule_type'],
                'configuration' => $data['configuration'] ?? [],
                'effective_from' => $effectiveFrom->toDateString(),
                'effective_to' => null,
            ]);
        });
    }

    /**
     * Resolve the single rule that fully covers [from, to].
     */
    public function resolveForPeriod(ExpenseCategory $category, Carbon|string $from, Carbon|string $to): AllocationRule
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($to->lt($from)) {
            throw DomainException::because('Invalid period: end is before start.');
        }

        $candidates = AllocationRule::query()
            ->where('expense_category_id', $category->id)
            ->orderBy('version')
            ->get()
            ->filter(fn (AllocationRule $rule) => $rule->coversPeriod($from, $to));

        if ($candidates->count() === 0) {
            throw DomainException::because('No allocation rule covers this expense period.');
        }

        if ($candidates->count() > 1) {
            throw DomainException::because('Multiple allocation rules cover this expense period.');
        }

        return $candidates->first();
    }

    private function assertNoOverlap(int $categoryId, Carbon $from, ?Carbon $to): void
    {
        $rangeEnd = $to?->toDateString() ?? '9999-12-31';
        $rangeStart = $from->toDateString();

        $overlap = AllocationRule::query()
            ->where('expense_category_id', $categoryId)
            ->whereDate('effective_from', '<=', $rangeEnd)
            ->where(function ($query) use ($rangeStart) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $rangeStart);
            })
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'effective_from' => ['This rule version overlaps an existing rule version for the category.'],
            ]);
        }
    }
}
