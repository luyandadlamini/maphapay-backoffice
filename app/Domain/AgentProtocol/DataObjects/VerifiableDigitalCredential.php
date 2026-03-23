<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\DataObjects;

/**
 * AP2 Verifiable Digital Credential (VDC).
 *
 * SD-JWT-VC structure for mandate authorization.
 * Contains cryptographic proof of mandate issuance and acceptance.
 */
readonly class VerifiableDigitalCredential
{
    /**
     * @param string              $type        VDC type (cart_vdc, intent_vdc, payment_vdc).
     * @param string              $issuer      Issuer DID.
     * @param string              $subject     Subject DID.
     * @param array<string,mixed> $claims      Credential claims (mandate payload hash, etc.).
     * @param array<string>       $disclosures Selective disclosure elements.
     * @param string              $signature   SD-JWT-VC signature.
     * @param string              $issuedAt    RFC 3339 issuance timestamp.
     * @param string|null         $expiresAt   RFC 3339 expiry.
     * @param string|null         $mandateId   Associated mandate ID.
     */
    public function __construct(
        public string $type,
        public string $issuer,
        public string $subject,
        public array $claims,
        public array $disclosures,
        public string $signature,
        public string $issuedAt,
        public ?string $expiresAt = null,
        public ?string $mandateId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type'        => $this->type,
            'issuer'      => $this->issuer,
            'subject'     => $this->subject,
            'claims'      => $this->claims,
            'disclosures' => $this->disclosures,
            'signature'   => $this->signature,
            'issued_at'   => $this->issuedAt,
            'expires_at'  => $this->expiresAt,
            'mandate_id'  => $this->mandateId,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? ''),
            issuer: (string) ($data['issuer'] ?? ''),
            subject: (string) ($data['subject'] ?? ''),
            claims: (array) ($data['claims'] ?? []),
            disclosures: (array) ($data['disclosures'] ?? []),
            signature: (string) ($data['signature'] ?? ''),
            issuedAt: (string) ($data['issued_at'] ?? gmdate('c')),
            expiresAt: isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            mandateId: isset($data['mandate_id']) ? (string) $data['mandate_id'] : null,
        );
    }

    /**
     * Compute the credential hash for verification.
     */
    public function computeHash(): string
    {
        $payload = json_encode([
            'type'    => $this->type,
            'issuer'  => $this->issuer,
            'subject' => $this->subject,
            'claims'  => $this->claims,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }
}
