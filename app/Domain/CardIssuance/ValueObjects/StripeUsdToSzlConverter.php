<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\ValueObjects;

final readonly class StripeUsdToSzlConverter
{
    public function __construct(private float $rate)
    {
    }

    public function toBillingAmount(int $usdCents): string
    {
        $szl = ($usdCents / 100) * $this->rate;

        return number_format($szl, 2, '.', '');
    }

    public function billingCurrency(): string
    {
        return 'SZL';
    }

    public function rate(): float
    {
        return $this->rate;
    }
}
