<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Enums;

enum FeeFormula: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
    case Hybrid = 'hybrid';
    case Tiered = 'tiered';
    case Volume = 'volume';
    case TimeWindow = 'time_window';
}
