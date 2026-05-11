<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\ValueObjects;

use DateTimeImmutable;

/**
 * Incoming JIT funding authorization request from card issuer.
 */
final readonly class AuthorizationRequest
{
    public function __construct(
        public string $authorizationId,
        public string $cardToken,
        public int $amountCents,
        public string $currency,
        public string $merchantName,
        public string $merchantCategory,
        public ?string $merchantId = null,
        public ?string $merchantCity = null,
        public ?string $merchantCountry = null,
        public ?DateTimeImmutable $timestamp = null,
    ) {
    }

    public function getAmountDecimal(): string
    {
        return number_format($this->amountCents / 100, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromWebhook(array $data): self
    {
        return new self(
            authorizationId: $data['authorization_id'],
            cardToken: $data['card_token'],
            amountCents: (int) $data['amount'],
            currency: $data['currency'] ?? 'USD',
            merchantName: $data['merchant_name'] ?? 'Unknown',
            merchantCategory: $data['merchant_category'] ?? 'unknown',
            merchantId: $data['merchant_id'] ?? null,
            merchantCity: $data['merchant_city'] ?? null,
            merchantCountry: $data['merchant_country'] ?? null,
            timestamp: isset($data['timestamp'])
                ? new DateTimeImmutable($data['timestamp'])
                : new DateTimeImmutable(),
        );
    }

    /**
     * Whether this authorisation looks like an ATM cash withdrawal.
     */
    public function isAtmWithdrawal(): bool
    {
        $mcc = $this->normalizedMcc();

        if ($mcc !== null && in_array($mcc, ['6010', '6011', '6012'], true)) {
            return true;
        }

        return str_contains(strtolower($this->merchantName), 'atm');
    }

    /**
     * Four-digit MCC when {@see $merchantCategory} is numeric; otherwise null.
     */
    public function normalizedMcc(): ?string
    {
        $raw = trim($this->merchantCategory);

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}$/', $raw) === 1) {
            return $raw;
        }

        return null;
    }
}
