<?php

namespace Database\Seeders;

use App\Enums\AvailabilityStatus;
use App\Enums\HouseMemberRole;
use App\Models\HouseMember;
use App\Models\MemberAvailabilityPeriod;
use App\Models\User;
use App\Services\Allocation\AllocationRuleService;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\Expense\ExpenseService;
use App\Services\House\HouseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo household matching the product requirements:
 * Family House with Masood/Maqsood/Munawar/Fakhar, category rules, August availability + confirmed expenses.
 */
class FamilyHouseSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');

        $a = User::query()->updateOrCreate(
            ['email' => 'masood@example.com'],
            ['name' => 'Masood', 'password' => $password]
        );
        $b = User::query()->updateOrCreate(
            ['email' => 'maqsood@example.com'],
            ['name' => 'Maqsood', 'password' => $password]
        );
        $c = User::query()->updateOrCreate(
            ['email' => 'munawar@example.com'],
            ['name' => 'Munawar', 'password' => $password]
        );
        $d = User::query()->updateOrCreate(
            ['email' => 'fakhar@example.com'],
            ['name' => 'Fakhar', 'password' => $password]
        );

        $house = app(HouseService::class)->create($a, [
            'name' => 'Family House',
            'description' => 'Demo household for rule-driven expense allocation.',
            'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
        ]);

        MemberAvailabilityPeriod::query()->where('house_id', $house->id)->delete();

        foreach ([$b, $c, $d] as $user) {
            HouseMember::query()->create([
                'house_id' => $house->id,
                'user_id' => $user->id,
                'role' => HouseMemberRole::Member,
                'joined_at' => '2026-08-01 00:00:00',
                'left_at' => null,
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
            MemberAvailabilityPeriod::query()->create([
                'house_id' => $house->id,
                'user_id' => $userId,
                'start_date' => $start,
                'end_date' => $end,
                'status' => AvailabilityStatus::Available,
                'created_by' => $a->id,
            ]);
        }

        $categories = app(ExpenseCategoryService::class);
        $rules = app(AllocationRuleService::class);

        $electricity = $categories->create($house, $a, [
            'name' => 'Electricity',
            'code' => 'electricity',
            'sort_order' => 1,
        ]);
        $rules->create($electricity, $a, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 10, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 90],
                ],
            ],
            'effective_from' => '2026-01-01',
        ]);

        $water = $categories->create($house, $a, [
            'name' => 'Water',
            'code' => 'water',
            'sort_order' => 2,
        ]);
        $rules->create($water, $a, [
            'rule_type' => 'per_day',
            'configuration' => [],
            'effective_from' => '2026-01-01',
        ]);

        $security = $categories->create($house, $a, [
            'name' => 'Security',
            'code' => 'security',
            'sort_order' => 3,
        ]);
        $rules->create($security, $a, [
            'rule_type' => 'fixed',
            'configuration' => ['apply_to' => 'all_members'],
            'effective_from' => '2026-01-01',
        ]);

        $gas = $categories->create($house, $a, [
            'name' => 'Gas',
            'code' => 'gas',
            'sort_order' => 4,
        ]);
        $rules->create($gas, $a, [
            'rule_type' => 'per_day',
            'configuration' => [],
            'effective_from' => '2026-01-01',
        ]);

        $grocery = $categories->create($house, $a, [
            'name' => 'Grocery',
            'code' => 'grocery',
            'sort_order' => 5,
        ]);
        $rules->create($grocery, $a, [
            'rule_type' => 'per_day',
            'configuration' => [],
            'effective_from' => '2026-01-01',
        ]);

        $expenses = app(ExpenseService::class);

        $bills = [
            [
                'expense_category_id' => $electricity->id,
                'title' => 'August Electricity',
                'amount' => '20000.00',
                'expense_date' => '2026-08-30',
                'period_start_date' => '2026-08-01',
                'period_end_date' => '2026-08-30',
                'paid_by' => $a->id,
            ],
            [
                'expense_category_id' => $water->id,
                'title' => 'August Water',
                'amount' => '10000.00',
                'expense_date' => '2026-08-28',
                'period_start_date' => '2026-08-01',
                'period_end_date' => '2026-08-30',
                'paid_by' => $b->id,
            ],
            [
                'expense_category_id' => $security->id,
                'title' => 'August Security',
                'amount' => '4000.00',
                'expense_date' => '2026-08-31',
                'period_start_date' => '2026-08-01',
                'period_end_date' => '2026-08-31',
                'paid_by' => $c->id,
            ],
            [
                'expense_category_id' => $gas->id,
                'title' => 'August Gas',
                'amount' => '5000.00',
                'expense_date' => '2026-08-25',
                'period_start_date' => '2026-08-01',
                'period_end_date' => '2026-08-30',
                'paid_by' => $d->id,
            ],
            [
                'expense_category_id' => $grocery->id,
                'title' => 'August Grocery',
                'amount' => '8000.00',
                'expense_date' => '2026-08-20',
                'period_start_date' => '2026-08-01',
                'period_end_date' => '2026-08-30',
                'paid_by' => $a->id,
            ],
        ];

        foreach ($bills as $payload) {
            $expense = $expenses->create($house, $a, $payload);
            $expenses->confirm($expense, $a);
        }

        $this->command?->info('Family House seeded.');
        $this->command?->table(
            ['Email', 'Role', 'Password'],
            [
                ['masood@example.com', 'owner', 'password'],
                ['maqsood@example.com', 'member', 'password'],
                ['munawar@example.com', 'member', 'password'],
                ['fakhar@example.com', 'member', 'password'],
            ]
        );
    }
}
