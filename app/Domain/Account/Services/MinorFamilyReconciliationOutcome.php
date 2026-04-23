<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

enum MinorFamilyReconciliationOutcome: string
{
    case RECONCILED = 'reconciled';
    case UNRESOLVED = 'unresolved';

    public function isReconciled(): bool
    {
        return $this === self::RECONCILED;
    }
}
