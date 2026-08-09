<?php

namespace Tests\Feature;

use App\Enums\MonthlySettlementStatus;
use App\Services\Expense\ExpenseService;
use App\Services\Monthly\MonthLockService;
use App\Services\Monthly\MonthlySettlementService;
use App\Services\Settlement\SettlementService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesFamilyHouse;
use Tests\TestCase;

/**
 * End-to-end coverage of the canonical Family House August scenario
 * from the product requirements (hybrid electricity, per-day water, fixed security).
 */
class AugustHouseholdScenarioTest extends TestCase
{
    use CreatesFamilyHouse;
    use RefreshDatabase;

    public function test_complete_august_household_settlement(): void
    {
        $setup = $this->createFamilyHouseWithAugustAvailability();
        $expenses = app(ExpenseService::class);
        $users = $setup['users'];
        $cats = $setup['categories'];

        $electricity = $expenses->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $cats['electricity']->id,
            'title' => 'August Electricity',
            'amount' => '20000.00',
            'expense_date' => '2026-08-30',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
            'paid_by' => $users['A']->id,
        ]);
        $expenses->confirm($electricity, $setup['owner']);

        $water = $expenses->create($setup['house'], $users['B'], [
            'expense_category_id' => $cats['water']->id,
            'title' => 'August Water',
            'amount' => '10000.00',
            'expense_date' => '2026-08-28',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
            'paid_by' => $users['B']->id,
        ]);
        $expenses->confirm($water, $setup['owner']);

        $security = $expenses->create($setup['house'], $users['C'], [
            'expense_category_id' => $cats['security']->id,
            'title' => 'August Security',
            'amount' => '4000.00',
            'expense_date' => '2026-08-31',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-31',
            'paid_by' => $users['C']->id,
        ]);
        $expenses->confirm($security, $setup['owner']);

        // Per-expense allocation checks
        $electricityAlloc = $electricity->fresh()->allocations->keyBy('user_id');
        $this->assertEquals('2300.00', (string) $electricityAlloc[$users['A']->id]->amount);
        $this->assertEquals('5900.00', (string) $electricityAlloc[$users['B']->id]->amount);

        $waterAlloc = $water->fresh()->allocations->keyBy('user_id');
        $this->assertEquals('1000.00', (string) $waterAlloc[$users['A']->id]->amount);
        $this->assertEquals('3000.00', (string) $waterAlloc[$users['B']->id]->amount);

        $securityAlloc = $security->fresh()->allocations->keyBy('user_id');
        foreach ($users as $user) {
            $this->assertEquals('1000.00', (string) $securityAlloc[$user->id]->amount);
        }

        $plan = app(SettlementService::class)->forMonth($setup['house'], $setup['owner'], 2026, 8);
        $byUser = $plan->balances->keyBy('userId');

        $this->assertEquals('34000.00', $plan->totalExpenses);

        // A: 20000 paid, 2300+1000+1000 = 4300 share => +15700
        $this->assertEquals('20000.00', $byUser[$users['A']->id]->actualPaid);
        $this->assertEquals('4300.00', $byUser[$users['A']->id]->responsibility);
        $this->assertEquals('15700.00', $byUser[$users['A']->id]->balance);

        // B: 10000 paid, 5900+3000+1000 = 9900 => +100
        $this->assertEquals('10000.00', $byUser[$users['B']->id]->actualPaid);
        $this->assertEquals('9900.00', $byUser[$users['B']->id]->responsibility);
        $this->assertEquals('100.00', $byUser[$users['B']->id]->balance);

        // C: 4000 paid, 9900 share => -5900
        $this->assertEquals('4000.00', $byUser[$users['C']->id]->actualPaid);
        $this->assertEquals('9900.00', $byUser[$users['C']->id]->responsibility);
        $this->assertEquals('-5900.00', $byUser[$users['C']->id]->balance);

        // D: 0 paid, 9900 share => -9900
        $this->assertEquals('0.00', $byUser[$users['D']->id]->actualPaid);
        $this->assertEquals('9900.00', $byUser[$users['D']->id]->responsibility);
        $this->assertEquals('-9900.00', $byUser[$users['D']->id]->balance);

        $this->assertTrue($plan->balances->every(
            fn ($b) => in_array($b->userId, [$users['A']->id, $users['B']->id], true)
                ? $b->isCreditor()
                : $b->isDebtor()
        ));

        // Transfers settle all balances and remain minimal.
        $this->assertGreaterThan(0, $plan->transfers->count());
        $this->assertLessThanOrEqual(3, $plan->transfers->count());

        $running = $plan->balances->mapWithKeys(
            fn ($b) => [$b->userId => $b->balance]
        )->all();

        foreach ($plan->transfers as $transfer) {
            $running[$transfer->fromUserId] = Money::add($running[$transfer->fromUserId], $transfer->amount);
            $running[$transfer->toUserId] = Money::sub($running[$transfer->toUserId], $transfer->amount);
        }

        foreach ($running as $balance) {
            $this->assertEquals('0.00', $balance);
        }

        $closed = app(MonthlySettlementService::class)->close($setup['house'], $setup['owner'], 2026, 8);
        $this->assertEquals(MonthlySettlementStatus::Closed, $closed->status);
        $this->assertEquals('34000.00', $closed->totalExpenses);
        $this->assertTrue(app(MonthLockService::class)->isClosed($setup['house'], 2026, 8));
    }

    public function test_allocations_never_create_or_lose_money_across_categories(): void
    {
        $setup = $this->createFamilyHouseWithAugustAvailability();
        $expenses = app(ExpenseService::class);

        foreach ([
            [$setup['categories']['electricity']->id, '20000.00', $setup['users']['A']->id],
            [$setup['categories']['water']->id, '10000.00', $setup['users']['B']->id],
            [$setup['categories']['security']->id, '4000.00', $setup['users']['C']->id],
            [$setup['categories']['gas']->id, '5000.00', $setup['users']['D']->id],
            [$setup['categories']['grocery']->id, '8000.00', $setup['users']['A']->id],
        ] as [$categoryId, $amount, $paidBy]) {
            $expense = $expenses->create($setup['house'], $setup['owner'], [
                'expense_category_id' => $categoryId,
                'title' => 'Bill '.$categoryId,
                'amount' => $amount,
                'expense_date' => '2026-08-20',
                'period_start_date' => '2026-08-01',
                'period_end_date' => '2026-08-30',
                'paid_by' => $paidBy,
            ]);
            $confirmed = $expenses->confirm($expense, $setup['owner']);

            $allocSum = '0.00';
            foreach ($confirmed->allocations as $row) {
                $allocSum = Money::add($allocSum, (string) $row->amount);
            }
            $this->assertEquals(Money::of($amount), $allocSum);
        }

        $summary = app(MonthlySettlementService::class)->summarize($setup['house'], $setup['owner'], 2026, 8);
        $this->assertEquals('47000.00', $summary->totalExpenses);

        $balanceSum = '0.00';
        foreach ($summary->balances as $balance) {
            $balanceSum = Money::add($balanceSum, $balance->balance);
        }
        $this->assertEquals('0.00', $balanceSum);
    }
}
