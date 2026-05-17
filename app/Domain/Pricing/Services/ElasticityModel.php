<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

/**
 * Linear elasticity model:
 *
 *   adjusted_volume = max(0, round(base * (1 + elasticity_bps/10000 * feePctChange)))
 *
 * Conventions:
 *   - elasticity_bps_per_pct is a *signed* basis-point coefficient. A typical
 *     own-price elasticity for payment fees is negative (raising fees reduces
 *     volume), so the product configuration should carry e.g. -5000 bps.
 *   - feePctChange is a fraction (0.10 = +10%, -0.25 = -25%) computed against
 *     the *current* live fee.
 *
 * Pure function — no I/O, deterministic, safe to call inside a hot loop.
 */
final class ElasticityModel
{
    public static function apply(int $baseVolumeCount, float $feePctChange, int $elasticityBps): int
    {
        $factor = 1.0 + ($elasticityBps / 10_000.0) * $feePctChange;

        return (int) max(0, (int) round($baseVolumeCount * $factor));
    }
}
