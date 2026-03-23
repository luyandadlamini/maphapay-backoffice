<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\DataObjects;

use App\Domain\MachinePay\Enums\PaymentRail;
use App\Domain\MachinePay\Exceptions\MppInvalidChallengeException;

/**
 * MPP 402 challenge payload.
 *
 * Issued by the server via the WWW-Authenticate: Payment header.
 * Contains the challenge parameters and HMAC-SHA256 binding
 * over seven positional slots per the MPP spec.
 */
readonly class MppChallenge
{
    /**
     * @param string              $id             Unique challenge identifier.
     * @param string              $realm          Server realm (e.g. domain name).
     * @param string              $intent         Payment intent type (charge, session).
     * @param string              $resourceId     Protected resource identifier.
     * @param int                 $amountCents    Amount in smallest currency unit.
     * @param string              $currency       ISO 4217 currency code or token symbol.
     * @param array<string>       $availableRails Available payment rails.
     * @param string              $nonce          Cryptographic nonce.
     * @param string              $expiresAt      RFC 3339 expiry timestamp.
     * @param string|null         $hmac           HMAC-SHA256 over positional slots.
     * @param string|null         $description    Human-readable description.
     * @param array<string,mixed> $extensions     Optional protocol extensions.
     */
    public function __construct(
        public string $id,
        public string $realm,
        public string $intent,
        public string $resourceId,
        public int $amountCents,
        public string $currency,
        public array $availableRails,
        public string $nonce,
        public string $expiresAt,
        public ?string $hmac = null,
        public ?string $description = null,
        public array $extensions = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id'              => $this->id,
            'realm'           => $this->realm,
            'intent'          => $this->intent,
            'resource_id'     => $this->resourceId,
            'amount_cents'    => $this->amountCents,
            'currency'        => $this->currency,
            'available_rails' => $this->availableRails,
            'nonce'           => $this->nonce,
            'expires_at'      => $this->expiresAt,
            'hmac'            => $this->hmac,
            'description'     => $this->description,
            'extensions'      => $this->extensions ?: null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'realm', 'intent', 'resource_id', 'amount_cents', 'currency', 'nonce', 'expires_at'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw MppInvalidChallengeException::missingField($field);
            }
        }

        return new self(
            id: (string) $data['id'],
            realm: (string) $data['realm'],
            intent: (string) $data['intent'],
            resourceId: (string) $data['resource_id'],
            amountCents: (int) $data['amount_cents'],
            currency: (string) $data['currency'],
            availableRails: (array) ($data['available_rails'] ?? []),
            nonce: (string) $data['nonce'],
            expiresAt: (string) $data['expires_at'],
            hmac: isset($data['hmac']) ? (string) $data['hmac'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            extensions: (array) ($data['extensions'] ?? []),
        );
    }

    /**
     * Encode as base64url-encoded JCS-serialized JSON.
     */
    public function toBase64Url(): string
    {
        $json = (string) json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Decode from base64url-encoded JSON.
     */
    public static function fromBase64Url(string $encoded): self
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($decoded === false) {
            throw MppInvalidChallengeException::invalidEncoding('Failed to decode base64url challenge.');
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($decoded, true);

        if (! is_array($data)) {
            throw MppInvalidChallengeException::invalidEncoding('Challenge payload is not valid JSON.');
        }

        return self::fromArray($data);
    }

    /**
     * Build the HMAC binding string from seven positional slots.
     *
     * Per the MPP spec: realm|method|intent|request|expires|digest|opaque
     */
    public function buildHmacInput(): string
    {
        return implode('|', [
            $this->realm,
            $this->intent,
            $this->resourceId,
            (string) json_encode(['amount_cents' => $this->amountCents, 'currency' => $this->currency]),
            $this->expiresAt,
            $this->nonce,
            '', // opaque (reserved)
        ]);
    }

    /**
     * Check whether this challenge has expired.
     */
    public function isExpired(): bool
    {
        return strtotime($this->expiresAt) < time();
    }

    /**
     * Get the available rails as enum instances.
     *
     * @return array<PaymentRail>
     */
    public function getRails(): array
    {
        return array_filter(
            array_map(
                static fn (string $rail): ?PaymentRail => PaymentRail::tryFrom($rail),
                $this->availableRails,
            ),
        );
    }
}
