<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Mock;

final class MockFailureRules
{
    public const SUFFIX_HAPPY = '0001';

    public const SUFFIX_PAYER_NOT_FOUND = '0002';

    public const SUFFIX_INSUFFICIENT_FUNDS = '0003';

    public const SUFFIX_TIMEOUT = '0004';

    public const SUFFIX_REJECTED_BY_USER = '0005';

    public const AMOUNT_DUPLICATE_MINOR = 9999;

    public const AMOUNT_TRANSIENT_MINOR = 9998;

    public const SYNC_ACCEPT = 'accept';

    public const SYNC_DUPLICATE_409 = 'duplicate_409';

    public const SYNC_TRANSIENT_500 = 'transient_500';

    public const CB_SUCCESSFUL = 'successful';

    public const CB_FAILED_PAYER_NOT_FOUND = 'failed:PAYER_NOT_FOUND';

    public const CB_FAILED_INSUFFICIENT_FUNDS = 'failed:INSUFFICIENT_FUNDS';

    public const CB_SILENT_TIMEOUT = 'silent_timeout';

    public const CB_FAILED_REJECTED_BY_USER = 'failed:REJECTED_BY_USER';

    /**
     * @return self::SYNC_*
     */
    public static function syncOutcome(string $accountRef, int $amountMinor): string
    {
        return match ($amountMinor) {
            self::AMOUNT_DUPLICATE_MINOR => self::SYNC_DUPLICATE_409,
            self::AMOUNT_TRANSIENT_MINOR => self::SYNC_TRANSIENT_500,
            default                      => self::SYNC_ACCEPT,
        };
    }

    /**
     * @return self::CB_*
     */
    public static function callbackOutcome(string $accountRef): string
    {
        $suffix = substr(trim($accountRef), -4);

        return match ($suffix) {
            self::SUFFIX_PAYER_NOT_FOUND    => self::CB_FAILED_PAYER_NOT_FOUND,
            self::SUFFIX_INSUFFICIENT_FUNDS => self::CB_FAILED_INSUFFICIENT_FUNDS,
            self::SUFFIX_TIMEOUT            => self::CB_SILENT_TIMEOUT,
            self::SUFFIX_REJECTED_BY_USER   => self::CB_FAILED_REJECTED_BY_USER,
            default                         => self::CB_SUCCESSFUL,
        };
    }
}
