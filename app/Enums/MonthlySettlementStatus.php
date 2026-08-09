<?php

namespace App\Enums;

enum MonthlySettlementStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
