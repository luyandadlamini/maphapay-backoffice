<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\DataObjects;

/**
 * MPP payment receipt returned via the Payment-Receipt header.
 *
 * Confirms successful settlement of a payment challenge.
 */
readonly class MppReceipt
{
    /**
     * @param string $receiptId           Unique receipt identifier.
     * @param string $challengeId         Reference to the original challenge.
     * @param string $rail                Payment rail used.
     * @param string $settlementReference Rail-specific settlement reference (tx hash, PI ID, etc.).
     * @param string $settledAt           RFC 3339 settlement timestamp.
     * @param int    $amountCents         Settled amount in smallest currency unit.
     * @param string $currency            Currency code.
     * @param string $status              Settlement status (success, error, failure).
     */
    public function __construct(
        public string $receiptId,
        public string $challengeId,
        public string $rail,
        public string $settlementReference,
        public string $settledAt,
        public int $amountCents,
        public string $currency,
        public string $status = 'success',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'receipt_id'           => $this->receiptId,
            'challenge_id'         => $this->challengeId,
            'rail'                 => $this->rail,
            'settlement_reference' => $this->settlementReference,
            'settled_at'           => $this->settledAt,
            'amount_cents'         => $this->amountCents,
            'currency'             => $this->currency,
            'status'               => $this->status,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            receiptId: (string) ($data['receipt_id'] ?? ''),
            challengeId: (string) ($data['challenge_id'] ?? ''),
            rail: (string) ($data['rail'] ?? ''),
            settlementReference: (string) ($data['settlement_reference'] ?? ''),
            settledAt: (string) ($data['settled_at'] ?? ''),
            amountCents: (int) ($data['amount_cents'] ?? 0),
            currency: (string) ($data['currency'] ?? ''),
            status: (string) ($data['status'] ?? 'success'),
        );
    }

    /**
     * Encode as base64url for the Payment-Receipt header.
     */
    public function toBase64Url(): string
    {
        $json = (string) json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }
}
