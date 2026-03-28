<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use App\Domain\Asset\Models\Asset;
use InvalidArgumentException;

/**
 * Precision-safe currency converter using bcmath.
 *
 * Always operate on string amounts. Never pass PHP floats into money-moving
 * paths — float serialization drift causes idempotency key mismatches and
 * rounding errors that compound across retries.
 *
 * Usage:
 *   $minor = MoneyConverter::forAsset('25.10', $szlAsset);  // → 2510
 *   $major = MoneyConverter::toMajorUnitString(2510, 2);     // → "25.10"
 */
final class MoneyConverter
{
    /**
     * Convert a major-unit string (e.g. "25.10") to the integer smallest-unit
     * for the given precision (e.g. SZL precision=2 → 2510).
     *
     * Rounding: half-up (matches mobile roundToSZL / Math.round).
     *
     * @throws InvalidArgumentException if $amount is not a valid non-negative numeric string.
     */
    public static function toSmallestUnit(string $amount, int $precision): int
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException(
                "Invalid amount string '{$amount}': must be a non-negative numeric string (e.g. \"25.10\")."
            );
        }

        if ($precision < 0) {
            throw new InvalidArgumentException("Precision must be >= 0, got {$precision}.");
        }

        $multiplier = bcpow('10', (string) $precision, 0);

        // Scale to one extra decimal place so we can perform half-up rounding.
        /** @var numeric-string $numericAmount */
        $numericAmount = $amount;
        $scaled = bcmul($numericAmount, $multiplier, 1);  // e.g. "2510.0" or "2500.5"
        $rounded = bcadd($scaled, '0.5', 1);                // e.g. "2510.5" or "2501.0"

        // (int) cast truncates at the decimal point (floor), giving the rounded integer.
        return (int) $rounded;
    }

    /**
     * Convert a smallest-unit integer back to a major-unit string with exactly
     * $precision decimal places (e.g. 2510, precision=2 → "25.10").
     */
    public static function toMajorUnitString(int $amount, int $precision): string
    {
        if ($precision < 0) {
            throw new InvalidArgumentException("Precision must be >= 0, got {$precision}.");
        }

        $divisor = bcpow('10', (string) $precision, 0);

        return number_format($amount / (int) $divisor, $precision, '.', '');
    }

    /**
     * Convenience wrapper — resolve precision from an Asset model.
     *
     * @throws InvalidArgumentException if $amount is invalid.
     */
    public static function forAsset(string $amount, Asset $asset): int
    {
        return self::toSmallestUnit($amount, $asset->precision);
    }

    /**
     * Normalise a major-unit numeric string to exactly $precision decimal places.
     *
     * Useful for producing canonical request-body representations before
     * building idempotency-key hashes.
     *
     * e.g. "25.1" → "25.10"  (precision=2)
     *      "25"   → "25.00"  (precision=2)
     *
     * @throws InvalidArgumentException if $amount is not a valid numeric string.
     */
    public static function normalise(string $amount, int $precision): string
    {
        if (! is_numeric($amount) || $amount < 0) {
            throw new InvalidArgumentException(
                "Invalid amount '{$amount}': must be a non-negative numeric string."
            );
        }

        return number_format((float) $amount, $precision, '.', '');
    }
}
