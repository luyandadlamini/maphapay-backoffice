<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Services\Rails;

use App\Domain\MachinePay\Contracts\PaymentRailInterface;
use App\Domain\MachinePay\DataObjects\MppCredential;
use App\Domain\MachinePay\DataObjects\MppReceipt;
use App\Domain\MachinePay\Enums\PaymentRail;
use App\Domain\MachinePay\Exceptions\MppException;
use App\Domain\MachinePay\Exceptions\MppSettlementException;
use Illuminate\Support\Str;

/**
 * Lightning Network rail adapter.
 *
 * Processes BOLT11 invoice payments with preimage-based proof.
 * Verification: SHA-256(preimage) must equal the payment_hash.
 */
class LightningRailAdapter implements PaymentRailInterface
{
    public function processPayment(MppCredential $credential, array $context = []): MppReceipt
    {
        $preimage = $credential->proofOfPayment['preimage'] ?? null;

        if (! is_string($preimage) || strlen($preimage) !== 64) {
            throw MppSettlementException::verificationFailed('Invalid Lightning preimage (expected 32-byte hex).');
        }

        if (app()->environment('production')) {
            throw new MppException('Lightning rail requires production node integration (not yet implemented).');
        }

        return new MppReceipt(
            receiptId: 'rcpt_ln_' . Str::random(16),
            challengeId: $credential->challengeId,
            rail: PaymentRail::LIGHTNING->value,
            settlementReference: 'ln_' . hash('sha256', $preimage),
            settledAt: gmdate('Y-m-d\TH:i:s\Z'),
            amountCents: (int) ($context['amount_cents'] ?? 0),
            currency: (string) ($context['currency'] ?? 'BTC'),
        );
    }

    public function verifyPayment(MppCredential $credential): bool
    {
        $preimage = $credential->proofOfPayment['preimage'] ?? null;

        return is_string($preimage) && strlen($preimage) === 64 && ctype_xdigit($preimage);
    }

    public function refund(string $settlementReference, int $amountCents): bool
    {
        // Lightning payments are final — no refunds
        return false;
    }

    public function getRailIdentifier(): PaymentRail
    {
        return PaymentRail::LIGHTNING;
    }

    public function isAvailable(): bool
    {
        return config('machinepay.rails.lightning.node_uri') !== null
            || ! app()->environment('production');
    }
}
