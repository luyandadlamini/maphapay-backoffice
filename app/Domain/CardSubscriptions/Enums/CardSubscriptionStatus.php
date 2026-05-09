<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardSubscriptionStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case PendingGuardianApproval = 'pending_guardian_approval';
}
