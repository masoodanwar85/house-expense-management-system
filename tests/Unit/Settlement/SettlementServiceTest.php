<?php

namespace Tests\Unit\Settlement;

use App\Exceptions\DomainException;
use App\Services\Settlement\SettlementService;
use Tests\TestCase;

class SettlementServiceTest extends TestCase
{
    public function test_spec_example_produces_minimal_transfers(): void
    {
        // A +12000, B +3000, C -7000, D -8000
        $transfers = app(SettlementService::class)->generateTransfers([
            ['user_id' => 1, 'balance' => '12000.00'],
            ['user_id' => 2, 'balance' => '3000.00'],
            ['user_id' => 3, 'balance' => '-7000.00'],
            ['user_id' => 4, 'balance' => '-8000.00'],
        ]);

        $this->assertCount(3, $transfers);

        $this->assertEquals([
            ['from_user_id' => 4, 'to_user_id' => 1, 'amount' => '8000.00'],
            ['from_user_id' => 3, 'to_user_id' => 1, 'amount' => '4000.00'],
            ['from_user_id' => 3, 'to_user_id' => 2, 'amount' => '3000.00'],
        ], $transfers->map->toArray()->all());

        $this->assertTransfersSettleBalances($transfers, [
            1 => '12000.00',
            2 => '3000.00',
            3 => '-7000.00',
            4 => '-8000.00',
        ]);
    }

    public function test_zero_balances_produce_no_transfers(): void
    {
        $transfers = app(SettlementService::class)->generateTransfers([
            ['user_id' => 1, 'balance' => '0.00'],
            ['user_id' => 2, 'balance' => '0.00'],
        ]);

        $this->assertCount(0, $transfers);
    }

    public function test_single_debtor_and_creditor(): void
    {
        $transfers = app(SettlementService::class)->generateTransfers([
            ['user_id' => 10, 'balance' => '50.00'],
            ['user_id' => 20, 'balance' => '-50.00'],
        ]);

        $this->assertCount(1, $transfers);
        $this->assertSame(20, $transfers[0]->fromUserId);
        $this->assertSame(10, $transfers[0]->toUserId);
        $this->assertSame('50.00', $transfers[0]->amount);
    }

    public function test_tie_break_uses_lower_user_id(): void
    {
        // Two equal creditors / debtors — ordering must be deterministic.
        $transfers = app(SettlementService::class)->generateTransfers([
            ['user_id' => 2, 'balance' => '100.00'],
            ['user_id' => 1, 'balance' => '100.00'],
            ['user_id' => 4, 'balance' => '-100.00'],
            ['user_id' => 3, 'balance' => '-100.00'],
        ]);

        $this->assertEquals([
            ['from_user_id' => 3, 'to_user_id' => 1, 'amount' => '100.00'],
            ['from_user_id' => 4, 'to_user_id' => 2, 'amount' => '100.00'],
        ], $transfers->map->toArray()->all());
    }

    public function test_non_zero_sum_balances_are_rejected(): void
    {
        $this->expectException(DomainException::class);

        app(SettlementService::class)->generateTransfers([
            ['user_id' => 1, 'balance' => '100.00'],
            ['user_id' => 2, 'balance' => '-50.00'],
        ]);
    }

    public function test_transfer_count_never_exceeds_creditors_plus_debtors_minus_one(): void
    {
        $transfers = app(SettlementService::class)->generateTransfers([
            ['user_id' => 1, 'balance' => '100.00'],
            ['user_id' => 2, 'balance' => '200.00'],
            ['user_id' => 3, 'balance' => '50.00'],
            ['user_id' => 4, 'balance' => '-175.00'],
            ['user_id' => 5, 'balance' => '-175.00'],
        ]);

        // 3 creditors + 2 debtors => at most 4 transfers.
        $this->assertLessThanOrEqual(4, $transfers->count());
        $this->assertGreaterThan(0, $transfers->count());

        $this->assertTransfersSettleBalances($transfers, [
            1 => '100.00',
            2 => '200.00',
            3 => '50.00',
            4 => '-175.00',
            5 => '-175.00',
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Services\Settlement\DTO\SettlementTransfer>  $transfers
     * @param  array<int, string>  $balances
     */
    private function assertTransfersSettleBalances($transfers, array $balances): void
    {
        $running = $balances;

        foreach ($transfers as $transfer) {
            $running[$transfer->fromUserId] = bcadd($running[$transfer->fromUserId], $transfer->amount, 2);
            $running[$transfer->toUserId] = bcsub($running[$transfer->toUserId], $transfer->amount, 2);
        }

        foreach ($running as $balance) {
            $this->assertSame('0.00', bcadd($balance, '0', 2));
        }
    }
}
