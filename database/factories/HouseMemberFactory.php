<?php

namespace Database\Factories;

use App\Enums\HouseMemberRole;
use App\Models\House;
use App\Models\HouseMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HouseMember>
 */
class HouseMemberFactory extends Factory
{
    protected $model = HouseMember::class;

    public function definition(): array
    {
        return [
            'house_id' => House::factory(),
            'user_id' => User::factory(),
            'role' => HouseMemberRole::Member,
            'joined_at' => now()->startOfMonth(),
            'left_at' => null,
        ];
    }
}
