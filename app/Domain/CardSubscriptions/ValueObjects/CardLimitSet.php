<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

final readonly class CardLimitSet
{
    public function __construct(
        public ?int $perTransactionCents = null,
        public ?int $dailyCents = null,
        public ?int $monthlyCents = null,
        public ?int $atmDailyCents = null,
        public ?int $contactlessCents = null,
        public string $currency = 'SZL',
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            perTransactionCents: isset($data['per_transaction_cents']) ? (int) $data['per_transaction_cents'] : null,
            dailyCents: isset($data['daily_cents']) ? (int) $data['daily_cents'] : null,
            monthlyCents: isset($data['monthly_cents']) ? (int) $data['monthly_cents'] : null,
            atmDailyCents: isset($data['atm_daily_cents']) ? (int) $data['atm_daily_cents'] : null,
            contactlessCents: isset($data['contactless_cents']) ? (int) $data['contactless_cents'] : null,
            currency: (string) ($data['currency'] ?? 'SZL'),
        );
    }
}
