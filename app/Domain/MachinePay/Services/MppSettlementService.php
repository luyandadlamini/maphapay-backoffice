<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Services;

use App\Domain\MachinePay\DataObjects\MppChallenge;
use App\Domain\MachinePay\DataObjects\MppCredential;
use App\Domain\MachinePay\DataObjects\MppReceipt;
use App\Domain\MachinePay\Enums\MppSettlementStatus;
use App\Domain\MachinePay\Events\MppPaymentFailed;
use App\Domain\MachinePay\Events\MppPaymentSettled;
use App\Domain\MachinePay\Events\MppPaymentVerified;
use App\Domain\MachinePay\Exceptions\MppSettlementException;
use App\Domain\MachinePay\Models\MppPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates MPP payment settlement across payment rails.
 *
 * Handles idempotency via payload hash, dispatches domain events,
 * and records settlement results in the payments ledger.
 */
class MppSettlementService
{
    public function __construct(
        private readonly MppVerificationService $verification,
        private readonly MppRailResolverService $railResolver,
    ) {
    }

    /**
     * Settle a verified payment credential.
     *
     * @throws MppSettlementException
     */
    public function settle(MppCredential $credential, MppChallenge $challenge): MppReceipt
    {
        // Idempotency: check if this credential has already been settled
        $payloadHash = hash('sha256', (string) json_encode($credential->toArray()));
        $existing = MppPayment::where('payload_hash', $payloadHash)
            ->where('status', MppSettlementStatus::SETTLED->value)
            ->first();

        if ($existing instanceof MppPayment) {
            Log::info('MPP: Returning idempotent settlement', ['payment_id' => $existing->uuid]);

            return new MppReceipt(
                receiptId: $existing->uuid,
                challengeId: $challenge->id,
                rail: $credential->rail,
                settlementReference: (string) $existing->settlement_reference,
                settledAt: $existing->updated_at?->format('c') ?? gmdate('c'),
                amountCents: $challenge->amountCents,
                currency: $challenge->currency,
            );
        }

        // Verify the credential
        $verifyResult = $this->verification->verify($credential, $challenge);

        if (! $verifyResult['valid']) {
            throw MppSettlementException::verificationFailed((string) $verifyResult['reason']);
        }

        MppPaymentVerified::dispatch($challenge->id, $credential->rail);

        // Record the payment attempt
        $payment = MppPayment::create([
            'uuid'             => Str::uuid()->toString(),
            'challenge_id'     => $challenge->id,
            'rail'             => $credential->rail,
            'amount_cents'     => $challenge->amountCents,
            'currency'         => $challenge->currency,
            'status'           => MppSettlementStatus::VERIFIED->value,
            'payer_identifier' => $credential->payerIdentifier,
            'endpoint_method'  => '',
            'endpoint_path'    => $challenge->resourceId,
            'payment_payload'  => $credential->toArray(),
            'payload_hash'     => $payloadHash,
        ]);

        // Delegate to the payment rail for settlement
        $rail = $this->railResolver->resolve($credential->rail);

        if ($rail === null) {
            $payment->update(['status' => MppSettlementStatus::FAILED->value]);
            MppPaymentFailed::dispatch($challenge->id, $credential->rail, 'Rail unavailable');

            throw MppSettlementException::railUnavailable($credential->rail);
        }

        try {
            $receipt = DB::transaction(function () use ($rail, $credential, $challenge, $payment): MppReceipt {
                $receipt = $rail->processPayment($credential, [
                    'amount_cents' => $challenge->amountCents,
                    'currency'     => $challenge->currency,
                    'challenge_id' => $challenge->id,
                ]);

                $payment->update([
                    'status'               => MppSettlementStatus::SETTLED->value,
                    'settlement_reference' => $receipt->settlementReference,
                ]);

                return $receipt;
            });

            MppPaymentSettled::dispatch(
                $challenge->id,
                $credential->rail,
                $receipt->settlementReference,
                $challenge->amountCents,
            );

            return $receipt;
        } catch (MppSettlementException $e) {
            $payment->update([
                'status'        => MppSettlementStatus::FAILED->value,
                'error_message' => $e->getMessage(),
            ]);
            MppPaymentFailed::dispatch($challenge->id, $credential->rail, $e->getMessage());

            throw $e;
        }
    }
}
