<?php

declare(strict_types=1);

namespace App\Domain\Segments\Enums;

enum SegmentSource: string
{
    case Static = 'static';
    case Dynamic = 'dynamic';
    case Hybrid = 'hybrid';
}
