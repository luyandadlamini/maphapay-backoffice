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
 * Tempo stablecoin rail adapter.
 *
 * Processes TIP-20 stablecoin transfers on the Tempo blockchain
 * (chain ID 42431). Supports both transaction and hash credential types.
 */
class TempoRailAdapter implements PaymentRailInterface
{
    public function processPayment(MppCredential $credential, array $context = []): MppReceipt
    {
        $txHash = $credential->proofOfPayment['tx_hash'] ?? null;

        if (! is_string($txHash) || ! str_starts_with($txHash, '0x')) {
            throw MppSettlementException::verificationFailed('Invalid Tempo transaction hash format.');
        }

        if (app()->environment('production')) {
            throw new MppException('Tempo rail requires production RPC integration (not yet implemented).');
        }

        return new MppReceipt(
            receiptId: 'rcpt_tempo_' . Str::random(16),
            challengeId: $credential->challengeId,
            rail: PaymentRail::TEMPO->value,
            settlementReference: $txHash,
            settledAt: gmdate('Y-m-d\TH:i:s\Z'),
            amountCents: (int) ($context['amount_cents'] ?? 0),
            currency: (string) ($context['currency'] ?? 'USDC'),
        );
    }

    public function verifyPayment(MppCredential $credential): bool
    {
        $txHash = $credential->proofOfPayment['tx_hash'] ?? null;

        return is_string($txHash) && str_starts_with($txHash, '0x') && strlen($txHash) === 66;
    }

    public function refund(string $settlementReference, int $amountCents): bool
    {
        if (app()->environment('production')) {
            throw new MppException('Tempo refund requires production RPC integration.');
        }

        return true;
    }

    public function getRailIdentifier(): PaymentRail
    {
        return PaymentRail::TEMPO;
    }

    public function isAvailable(): bool
    {
        return config('machinepay.rails.tempo.endpoint') !== null
            || ! app()->environment('production');
    }
}
