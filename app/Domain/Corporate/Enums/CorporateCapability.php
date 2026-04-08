<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Enums;

enum CorporateCapability: string
{
    case TREASURY_OPERATIONS = 'treasury_operations';
    case PAYOUT_INITIATION = 'payout_initiation';
    case PAYOUT_APPROVAL = 'payout_approval';
    case MEMBER_ADMINISTRATION = 'member_administration';
    case COMPLIANCE_REVIEW = 'compliance_review';
    case API_ADMINISTRATION = 'api_administration';
    case SPEND_CONTROL_ADMINISTRATION = 'spend_control_administration';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $capability): string => $capability->value,
            self::cases(),
        );
    }
}
