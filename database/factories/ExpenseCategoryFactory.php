<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\House;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'house_id' => House::factory(),
            'name' => Str::title($name),
            'description' => fake()->optional()->sentence(),
            'code' => Str::slug($name, '_'),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
