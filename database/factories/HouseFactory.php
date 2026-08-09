<?php

namespace Database\Factories;

use App\Models\House;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<House>
 */
class HouseFactory extends Factory
{
    protected $model = House::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' House',
            'description' => fake()->optional()->sentence(),
            'owner_id' => User::factory(),
            'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
        ];
    }
}
