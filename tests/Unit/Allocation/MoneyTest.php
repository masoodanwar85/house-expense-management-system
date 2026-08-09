<?php

namespace Tests\Unit\Allocation;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_allocate_by_weights_assigns_remainder_to_highest_key(): void
    {
        $shares = Money::allocateByWeights('100.00', [10 => 1, 20 => 1, 30 => 1]);

        $this->assertSame('33.33', $shares[10]);
        $this->assertSame('33.33', $shares[20]);
        $this->assertSame('33.34', $shares[30]);
        $this->assertSame('100.00', Money::add(Money::add($shares[10], $shares[20]), $shares[30]));
    }

    public function test_percent_of_uses_bcmath(): void
    {
        $this->assertSame('2000.00000000', Money::percentOf('20000.00', '10'));
        $this->assertSame('2000.00', Money::round(Money::percentOf('20000.00', '10')));
    }
}
