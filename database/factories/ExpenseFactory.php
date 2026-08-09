<?php

namespace Database\Factories;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\House;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        $date = now()->toDateString();

        return [
            'house_id' => House::factory(),
            'expense_category_id' => ExpenseCategory::factory(),
            'paid_by' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'amount' => '1000.00',
            'expense_date' => $date,
            'period_start_date' => null,
            'period_end_date' => null,
            'status' => ExpenseStatus::Draft,
            'allocation_rule_id' => null,
            'created_by' => User::factory(),
            'confirmed_at' => null,
        ];
    }
}
