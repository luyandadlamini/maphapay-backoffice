<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardDisputeStatus: string
{
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case EvidenceRequired = 'evidence_required';
    case Won = 'won';
    case Lost = 'lost';
    case Withdrawn = 'withdrawn';
}
