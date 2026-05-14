<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CardIssuance\ValueObjects;

use App\Domain\CardIssuance\ValueObjects\StripeUsdToSzlConverter;
use PHPUnit\Framework\TestCase;

class StripeUsdToSzlConverterTest extends TestCase
{
    public function test_converts_usd_cents_to_szl_decimal_string(): void
    {
        $converter = new StripeUsdToSzlConverter(rate: 18.50);

        $this->assertSame('185.00', $converter->toBillingAmount(1000));
    }

    public function test_handles_zero(): void
    {
        $converter = new StripeUsdToSzlConverter(rate: 18.50);

        $this->assertSame('0.00', $converter->toBillingAmount(0));
    }

    public function test_handles_fractional_cents_to_two_decimals(): void
    {
        $converter = new StripeUsdToSzlConverter(rate: 18.7654);

        $this->assertSame('231.57', $converter->toBillingAmount(1234));
    }

    public function test_billing_currency_is_szl(): void
    {
        $converter = new StripeUsdToSzlConverter(rate: 18.50);

        $this->assertSame('SZL', $converter->billingCurrency());
    }

    public function test_rate_is_accessible(): void
    {
        $converter = new StripeUsdToSzlConverter(rate: 18.50);

        $this->assertSame(18.50, $converter->rate());
    }
}
