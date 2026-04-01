<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Kyc;

/**
 * Normalizes internal KYC status values for MaphaPay compat clients that only
 * understand the legacy user.kyc_status vocabulary (see users.kyc_status enum).
 */
final class KycCompatStatus
{
    public static function normalizeForMobile(string $status): string
    {
        return match ($status) {
            'partial_identity' => 'pending',
            'not_submitted' => 'not_started',
            default => $status,
        };
    }
}
