<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\HouseMemberRole;
use App\Models\HouseMember;
use App\Models\MemberAvailabilityPeriod;
use App\Models\User;
use App\Services\Allocation\AllocationRuleService;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\Expense\ExpenseService;
use App\Services\House\HouseService;
use App\Services\Settlement\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_month_builds_transfers_from_stored_allocations(): void
    {
        $setup = $this->seedAugustElectricity();

        $plan = app(SettlementService::class)->forMonth(
            $setup['house'],
            $setup['owner'],
            2026,
            8
        );

        $this->assertEquals('20000.00', $plan->totalExpenses);
        $this->assertCount(3, $plan->transfers);

        // A +17700; B,C,D each -5900
        $amounts = $plan->transfers->pluck('amount')->sort()->values()->all();
        $this->assertEquals(['5900.00', '5900.00', '5900.00'], $amounts);

        foreach ($plan->transfers as $transfer) {
            $this->assertEquals($setup['users']['A']->id, $transfer->toUserId);
            $this->assertContains($transfer->fromUserId, [
                $setup['users']['B']->id,
                $setup['users']['C']->id,
                $setup['users']['D']->id,
            ]);
        }

        $net = [
            $setup['users']['A']->id => '17700.00',
            $setup['users']['B']->id => '-5900.00',
            $setup['users']['C']->id => '-5900.00',
            $setup['users']['D']->id => '-5900.00',
        ];

        foreach ($plan->transfers as $transfer) {
            $net[$transfer->fromUserId] = bcadd($net[$transfer->fromUserId], $transfer->amount, 2);
            $net[$transfer->toUserId] = bcsub($net[$transfer->toUserId], $transfer->amount, 2);
        }

        foreach ($net as $balance) {
            $this->assertSame('0.00', $balance);
        }
    }

    /**
     * @return array{
     *     owner: User,
     *     house: \App\Models\House,
     *     users: array{A: User, B: User, C: User, D: User}
     * }
     */
    private function seedAugustElectricity(): array
    {
        $a = User::factory()->create(['name' => 'A']);
        $b = User::factory()->create(['name' => 'B']);
        $c = User::factory()->create(['name' => 'C']);
        $d = User::factory()->create(['name' => 'D']);

        $house = app(HouseService::class)->create($a, ['name' => 'Family House']);
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

        $category = app(ExpenseCategoryService::class)->create($house, $a, [
            'name' => 'Electricity',
            'code' => 'electricity',
        ]);

        app(AllocationRuleService::class)->create($category, $a, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 10, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 90],
                ],
            ],
            'effective_from' => '2026-01-01',
        ]);

        $expense = app(ExpenseService::class)->create($house, $a, [
            'expense_category_id' => $category->id,
            'title' => 'August Electricity',
            'amount' => '20000.00',
            'expense_date' => '2026-08-30',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
            'paid_by' => $a->id,
        ]);
        app(ExpenseService::class)->confirm($expense, $a);

        return [
            'owner' => $a,
            'house' => $house->fresh(),
            'users' => ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d],
        ];
    }
}
