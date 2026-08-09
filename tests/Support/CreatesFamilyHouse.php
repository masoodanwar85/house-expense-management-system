<?php

namespace Tests\Support;

use App\Enums\AvailabilityStatus;
use App\Enums\HouseMemberRole;
use App\Models\ExpenseCategory;
use App\Models\House;
use App\Models\HouseMember;
use App\Models\MemberAvailabilityPeriod;
use App\Models\User;
use App\Services\Allocation\AllocationRuleService;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\House\HouseService;

trait CreatesFamilyHouse
{
    /**
     * Canonical Family House: A(owner 10 days), B/C/D (30 days) in August 2026.
     *
     * @return array{
     *     house: House,
     *     owner: User,
     *     users: array{A: User, B: User, C: User, D: User},
     *     categories: array{electricity: ExpenseCategory, water: ExpenseCategory, security: ExpenseCategory, gas: ExpenseCategory, grocery: ExpenseCategory}
     * }
     */
    protected function createFamilyHouseWithAugustAvailability(bool $withCategories = true): array
    {
        $a = User::factory()->create(['name' => 'A']);
        $b = User::factory()->create(['name' => 'B']);
        $c = User::factory()->create(['name' => 'C']);
        $d = User::factory()->create(['name' => 'D']);

        $house = app(HouseService::class)->create($a, [
            'name' => 'Family House',
            'currency' => 'PKR',
        ]);

        MemberAvailabilityPeriod::query()->where('house_id', $house->id)->delete();

        foreach ([$b, $c, $d] as $user) {
            HouseMember::factory()->create([
                'house_id' => $house->id,
                'user_id' => $user->id,
                'role' => HouseMemberRole::Member,
                'joined_at' => '2026-08-01 00:00:00',
            ]);
        }

        HouseMember::query()
            ->where('house_id', $house->id)
            ->where('user_id', $a->id)
            ->update(['joined_at' => '2026-08-01 00:00:00']);

        foreach ([
            $a->id => ['2026-08-01', '2026-08-10'],
            $b->id => ['2026-08-01', '2026-08-30'],
            $c->id => ['2026-08-01', '2026-08-30'],
            $d->id => ['2026-08-01', '2026-08-30'],
        ] as $userId => [$start, $end]) {
            MemberAvailabilityPeriod::factory()->create([
                'house_id' => $house->id,
                'user_id' => $userId,
                'start_date' => $start,
                'end_date' => $end,
                'status' => AvailabilityStatus::Available,
                'created_by' => $a->id,
            ]);
        }

        $categories = [];

        if ($withCategories) {
            $categories = $this->seedStandardCategories($house, $a);
        }

        return [
            'house' => $house->fresh(),
            'owner' => $a,
            'users' => ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d],
            'categories' => $categories,
        ];
    }

    /**
     * @return array{electricity: ExpenseCategory, water: ExpenseCategory, security: ExpenseCategory, gas: ExpenseCategory, grocery: ExpenseCategory}
     */
    protected function seedStandardCategories(House $house, User $owner): array
    {
        $categoryService = app(ExpenseCategoryService::class);
        $ruleService = app(AllocationRuleService::class);

        $electricity = $categoryService->create($house, $owner, [
            'name' => 'Electricity',
            'code' => 'electricity',
        ]);
        $ruleService->create($electricity, $owner, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 10, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 90],
                ],
            ],
            'effective_from' => '2026-01-01',
        ]);

        $water = $categoryService->create($house, $owner, [
            'name' => 'Water',
            'code' => 'water',
        ]);
        $ruleService->create($water, $owner, [
            'rule_type' => 'per_day',
            'configuration' => [],
            'effective_from' => '2026-01-01',
        ]);

        $security = $categoryService->create($house, $owner, [
            'name' => 'Security',
            'code' => 'security',
        ]);
        $ruleService->create($security, $owner, [
            'rule_type' => 'fixed',
            'configuration' => ['apply_to' => 'all_members'],
            'effective_from' => '2026-01-01',
        ]);

        $gas = $categoryService->create($house, $owner, [
            'name' => 'Gas',
            'code' => 'gas',
        ]);
        $ruleService->create($gas, $owner, [
            'rule_type' => 'per_day',
            'configuration' => [],
            'effective_from' => '2026-01-01',
        ]);

        $grocery = $categoryService->create($house, $owner, [
            'name' => 'Grocery',
            'code' => 'grocery',
        ]);
        $ruleService->create($grocery, $owner, [
            'rule_type' => 'per_day',
            'configuration' => [],
            'effective_from' => '2026-01-01',
        ]);

        return [
            'electricity' => $electricity,
            'water' => $water,
            'security' => $security,
            'gas' => $gas,
            'grocery' => $grocery,
        ];
    }
}
