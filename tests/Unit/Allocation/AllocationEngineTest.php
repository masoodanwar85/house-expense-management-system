<?php

namespace Tests\Unit\Allocation;

use App\Enums\AllocationRuleType;
use App\Models\AllocationRule;
use App\Services\Allocation\AllocationEngine;
use App\Services\Allocation\DTO\AllocationContext;
use App\Services\Allocation\DTO\UserAllocationResult;
use App\Support\Money;
use Tests\TestCase;

class AllocationEngineTest extends TestCase
{
    public function test_hybrid_electricity_example(): void
    {
        // A=10, B=30, C=30, D=30; 10% fixed + 90% per_day on 20000
        $context = new AllocationContext(
            amount: '20000.00',
            memberUserIds: [1, 2, 3, 4],
            availableDaysByUser: [1 => 10, 2 => 30, 3 => 30, 4 => 30],
            periodLengthDays: 30,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::Hybrid,
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 10, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 90],
                ],
            ],
            'version' => 1,
        ]);

        $results = collect(app(AllocationEngine::class)->allocate($context, $rule))->keyBy('userId');

        $this->assertSame('2300.00', $results[1]->amount);
        $this->assertSame('5900.00', $results[2]->amount);
        $this->assertSame('5900.00', $results[3]->amount);
        $this->assertSame('5900.00', $results[4]->amount);

        $this->assertSame('500.00', $results[1]->components['fixed']);
        $this->assertSame('1800.00', $results[1]->components['per_day']);
        $this->assertSame('500.00', $results[2]->components['fixed']);
        $this->assertSame('5400.00', $results[2]->components['per_day']);

        $this->assertAllocationSum('20000.00', $results->all());
    }

    public function test_fixed_security_includes_zero_day_member(): void
    {
        $context = new AllocationContext(
            amount: '8000.00',
            memberUserIds: [1, 2, 3, 4],
            availableDaysByUser: [1 => 30, 2 => 30, 3 => 30, 4 => 0],
            periodLengthDays: 30,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::Fixed,
            'configuration' => ['apply_to' => 'all_members'],
            'version' => 1,
        ]);

        $results = collect(app(AllocationEngine::class)->allocate($context, $rule))->keyBy('userId');

        foreach ([1, 2, 3, 4] as $userId) {
            $this->assertSame('2000.00', $results[$userId]->amount);
        }

        $this->assertAllocationSum('8000.00', $results->all());
    }

    public function test_per_day_water_example(): void
    {
        $context = new AllocationContext(
            amount: '10000.00',
            memberUserIds: [1, 2, 3, 4],
            availableDaysByUser: [1 => 10, 2 => 30, 3 => 30, 4 => 30],
            periodLengthDays: 30,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::PerDay,
            'configuration' => [],
            'version' => 1,
        ]);

        $results = collect(app(AllocationEngine::class)->allocate($context, $rule))->keyBy('userId');

        $this->assertSame('1000.00', $results[1]->amount);
        $this->assertSame('3000.00', $results[2]->amount);
        $this->assertSame('3000.00', $results[3]->amount);
        $this->assertSame('3000.00', $results[4]->amount);
        $this->assertAllocationSum('10000.00', $results->all());
    }

    public function test_per_day_zero_day_member_pays_zero(): void
    {
        $context = new AllocationContext(
            amount: '10000.00',
            memberUserIds: [1, 2, 3, 4],
            availableDaysByUser: [1 => 10, 2 => 30, 3 => 30, 4 => 0],
            periodLengthDays: 30,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::PerDay,
            'configuration' => [],
            'version' => 1,
        ]);

        $results = collect(app(AllocationEngine::class)->allocate($context, $rule))->keyBy('userId');

        $this->assertSame('0.00', $results[4]->amount);
        $this->assertAllocationSum('10000.00', $results->all());
    }

    public function test_hybrid_zero_day_member_pays_fixed_only(): void
    {
        $context = new AllocationContext(
            amount: '20000.00',
            memberUserIds: [1, 2, 3, 4],
            availableDaysByUser: [1 => 10, 2 => 30, 3 => 30, 4 => 0],
            periodLengthDays: 30,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::Hybrid,
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 10, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 90],
                ],
            ],
            'version' => 1,
        ]);

        $results = collect(app(AllocationEngine::class)->allocate($context, $rule))->keyBy('userId');

        // Fixed 2000/4 = 500 each; variable 18000 over 70 days
        $this->assertSame('500.00', $results[4]->components['fixed']);
        $this->assertSame('0.00', $results[4]->components['per_day']);
        $this->assertSame('500.00', $results[4]->amount);
        $this->assertAllocationSum('20000.00', $results->all());
    }

    public function test_fixed_active_members_excludes_zero_days(): void
    {
        $context = new AllocationContext(
            amount: '9000.00',
            memberUserIds: [1, 2, 3, 4],
            availableDaysByUser: [1 => 10, 2 => 30, 3 => 30, 4 => 0],
            periodLengthDays: 30,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::Fixed,
            'configuration' => ['apply_to' => 'active_members'],
            'version' => 1,
        ]);

        $results = collect(app(AllocationEngine::class)->allocate($context, $rule))->keyBy('userId');

        $this->assertSame('3000.00', $results[1]->amount);
        $this->assertSame('3000.00', $results[2]->amount);
        $this->assertSame('3000.00', $results[3]->amount);
        $this->assertSame('0.00', $results[4]->amount);
        $this->assertAllocationSum('9000.00', $results->all());
    }

    public function test_fixed_full_period_members_only(): void
    {
        $context = new AllocationContext(
            amount: '6000.00',
            memberUserIds: [1, 2, 3],
            availableDaysByUser: [1 => 31, 2 => 31, 3 => 20],
            periodLengthDays: 31,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::Fixed,
            'configuration' => ['apply_to' => 'full_period_members'],
            'version' => 1,
        ]);

        $results = collect(app(AllocationEngine::class)->allocate($context, $rule))->keyBy('userId');

        $this->assertSame('3000.00', $results[1]->amount);
        $this->assertSame('3000.00', $results[2]->amount);
        $this->assertSame('0.00', $results[3]->amount);
        $this->assertAllocationSum('6000.00', $results->all());
    }

    public function test_partial_availability_leave_mid_month(): void
    {
        // D available 20 days; A/B/C 31 days; per_day 10000
        $context = new AllocationContext(
            amount: '10000.00',
            memberUserIds: [1, 2, 3, 4],
            availableDaysByUser: [1 => 31, 2 => 31, 3 => 31, 4 => 20],
            periodLengthDays: 31,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::PerDay,
            'configuration' => [],
            'version' => 1,
        ]);

        $results = collect(app(AllocationEngine::class)->allocate($context, $rule))->keyBy('userId');

        // 113 person-days
        $this->assertSame(20, $results[4]->availabilityDays);
        $this->assertTrue(Money::compare($results[4]->amount, $results[1]->amount) < 0);
        $this->assertAllocationSum('10000.00', $results->all());
    }

    public function test_rounding_remainder_is_deterministic(): void
    {
        $context = new AllocationContext(
            amount: '100.00',
            memberUserIds: [1, 2, 3],
            availableDaysByUser: [1 => 1, 2 => 1, 3 => 1],
            periodLengthDays: 1,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::Fixed,
            'configuration' => ['apply_to' => 'all_members'],
            'version' => 1,
        ]);

        $results = collect(app(AllocationEngine::class)->allocate($context, $rule))->keyBy('userId');

        $this->assertSame('33.33', $results[1]->amount);
        $this->assertSame('33.33', $results[2]->amount);
        $this->assertSame('33.34', $results[3]->amount); // highest user id
        $this->assertAllocationSum('100.00', $results->all());
    }

    public function test_different_hybrid_percentages(): void
    {
        $context = new AllocationContext(
            amount: '10000.00',
            memberUserIds: [1, 2],
            availableDaysByUser: [1 => 10, 2 => 10],
            periodLengthDays: 10,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::Hybrid,
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 30, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 70],
                ],
            ],
            'version' => 1,
        ]);

        $results = collect(app(AllocationEngine::class)->allocate($context, $rule))->keyBy('userId');

        // Fixed 3000 => 1500 each; per_day 7000 => 3500 each
        $this->assertSame('1500.00', $results[1]->components['fixed']);
        $this->assertSame('3500.00', $results[1]->components['per_day']);
        $this->assertSame('5000.00', $results[1]->amount);
        $this->assertSame('5000.00', $results[2]->amount);
        $this->assertAllocationSum('10000.00', $results->all());
    }

    public function test_hybrid_amount_remainder_fixed_plus_per_day(): void
    {
        // Same economics as 10/90 on 20000 when fixed amount is 2000.
        $context = new AllocationContext(
            amount: '20000.00',
            memberUserIds: [1, 2, 3, 4],
            availableDaysByUser: [1 => 10, 2 => 30, 3 => 30, 4 => 30],
            periodLengthDays: 30,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::Hybrid,
            'configuration' => [
                'mode' => 'amount_remainder',
                'components' => [
                    ['type' => 'fixed', 'amount' => '2000.00', 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'share' => 'remainder'],
                ],
            ],
            'version' => 1,
        ]);

        $results = collect(app(AllocationEngine::class)->allocate($context, $rule))->keyBy('userId');

        $this->assertSame('2300.00', $results[1]->amount);
        $this->assertSame('5900.00', $results[2]->amount);
        $this->assertSame('5900.00', $results[3]->amount);
        $this->assertSame('5900.00', $results[4]->amount);
        $this->assertSame('500.00', $results[1]->components['fixed']);
        $this->assertSame('1800.00', $results[1]->components['per_day']);
        $this->assertAllocationSum('20000.00', $results->all());
    }

    public function test_hybrid_amount_remainder_rejects_when_fixed_exceeds_expense(): void
    {
        $context = new AllocationContext(
            amount: '1000.00',
            memberUserIds: [1, 2],
            availableDaysByUser: [1 => 10, 2 => 10],
            periodLengthDays: 10,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::Hybrid,
            'configuration' => [
                'mode' => 'amount_remainder',
                'components' => [
                    ['type' => 'fixed', 'amount' => '1500.00', 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'share' => 'remainder'],
                ],
            ],
            'version' => 1,
        ]);

        $this->expectException(\App\Exceptions\DomainException::class);

        app(AllocationEngine::class)->allocate($context, $rule);
    }

    public function test_hybrid_amount_remainder_zero_when_fixed_equals_expense(): void
    {
        $context = new AllocationContext(
            amount: '2000.00',
            memberUserIds: [1, 2],
            availableDaysByUser: [1 => 10, 2 => 10],
            periodLengthDays: 10,
        );

        $rule = new AllocationRule([
            'rule_type' => AllocationRuleType::Hybrid,
            'configuration' => [
                'mode' => 'amount_remainder',
                'components' => [
                    ['type' => 'fixed', 'amount' => '2000.00', 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'share' => 'remainder'],
                ],
            ],
            'version' => 1,
        ]);

        $results = collect(app(AllocationEngine::class)->allocate($context, $rule))->keyBy('userId');

        $this->assertSame('1000.00', $results[1]->amount);
        $this->assertSame('1000.00', $results[2]->amount);
        $this->assertSame('0.00', $results[1]->components['per_day']);
        $this->assertAllocationSum('2000.00', $results->all());
    }

    /**
     * @param  list<UserAllocationResult>  $results
     */
    private function assertAllocationSum(string $expected, array $results): void
    {
        $sum = '0.00';

        foreach ($results as $row) {
            $sum = Money::add($sum, $row->amount);
        }

        $this->assertSame(Money::of($expected), $sum);
    }
}
