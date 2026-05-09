<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

final readonly class BillingAttemptResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $result,
        public ?string $reason = null,
        public ?string $ledgerPostingId = null,
        public ?string $billingAttemptId = null,
        public ?int $amountCents = null,
        public string $currency = 'SZL',
        public array $metadata = [],
    ) {
    }

    public static function success(?string $ledgerPostingId = null, ?int $amountCents = null, string $currency = 'SZL'): self
    {
        return new self('success', ledgerPostingId: $ledgerPostingId, amountCents: $amountCents, currency: $currency);
    }

    public static function failed(string $reason, ?int $amountCents = null, string $currency = 'SZL'): self
    {
        return new self('failed', reason: $reason, amountCents: $amountCents, currency: $currency);
    }
}
