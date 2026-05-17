<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Enums;

enum PricingRuleStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Superseded = 'superseded';
    case RolledBack = 'rolled_back';
}
