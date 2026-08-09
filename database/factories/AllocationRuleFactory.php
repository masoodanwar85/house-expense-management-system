<?php

namespace Database\Factories;

use App\Enums\AllocationRuleType;
use App\Models\AllocationRule;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AllocationRule>
 */
class AllocationRuleFactory extends Factory
{
    protected $model = AllocationRule::class;

    public function definition(): array
    {
        return [
            'expense_category_id' => ExpenseCategory::factory(),
            'rule_type' => AllocationRuleType::PerDay,
            'configuration' => [],
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'version' => 1,
            'created_by' => User::factory(),
        ];
    }
}
