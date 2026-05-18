<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing\ValueObjects;

use App\Domain\Pricing\ValueObjects\FeeBreakdown;
use Tests\TestCase;

final class FeeBreakdownTest extends TestCase
{
    public function test_total_minor_sums_fixed_percentage_and_fx_spread(): void
    {
        $breakdown = new FeeBreakdown(
            fixedMinor: 200,
            percentageMinor: 150,
            fxSpreadMinor: 50,
            currency: 'SZL',
        );

        $this->assertSame(400, $breakdown->totalMinor());
    }

    public function test_total_minor_is_clamped_to_cap_max(): void
    {
        $breakdown = new FeeBreakdown(
            fixedMinor: 100,
            percentageMinor: 50_000,
            fxSpreadMinor: 0,
            currency: 'SZL',
            capMaxMinor: 5_000,
        );

        $this->assertSame(5_000, $breakdown->totalMinor());
    }

    public function test_total_minor_is_raised_to_cap_min(): void
    {
        $breakdown = new FeeBreakdown(
            fixedMinor: 0,
            percentageMinor: 25,
            fxSpreadMinor: 0,
            currency: 'SZL',
            capMinMinor: 100,
        );

        $this->assertSame(100, $breakdown->totalMinor());
    }

    public function test_discount_minor_reduces_total_before_cap_min(): void
    {
        $breakdown = new FeeBreakdown(
            fixedMinor: 500,
            percentageMinor: 500,
            fxSpreadMinor: 0,
            currency: 'SZL',
            discountMinor: 200,
            capMinMinor: 50,
        );

        $this->assertSame(800, $breakdown->totalMinor());
    }

    public function test_discount_cannot_drop_total_below_cap_min(): void
    {
        $breakdown = new FeeBreakdown(
            fixedMinor: 100,
            percentageMinor: 0,
            fxSpreadMinor: 0,
            currency: 'SZL',
            discountMinor: 200,
            capMinMinor: 50,
        );

        $this->assertSame(50, $breakdown->totalMinor());
    }

    public function test_to_array_serialises_all_components(): void
    {
        $breakdown = new FeeBreakdown(
            fixedMinor: 200,
            percentageMinor: 150,
            fxSpreadMinor: 50,
            currency: 'SZL',
            capMinMinor: 100,
            capMaxMinor: 5_000,
            discountMinor: 25,
        );

        $this->assertSame([
            'fixed_minor'      => 200,
            'percentage_minor' => 150,
            'fx_spread_minor'  => 50,
            'discount_minor'   => 25,
            'cap_min_minor'    => 100,
            'cap_max_minor'    => 5_000,
            'currency'         => 'SZL',
            'total_minor'      => 375,
        ], $breakdown->toArray());
    }

    public function test_zero_construction_yields_zero_total(): void
    {
        $breakdown = FeeBreakdown::zero('SZL');

        $this->assertSame(0, $breakdown->totalMinor());
        $this->assertSame('SZL', $breakdown->currency());
    }
}
