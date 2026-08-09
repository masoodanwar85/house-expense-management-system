<?php

namespace Database\Factories;

use App\Enums\AvailabilityStatus;
use App\Models\House;
use App\Models\MemberAvailabilityPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberAvailabilityPeriod>
 */
class MemberAvailabilityPeriodFactory extends Factory
{
    protected $model = MemberAvailabilityPeriod::class;

    public function definition(): array
    {
        return [
            'house_id' => House::factory(),
            'user_id' => User::factory(),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'status' => AvailabilityStatus::Available,
            'created_by' => User::factory(),
        ];
    }
}
