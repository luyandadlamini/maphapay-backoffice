<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Services;

use App\Domain\AgentProtocol\DataObjects\AP2PaymentMethod;
use App\Domain\AgentProtocol\Models\AgentMandate;
use App\Domain\AgentProtocol\Models\MandatePayment;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Bridges AP2 mandates to X402 and MPP payment systems.
 *
 * When a mandate is executed, this service determines the payment
 * method and delegates to the appropriate payment domain (X402 or MPP).
 */
class AP2PaymentBridgeService
{
    /**
     * Resolve the best payment method for a mandate.
     *
     * @param array<string> $preferences Ordered payment method preferences.
     */
    public function resolvePaymentMethod(array $preferences = ['x402', 'mpp']): AP2PaymentMethod
    {
        foreach ($preferences as $method) {
            if ($this->isPaymentMethodAvailable($method)) {
                return match ($method) {
                    'x402'  => AP2PaymentMethod::x402(),
                    'mpp'   => AP2PaymentMethod::mpp(),
                    default => new AP2PaymentMethod($method),
                };
            }
        }

        throw new RuntimeException('No payment method available for mandate execution.');
    }

    /**
     * Record a payment against a mandate.
     */
    public function recordPayment(
        string $mandateId,
        string $paymentType,
        string $paymentId,
        int $amountCents,
        string $currency,
    ): MandatePayment {
        $mandate = AgentMandate::where('uuid', $mandateId)->firstOrFail();

        $payment = MandatePayment::create([
            'mandate_id'   => $mandate->uuid,
            'payment_type' => $paymentType,
            'payment_id'   => $paymentId,
            'amount_cents' => $amountCents,
            'currency'     => $currency,
            'status'       => 'settled',
        ]);

        Log::info('AP2: Payment recorded for mandate', [
            'mandate_id'   => $mandateId,
            'payment_type' => $paymentType,
            'payment_id'   => $paymentId,
            'amount_cents' => $amountCents,
        ]);

        return $payment;
    }

    /**
     * Check if a payment method is available.
     */
    private function isPaymentMethodAvailable(string $method): bool
    {
        return match ($method) {
            'x402'  => (bool) config('x402.enabled', false),
            'mpp'   => (bool) config('machinepay.enabled', false),
            default => false,
        };
    }
}
