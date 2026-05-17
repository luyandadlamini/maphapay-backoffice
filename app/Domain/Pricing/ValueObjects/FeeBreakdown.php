<?php

declare(strict_types=1);

namespace App\Domain\Pricing\ValueObjects;

class FeeBreakdown
{
    public function __construct(
        private int $fixedMinor = 0,
        private int $percentageMinor = 0,
        private int $fxSpreadMinor = 0,
        private string $currency = '',
        private int $capMinMinor = 0,
        private int $capMaxMinor = 0,
        private int $discountMinor = 0
    ) {
    }

    public static function zero(string $currency): self
    {
        return new self(currency: $currency);
    }

    public function totalMinor(): int
    {
        $total = $this->fixedMinor + $this->percentageMinor + $this->fxSpreadMinor - $this->discountMinor;

        if ($this->capMinMinor > 0) {
            $total = max($total, $this->capMinMinor);
        }

        if ($this->capMaxMinor > 0) {
            $total = min($total, $this->capMaxMinor);
        }

        return $total;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function toArray(): array
    {
        return [
            'fixed_minor'      => $this->fixedMinor,
            'percentage_minor' => $this->percentageMinor,
            'fx_spread_minor'  => $this->fxSpreadMinor,
            'discount_minor'   => $this->discountMinor,
            'cap_min_minor'    => $this->capMinMinor,
            'cap_max_minor'    => $this->capMaxMinor,
            'currency'         => $this->currency,
            'total_minor'      => $this->totalMinor(),
        ];
    }
}
