<?php

namespace Tests\Unit\Allocation;

use App\Support\Money;
use Tests\TestCase;

class MoneyRemainderTest extends TestCase
{
    public function test_zero_weight_participants_never_receive_remainder(): void
    {
        $shares = Money::allocateByWeights('100.00', [
            1 => 1,
            2 => 1,
            3 => 0,
        ]);

        $this->assertSame('0.00', $shares[3]);
        $this->assertEquals('100.00', Money::add($shares[1], $shares[2]));
        $this->assertContains($shares[1], ['50.00', '49.99', '50.01']);
    }

    public function test_allocate_equally_assigns_paisa_remainder_to_highest_id(): void
    {
        $shares = Money::allocateEqually('10.00', [10, 20, 30]);

        $this->assertSame('3.33', $shares[10]);
        $this->assertSame('3.33', $shares[20]);
        $this->assertSame('3.34', $shares[30]);
        $this->assertSame('10.00', Money::add(Money::add($shares[10], $shares[20]), $shares[30]));
    }

    public function test_all_zero_weights_return_zeros(): void
    {
        $shares = Money::allocateByWeights('50.00', [1 => 0, 2 => 0]);

        $this->assertSame('0.00', $shares[1]);
        $this->assertSame('0.00', $shares[2]);
    }
}
