<?php

namespace Database\Factories;

use App\Enums\SettlementPaymentStatus;
use App\Models\House;
use App\Models\SettlementPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementPayment>
 */
class SettlementPaymentFactory extends Factory
{
    protected $model = SettlementPayment::class;

    public function definition(): array
    {
        return [
            'house_id' => House::factory(),
            'from_user_id' => User::factory(),
            'to_user_id' => User::factory(),
            'year' => 2026,
            'month' => 8,
            'amount' => '100.00',
            'status' => SettlementPaymentStatus::Pending,
            'note' => null,
            'recorded_by' => fn (array $attributes) => $attributes['from_user_id'],
            'confirmed_by' => null,
            'confirmed_at' => null,
            'rejected_at' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SettlementPaymentStatus::Confirmed,
            'confirmed_by' => $attributes['to_user_id'],
            'confirmed_at' => now(),
        ]);
    }
}
