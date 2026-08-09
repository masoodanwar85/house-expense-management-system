<?php

namespace App\Enums;

enum AllocationRuleType: string
{
    case PerDay = 'per_day';
    case Fixed = 'fixed';
    case Hybrid = 'hybrid';
}
