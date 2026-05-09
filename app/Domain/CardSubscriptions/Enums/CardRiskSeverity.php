<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardRiskSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
