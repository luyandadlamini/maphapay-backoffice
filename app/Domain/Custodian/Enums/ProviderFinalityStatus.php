<?php

declare(strict_types=1);

namespace App\Domain\Custodian\Enums;

enum ProviderFinalityStatus: string
{
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
