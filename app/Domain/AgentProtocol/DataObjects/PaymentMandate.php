<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\DataObjects;

/**
 * AP2 Payment Mandate — direct payment authorization.
 *
 * Authorizes a specific payment from payer to payee with
 * payment method preferences (x402, MPP, fiat transfer).
 */
readonly class PaymentMandate
{
    /**
     * @param string              $payeeDid                Recipient agent DID.
     * @param int                 $amountCents             Payment amount in smallest currency unit.
     * @param string              $currency                ISO 4217 currency code.
     * @param string              $payerDid                Payer agent/user DID.
     * @param array<string>       $paymentMethodPreferences Preferred payment methods (x402, mpp, fiat_transfer).
     * @param string|null         $reference               Payment reference/description.
     * @param string|null         $expiresAt               RFC 3339 expiry.
     */
    public function __construct(
        public string $payeeDid,
        public int $amountCents,
        public string $currency,
        public string $payerDid,
        public array $paymentMethodPreferences = ['x402', 'mpp'],
        public ?string $reference = null,
        public ?string $expiresAt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'payee_did'                  => $this->payeeDid,
            'amount_cents'               => $this->amountCents,
            'currency'                   => $this->currency,
            'payer_did'                  => $this->payerDid,
            'payment_method_preferences' => $this->paymentMethodPreferences,
            'reference'                  => $this->reference,
            'expires_at'                 => $this->expiresAt,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            payeeDid: (string) ($data['payee_did'] ?? ''),
            amountCents: (int) ($data['amount_cents'] ?? 0),
            currency: (string) ($data['currency'] ?? 'USD'),
            payerDid: (string) ($data['payer_did'] ?? ''),
            paymentMethodPreferences: (array) ($data['payment_method_preferences'] ?? ['x402', 'mpp']),
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            expiresAt: isset($data['expires_at']) ? (string) $data['expires_at'] : null,
        );
    }
}
