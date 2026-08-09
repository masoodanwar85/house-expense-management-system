<?php

namespace App\Enums;

enum FixedApplyTo: string
{
    case AllMembers = 'all_members';
    case ActiveMembers = 'active_members';
    case FullPeriodMembers = 'full_period_members';
}
