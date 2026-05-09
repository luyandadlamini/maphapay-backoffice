<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardPlanEligibility: string
{
    case Adult = 'adult';
    case Minor = 'minor';
}
