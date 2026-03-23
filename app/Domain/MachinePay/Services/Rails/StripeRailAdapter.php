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
 * Stripe SPT (Payment Token) rail adapter.
 *
 * Processes payments using single-use Stripe Payment Tokens (spt_).
 * In production, creates a PaymentIntent with confirm:true and
 * shared_payment_granted_token. Demo mode returns simulated responses.
 */
class StripeRailAdapter implements PaymentRailInterface
{
    public function processPayment(MppCredential $credential, array $context = []): MppReceipt
    {
        $spt = $credential->proofOfPayment['spt'] ?? null;

        if (! is_string($spt) || ! str_starts_with($spt, 'spt_')) {
            throw MppSettlementException::verificationFailed('Invalid Stripe Payment Token format.');
        }

        if (app()->environment('production')) {
            throw new MppException('Stripe rail requires production API integration (not yet implemented).');
        }

        // Demo mode: simulate successful payment
        return new MppReceipt(
            receiptId: 'rcpt_stripe_' . Str::random(16),
            challengeId: $credential->challengeId,
            rail: PaymentRail::STRIPE_SPT->value,
            settlementReference: 'pi_demo_' . Str::random(20),
            settledAt: gmdate('Y-m-d\TH:i:s\Z'),
            amountCents: (int) ($context['amount_cents'] ?? 0),
            currency: (string) ($context['currency'] ?? 'USD'),
        );
    }

    public function verifyPayment(MppCredential $credential): bool
    {
        $spt = $credential->proofOfPayment['spt'] ?? null;

        return is_string($spt) && str_starts_with($spt, 'spt_');
    }

    public function refund(string $settlementReference, int $amountCents): bool
    {
        if (app()->environment('production')) {
            throw new MppException('Stripe refund requires production API integration.');
        }

        return true;
    }

    public function getRailIdentifier(): PaymentRail
    {
        return PaymentRail::STRIPE_SPT;
    }

    public function isAvailable(): bool
    {
        return config('machinepay.rails.stripe.api_key_id') !== null
            || ! app()->environment('production');
    }
}
