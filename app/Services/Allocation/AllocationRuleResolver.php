<?php

namespace App\Services\Allocation;

use App\Models\AllocationRule;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Support\Carbon;

/**
 * Resolves the allocation rule version that fully covers a period.
 */
class AllocationRuleResolver
{
    public function __construct(
        private readonly AllocationRuleService $ruleService,
    ) {}

    public function resolveForCategoryPeriod(
        ExpenseCategory $category,
        Carbon|string $from,
        Carbon|string $to,
    ): AllocationRule {
        return $this->ruleService->resolveForPeriod($category, $from, $to);
    }

    public function resolveForExpense(Expense $expense): AllocationRule
    {
        $expense->loadMissing('category');

        return $this->resolveForCategoryPeriod(
            $expense->category,
            $expense->coverageStart(),
            $expense->coverageEnd(),
        );
    }
}
