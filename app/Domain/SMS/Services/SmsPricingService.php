<?php

declare(strict_types=1);

namespace App\Domain\SMS\Services;

use Illuminate\Support\Facades\Log;

/**
 * Converts configured EUR rates (or fallback USDC) to atomic USDC pricing.
 *
 * Formula: rate_eur × eur_usd × margin × 1_000_000
 */
class SmsPricingService
{
    /**
     * @return array{amount_usdc: string, rate_eur: string, country_code: string, parts: int}
     */
    public function getPriceForNumber(string $phoneNumber, int $parts = 1): array
    {
        $countryCode = $this->extractCountryCode($phoneNumber);
        $rateEur = $this->getRateForCountry($countryCode);

        if ($rateEur <= 0.0) {
            $rateEur = 0.01;
        }

        $marginMultiplier = max(1.0, (float) config('sms.pricing.margin_multiplier', 1.15));
        $eurUsdRate = max(0.5, (float) config('sms.pricing.eur_usd_rate', 1.08));

        $perPartUsd = $rateEur * $eurUsdRate * $marginMultiplier;
        $totalUsd = $perPartUsd * $parts;

        $totalUsdStr = number_format($totalUsd, 8, '.', '');
        $atomicUsdc = (string) max(1000, (int) bcmul($totalUsdStr, '1000000', 0));

        return [
            'amount_usdc'  => $atomicUsdc,
            'rate_eur'     => number_format($rateEur, 4, '.', ''),
            'country_code' => $countryCode,
            'parts'        => $parts,
        ];
    }

    /**
     * @return array{country: string, country_code: string, rate_eur: string, rate_usdc: string}|null
     */
    public function getRateForDisplay(string $countryCode): ?array
    {
        foreach ($this->getRateCard() as $rate) {
            if (($rate['CountryCode'] ?? '') === strtoupper($countryCode)) {
                $rateEur = (float) ($rate['Rate'] ?? 0);
                $pricing = $this->getPriceForNumber('+' . $this->countryCodeToDialCode($countryCode) . '000000000');

                return [
                    'country'      => (string) ($rate['Country'] ?? $countryCode),
                    'country_code' => strtoupper($countryCode),
                    'rate_eur'     => number_format($rateEur, 4, '.', ''),
                    'rate_usdc'    => $pricing['amount_usdc'],
                ];
            }
        }

        return null;
    }

    private function getRateForCountry(string $countryCode): float
    {
        foreach ($this->getRateCard() as $rate) {
            if (($rate['CountryCode'] ?? '') === strtoupper($countryCode)) {
                return (float) ($rate['Rate'] ?? 0);
            }
        }

        Log::debug('SmsPricing: No rate found for country, using fallback', [
            'country_code' => $countryCode,
        ]);

        $fallbackUsdc = (int) config('sms.pricing.fallback_usdc', 50000);
        $eurUsdRate = (float) config('sms.pricing.eur_usd_rate', 1.08);
        $margin = (float) config('sms.pricing.margin_multiplier', 1.15);

        return ($fallbackUsdc / 1_000_000) / $eurUsdRate / $margin;
    }

    /**
     * @return array<int, array{CountryCode: string, Country: string, Operator: string, Rate: string}>
     */
    private function getRateCard(): array
    {
        /** @var mixed $raw */
        $raw = config('sms.rate_card', []);

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'CountryCode' => (string) ($row['CountryCode'] ?? ''),
                'Country'     => (string) ($row['Country'] ?? ''),
                'Operator'    => (string) ($row['Operator'] ?? ''),
                'Rate'        => (string) ($row['Rate'] ?? '0'),
            ];
        }

        return $out;
    }

    private function extractCountryCode(string $phoneNumber): string
    {
        $number = preg_replace('/[^0-9+]/', '', $phoneNumber);

        if ($number === null || $number === '') {
            return 'US';
        }

        if (str_starts_with($number, '+')) {
            $number = substr($number, 1);
        } elseif (str_starts_with($number, '00')) {
            $number = substr($number, 2);
        }

        $dialCodes = [
            ['370', 'LT'], ['371', 'LV'], ['372', 'EE'], ['358', 'FI'],
            ['353', 'IE'], ['351', 'PT'], ['420', 'CZ'], ['421', 'SK'],
            ['359', 'BG'], ['385', 'HR'], ['386', 'SI'], ['381', 'RS'],
            ['380', 'UA'], ['375', 'BY'], ['373', 'MD'], ['995', 'GE'],
            ['971', 'AE'], ['966', 'SA'], ['972', 'IL'],
            ['44', 'GB'], ['49', 'DE'], ['33', 'FR'], ['34', 'ES'],
            ['39', 'IT'], ['48', 'PL'], ['31', 'NL'], ['32', 'BE'],
            ['43', 'AT'], ['46', 'SE'], ['47', 'NO'], ['45', 'DK'],
            ['36', 'HU'], ['40', 'RO'], ['30', 'GR'], ['90', 'TR'],
            ['61', 'AU'], ['81', 'JP'], ['82', 'KR'], ['86', 'CN'],
            ['91', 'IN'], ['55', 'BR'], ['52', 'MX'], ['27', 'ZA'],
            ['65', 'SG'], ['60', 'MY'], ['66', 'TH'], ['62', 'ID'],
            ['63', 'PH'], ['84', 'VN'], ['41', 'CH'],
            ['7', 'RU'], ['1', 'US'],
        ];

        foreach ($dialCodes as [$dial, $country]) {
            if (str_starts_with($number, $dial)) {
                return $country;
            }
        }

        return 'US';
    }

    private function countryCodeToDialCode(string $countryCode): string
    {
        $map = [
            'LT' => '370', 'LV' => '371', 'EE' => '372',
            'GB' => '44',  'DE' => '49',  'FR' => '33',
            'US' => '1',   'ES' => '34',  'IT' => '39',
            'PL' => '48',  'NL' => '31',  'BE' => '32',
            'SE' => '46',  'NO' => '47',  'DK' => '45',
            'FI' => '358', 'IE' => '353', 'PT' => '351',
            'CH' => '41',  'AT' => '43',
        ];

        return $map[strtoupper($countryCode)] ?? '1';
    }
}
