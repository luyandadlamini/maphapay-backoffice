<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Services\Rails;

use App\Domain\MachinePay\Contracts\PaymentRailInterface;
use App\Domain\MachinePay\DataObjects\MppCredential;
use App\Domain\MachinePay\DataObjects\MppReceipt;
use App\Domain\MachinePay\Enums\PaymentRail;
use App\Domain\MachinePay\Exceptions\MppException;
use Illuminate\Support\Str;

/**
 * Demo rail adapter for all payment rails.
 *
 * Returns simulated payment responses using cache-based state.
 * Active in non-production environments. Matches the DemoCardIssuerAdapter pattern.
 */
class DemoRailAdapter implements PaymentRailInterface
{
    private PaymentRail $rail;

    public function __construct(PaymentRail $rail = PaymentRail::STRIPE_SPT)
    {
        $this->rail = $rail;
    }

    public function processPayment(MppCredential $credential, array $context = []): MppReceipt
    {
        if (app()->environment('production') && ! config('machinepay.allow_demo', false)) {
            throw new MppException(
                'Demo rail adapter cannot be used in production. ' .
                'Set MACHINEPAY_ALLOW_DEMO=true in .env to override.'
            );
        }

        $challengeId = $context['challenge_id'] ?? $credential->challengeId;

        return new MppReceipt(
            receiptId: 'rcpt_demo_' . Str::random(16),
            challengeId: (string) $challengeId,
            rail: $this->rail->value,
            settlementReference: 'demo_settlement_' . Str::random(20),
            settledAt: gmdate('Y-m-d\TH:i:s\Z'),
            amountCents: (int) ($context['amount_cents'] ?? 0),
            currency: (string) ($context['currency'] ?? 'USD'),
            status: 'success',
        );
    }

    public function verifyPayment(MppCredential $credential): bool
    {
        if (app()->environment('production') && ! config('machinepay.allow_demo', false)) {
            throw new MppException(
                'Demo rail adapter cannot be used in production. ' .
                'Set MACHINEPAY_ALLOW_DEMO=true in .env to override.'
            );
        }

        return true;
    }

    public function refund(string $settlementReference, int $amountCents): bool
    {
        if (app()->environment('production') && ! config('machinepay.allow_demo', false)) {
            throw new MppException(
                'Demo rail adapter cannot be used in production. ' .
                'Set MACHINEPAY_ALLOW_DEMO=true in .env to override.'
            );
        }

        return true;
    }

    public function getRailIdentifier(): PaymentRail
    {
        return $this->rail;
    }

    public function isAvailable(): bool
    {
        return ! app()->environment('production');
    }
}
