<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

final readonly class CardFeePreviewInput
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $amountCents,
        public string $currency,
        public ?string $transactionType = null,
        public ?ReplacementReason $replacementReason = null,
        public ?string $deliveryMethod = null,
        public array $metadata = [],
    ) {
    }

    public static function transaction(int $amountCents, string $currency, ?string $transactionType = null): self
    {
        return new self($amountCents, $currency, $transactionType);
    }
}
