<?php

namespace Tests\Feature;

use App\Enums\AllocationRuleType;
use App\Enums\MonthlySettlementStatus;
use App\Exceptions\DomainException;
use App\Models\House;
use App\Models\MonthlySettlement;
use App\Models\User;
use App\Services\Allocation\AllocationRuleService;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\House\HouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AllocationRuleVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_per_day_fixed_and_hybrid_rules(): void
    {
        [$owner, $house, $service] = $this->setupHouse();

        $water = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Water']);
        $security = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Security']);
        $electricity = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Electricity']);

        $perDay = $service->create($water, $owner, [
            'rule_type' => 'per_day',
            'configuration' => [],
            'effective_from' => '2026-01-01',
        ]);

        $fixed = $service->create($security, $owner, [
            'rule_type' => 'fixed',
            'configuration' => ['apply_to' => 'all_members'],
            'effective_from' => '2026-01-01',
        ]);

        $hybrid = $service->create($electricity, $owner, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 10, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 90],
                ],
            ],
            'effective_from' => '2026-01-01',
        ]);

        $this->assertEquals(AllocationRuleType::PerDay, $perDay->rule_type);
        $this->assertEquals(AllocationRuleType::Fixed, $fixed->rule_type);
        $this->assertEquals(AllocationRuleType::Hybrid, $hybrid->rule_type);
        $this->assertEquals(1, $hybrid->version);
        $this->assertEquals('percentage', $hybrid->configuration['mode']);
    }

    public function test_hybrid_percentages_must_sum_to_100(): void
    {
        [$owner, $house, $service] = $this->setupHouse();
        $category = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Electricity']);

        $this->expectException(ValidationException::class);

        $service->create($category, $owner, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 10, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 80],
                ],
            ],
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_hybrid_amount_remainder_rule_is_persisted(): void
    {
        [$owner, $house, $service] = $this->setupHouse();
        $category = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Electricity']);

        $rule = $service->create($category, $owner, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'mode' => 'amount_remainder',
                'components' => [
                    ['type' => 'fixed', 'amount' => '2000.00', 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'share' => 'remainder'],
                ],
            ],
            'effective_from' => '2026-01-01',
        ]);

        $this->assertEquals('amount_remainder', $rule->configuration['mode']);
        $this->assertEquals('2000.00', $rule->configuration['components'][0]['amount']);
        $this->assertEquals('remainder', $rule->configuration['components'][1]['share']);
    }

    public function test_hybrid_amount_remainder_requires_exactly_one_remainder(): void
    {
        [$owner, $house, $service] = $this->setupHouse();
        $category = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Electricity']);

        $this->expectException(ValidationException::class);

        $service->create($category, $owner, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'mode' => 'amount_remainder',
                'components' => [
                    ['type' => 'fixed', 'amount' => '2000.00', 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'amount' => '100.00'],
                ],
            ],
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_create_version_closes_previous_and_preserves_history(): void
    {
        [$owner, $house, $service] = $this->setupHouse();
        $category = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Electricity']);

        $v1 = $service->create($category, $owner, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 10, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 90],
                ],
            ],
            'effective_from' => '2026-01-01',
        ]);

        $v1Config = $v1->configuration;

        $v2 = $service->createVersion($category, $owner, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 20, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 80],
                ],
            ],
            'effective_from' => '2026-08-16',
        ]);

        $v1->refresh();

        $this->assertEquals('2026-08-15', $v1->effective_to->toDateString());
        $this->assertEquals($v1Config, $v1->configuration);
        $this->assertEquals(2, $v2->version);
        $this->assertNull($v2->effective_to);
        $this->assertEquals(20.0, $v2->configuration['components'][0]['percentage']);
    }

    public function test_overlapping_rule_versions_are_rejected(): void
    {
        [$owner, $house, $service] = $this->setupHouse();
        $category = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Water']);

        $service->create($category, $owner, [
            'rule_type' => 'per_day',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-08-31',
        ]);

        $this->expectException(ValidationException::class);

        $service->create($category, $owner, [
            'rule_type' => 'per_day',
            'effective_from' => '2026-08-15',
            'effective_to' => null,
        ]);
    }

    public function test_rule_resolution_uses_historical_version(): void
    {
        [$owner, $house, $service] = $this->setupHouse();
        $category = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Electricity']);

        $service->create($category, $owner, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 10, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 90],
                ],
            ],
            'effective_from' => '2026-01-01',
        ]);

        $service->createVersion($category, $owner, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 20, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 80],
                ],
            ],
            'effective_from' => '2026-08-16',
        ]);

        $historical = $service->resolveForPeriod($category, '2026-08-01', '2026-08-10');
        $current = $service->resolveForPeriod($category, '2026-08-16', '2026-08-31');

        $this->assertEquals(1, $historical->version);
        $this->assertEquals(10.0, $historical->configuration['components'][0]['percentage']);
        $this->assertEquals(2, $current->version);
        $this->assertEquals(20.0, $current->configuration['components'][0]['percentage']);
    }

    public function test_expense_period_spanning_rule_versions_is_rejected(): void
    {
        [$owner, $house, $service] = $this->setupHouse();
        $category = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Electricity']);

        $service->create($category, $owner, [
            'rule_type' => 'per_day',
            'effective_from' => '2026-01-01',
        ]);

        $service->createVersion($category, $owner, [
            'rule_type' => 'per_day',
            'effective_from' => '2026-08-16',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No allocation rule covers this expense period.');

        // Period spans v1 and v2 — neither covers the full range.
        $service->resolveForPeriod($category, '2026-08-01', '2026-08-31');
    }

    public function test_missing_rule_throws_domain_exception(): void
    {
        [$owner, $house, $service] = $this->setupHouse();
        $category = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Grocery']);

        $this->expectException(DomainException::class);

        $service->resolveForPeriod($category, '2026-08-01', '2026-08-31');
    }

    public function test_closed_month_blocks_rule_version_change(): void
    {
        [$owner, $house, $service] = $this->setupHouse();
        $category = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Water']);

        $service->create($category, $owner, [
            'rule_type' => 'per_day',
            'effective_from' => '2026-01-01',
        ]);

        MonthlySettlement::query()->create([
            'house_id' => $house->id,
            'year' => 2026,
            'month' => 8,
            'status' => MonthlySettlementStatus::Closed,
            'total_expenses' => '0.00',
            'closed_at' => now(),
            'closed_by' => $owner->id,
        ]);

        $this->expectException(DomainException::class);

        $service->createVersion($category, $owner, [
            'rule_type' => 'per_day',
            'effective_from' => '2026-08-16',
        ]);
    }

    public function test_fixed_rule_requires_apply_to(): void
    {
        [$owner, $house, $service] = $this->setupHouse();
        $category = app(ExpenseCategoryService::class)->create($house, $owner, ['name' => 'Security']);

        $this->expectException(ValidationException::class);

        $service->create($category, $owner, [
            'rule_type' => 'fixed',
            'configuration' => [],
            'effective_from' => '2026-01-01',
        ]);
    }

    /**
     * @return array{0: User, 1: House, 2: AllocationRuleService}
     */
    private function setupHouse(): array
    {
        $owner = User::factory()->create();
        $house = app(HouseService::class)->create($owner, ['name' => 'Family House']);

        return [$owner, $house, app(AllocationRuleService::class)];
    }
}
