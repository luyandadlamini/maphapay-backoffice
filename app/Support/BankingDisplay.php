<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money display helpers using {@see config('banking')} (`BANKING_DEFAULT_CURRENCY`, `BANKING_CURRENCY_SYMBOL`).
 */
final class BankingDisplay
{
    /** Symbol for form prefixes, notifications, and Chart.js (falls back to ISO code). */
    public static function currencySymbolForForms(): string
    {
        $symbol = trim((string) config('banking.currency_symbol', ''));

        return $symbol !== '' ? $symbol : trim((string) config('banking.default_currency', 'SZL'));
    }

    public static function minorUnitsAsString(int|float|string $minorUnits): string
    {
        $minor = is_numeric($minorUnits) ? (float) $minorUnits : 0.0;
        $symbol = trim((string) config('banking.currency_symbol', ''));
        $amount = number_format($minor / 100, 2);

        if ($symbol !== '') {
            return $symbol . $amount;
        }

        return trim((string) config('banking.default_currency', 'SZL')) . ' ' . $amount;
    }

    /** Amounts already in major units (e.g. dollars, GCU index). */
    public static function majorUnitsAsString(int|float|string $majorAmount, int $decimals = 2): string
    {
        $n = is_numeric($majorAmount) ? (float) $majorAmount : 0.0;
        $symbol = trim((string) config('banking.currency_symbol', ''));
        $formatted = number_format($n, $decimals);

        if ($symbol !== '') {
            return $symbol . $formatted;
        }

        return trim((string) config('banking.default_currency', 'SZL')) . ' ' . $formatted;
    }

    /** Prefix {@see Number::abbreviate()} style strings (e.g. "1.2K"). */
    public static function prefixAbbreviatedFigures(string $figures): string
    {
        $symbol = trim((string) config('banking.currency_symbol', ''));

        if ($symbol !== '') {
            return $symbol . $figures;
        }

        return trim((string) config('banking.default_currency', 'SZL')) . ' ' . $figures;
    }
}
