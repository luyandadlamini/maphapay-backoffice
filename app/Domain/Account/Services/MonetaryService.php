<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

class MonetaryService
{
    /**
     * @param float|numeric-string|int $amount
     */
    public static function toCents(float|string|int $amount): int
    {
        return (int) bcmul((string) $amount, '100', 0);
    }

    public static function fromCents(int $cents): string
    {
        return bcdiv((string) $cents, '100', 2);
    }

    public static function add(int|float|string $a, int|float|string $b): string
    {
        /** @var numeric-string $aStr */
        $aStr = (string) $a;
        /** @var numeric-string $bStr */
        $bStr = (string) $b;

        return bcadd($aStr, $bStr, 2);
    }

    public static function subtract(int|float|string $a, int|float|string $b): string
    {
        /** @var numeric-string $aStr */
        $aStr = (string) $a;
        /** @var numeric-string $bStr */
        $bStr = (string) $b;

        return bcsub($aStr, $bStr, 2);
    }
}
