<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

final readonly class CardFeePreview
{
    /**
     * @param array<string, int> $feeBreakdownCents
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $subtotalCents,
        public int $totalFeeCents,
        public int $totalCents,
        public string $currency,
        public array $feeBreakdownCents = [],
        public array $metadata = [],
    ) {
    }

    /**
     * @param array<string, int> $feeBreakdownCents
     */
    public static function fromBreakdown(int $subtotalCents, string $currency, array $feeBreakdownCents): self
    {
        $totalFeeCents = array_sum($feeBreakdownCents);

        return new self(
            subtotalCents: $subtotalCents,
            totalFeeCents: $totalFeeCents,
            totalCents: $subtotalCents + $totalFeeCents,
            currency: $currency,
            feeBreakdownCents: $feeBreakdownCents,
        );
    }
}
