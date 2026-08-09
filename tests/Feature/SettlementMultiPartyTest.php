<?php

namespace Tests\Feature;

use App\Services\Expense\ExpenseService;
use App\Services\Settlement\SettlementService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesFamilyHouse;
use Tests\TestCase;

class SettlementMultiPartyTest extends TestCase
{
    use CreatesFamilyHouse;
    use RefreshDatabase;

    public function test_multiple_creditors_and_debtors_settle_with_minimal_transfers(): void
    {
        $setup = $this->createFamilyHouseWithAugustAvailability();
        $expenses = app(ExpenseService::class);
        $users = $setup['users'];

        // A pays large electricity; B pays water; C/D pay nothing => 2 creditors, 2 debtors.
        foreach ([
            [$setup['categories']['electricity']->id, '20000.00', $users['A']->id],
            [$setup['categories']['water']->id, '10000.00', $users['B']->id],
        ] as [$categoryId, $amount, $paidBy]) {
            $expense = $expenses->create($setup['house'], $setup['owner'], [
                'expense_category_id' => $categoryId,
                'title' => 'Bill',
                'amount' => $amount,
                'expense_date' => '2026-08-25',
                'period_start_date' => '2026-08-01',
                'period_end_date' => '2026-08-30',
                'paid_by' => $paidBy,
            ]);
            $expenses->confirm($expense, $setup['owner']);
        }

        $plan = app(SettlementService::class)->forMonth($setup['house'], $setup['owner'], 2026, 8);

        $creditors = $plan->balances->filter->isCreditor();
        $debtors = $plan->balances->filter->isDebtor();

        $this->assertGreaterThanOrEqual(2, $creditors->count());
        $this->assertGreaterThanOrEqual(2, $debtors->count());
        $this->assertLessThanOrEqual(
            $creditors->count() + $debtors->count() - 1,
            $plan->transfers->count()
        );

        $running = $plan->balances->mapWithKeys(fn ($b) => [$b->userId => $b->balance])->all();

        foreach ($plan->transfers as $transfer) {
            $this->assertTrue(Money::compare($transfer->amount, '0.00') === 1);
            $running[$transfer->fromUserId] = Money::add($running[$transfer->fromUserId], $transfer->amount);
            $running[$transfer->toUserId] = Money::sub($running[$transfer->toUserId], $transfer->amount);
        }

        foreach ($running as $balance) {
            $this->assertEquals('0.00', $balance);
        }
    }

    public function test_zero_net_household_produces_no_transfers(): void
    {
        $setup = $this->createFamilyHouseWithAugustAvailability();

        // Each pays exactly their security share by having each pay 1000 of a 4000 bill — use four equal bills.
        // Simpler: one fixed security bill paid by A, then each other already owes — not zero.
        // True zero: no confirmed expenses.
        $plan = app(SettlementService::class)->forMonth($setup['house'], $setup['owner'], 2026, 8);

        $this->assertEquals('0.00', $plan->totalExpenses);
        $this->assertCount(0, $plan->transfers);
        $this->assertTrue($plan->balances->every->isSettled());
    }
}
