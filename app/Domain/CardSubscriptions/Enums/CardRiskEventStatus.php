<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardRiskEventStatus: string
{
    case Open = 'open';
    case InReview = 'in_review';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
