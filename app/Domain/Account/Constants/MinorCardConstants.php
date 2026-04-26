<?php

declare(strict_types=1);

namespace App\Domain\Account\Constants;

final class MinorCardConstants
{
    public const REQUEST_EXPIRY_HOURS = 72;

    public const DEFAULT_DAILY_LIMIT = '2000.00';

    public const DEFAULT_MONTHLY_LIMIT = '10000.00';

    public const DEFAULT_SINGLE_TRANSACTION_LIMIT = '1500.00';

    public const REQUEST_TYPE_PARENT_INITIATED = 'parent_initiated';

    public const REQUEST_TYPE_CHILD_REQUESTED = 'child_requested';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DENIED = 'denied';

    public const STATUS_CARD_CREATED = 'card_created';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FROZEN = 'frozen';

    public const STATUS_REVOKED = 'revoked';

    public const VALID_TRANSITIONS = [
        self::STATUS_PENDING_APPROVAL => [self::STATUS_APPROVED, self::STATUS_DENIED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED         => [self::STATUS_CARD_CREATED, self::STATUS_CANCELLED],
        self::STATUS_DENIED           => [self::STATUS_PENDING_APPROVAL],
        self::STATUS_CANCELLED        => [],
        self::STATUS_CARD_CREATED     => [self::STATUS_ACTIVE],
        self::STATUS_EXPIRED          => [],
        self::STATUS_ACTIVE           => [self::STATUS_FROZEN, self::STATUS_REVOKED],
        self::STATUS_FROZEN           => [self::STATUS_ACTIVE, self::STATUS_REVOKED],
        self::STATUS_REVOKED          => [],
    ];
}
