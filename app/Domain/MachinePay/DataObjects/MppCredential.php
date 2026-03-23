<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\DataObjects;

use App\Domain\MachinePay\Enums\PaymentRail;
use App\Domain\MachinePay\Exceptions\MppException;

/**
 * MPP payment credential sent by the client via Authorization: Payment header.
 *
 * Contains the payment proof for a specific rail, binding back to the
 * original challenge via challenge ID.
 */
readonly class MppCredential
{
    /**
     * @param string              $challengeId     Reference to the original challenge.
     * @param string              $rail            Payment rail used (stripe, tempo, lightning, card).
     * @param array<string,mixed> $proofOfPayment  Rail-specific payment proof.
     * @param string|null         $payerIdentifier Optional payer DID or address.
     * @param string              $timestamp       RFC 3339 timestamp of credential creation.
     */
    public function __construct(
        public string $challengeId,
        public string $rail,
        public array $proofOfPayment,
        public ?string $payerIdentifier,
        public string $timestamp,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'challenge_id'     => $this->challengeId,
            'rail'             => $this->rail,
            'proof_of_payment' => $this->proofOfPayment,
            'payer_identifier' => $this->payerIdentifier,
            'timestamp'        => $this->timestamp,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['challenge_id', 'rail', 'proof_of_payment', 'timestamp'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new MppException("Missing required credential field: {$field}");
            }
        }

        return new self(
            challengeId: (string) $data['challenge_id'],
            rail: (string) $data['rail'],
            proofOfPayment: (array) $data['proof_of_payment'],
            payerIdentifier: isset($data['payer_identifier']) ? (string) $data['payer_identifier'] : null,
            timestamp: (string) $data['timestamp'],
        );
    }

    /**
     * Encode as base64url for the Authorization: Payment header.
     */
    public function toBase64Url(): string
    {
        $json = (string) json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Decode from base64url.
     */
    public static function fromBase64Url(string $encoded): self
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($decoded === false) {
            throw new MppException('Failed to decode base64url credential.');
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($decoded, true);

        if (! is_array($data)) {
            throw new MppException('Credential payload is not valid JSON.');
        }

        return self::fromArray($data);
    }

    /**
     * Get the payment rail as an enum instance.
     */
    public function getRail(): ?PaymentRail
    {
        return PaymentRail::tryFrom($this->rail);
    }
}
