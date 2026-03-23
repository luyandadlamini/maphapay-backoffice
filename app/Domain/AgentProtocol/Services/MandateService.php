<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Services;

use App\Domain\AgentProtocol\DataObjects\CartMandate;
use App\Domain\AgentProtocol\DataObjects\IntentMandate;
use App\Domain\AgentProtocol\DataObjects\MandateResult;
use App\Domain\AgentProtocol\DataObjects\PaymentMandate;
use App\Domain\AgentProtocol\Enums\MandateStatus;
use App\Domain\AgentProtocol\Enums\MandateType;
use App\Domain\AgentProtocol\Events\MandateAccepted;
use App\Domain\AgentProtocol\Events\MandateCompleted;
use App\Domain\AgentProtocol\Events\MandateCreated;
use App\Domain\AgentProtocol\Events\MandateDisputed;
use App\Domain\AgentProtocol\Events\MandateExecuted;
use App\Domain\AgentProtocol\Events\MandateRevoked;
use App\Domain\AgentProtocol\Models\AgentMandate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AP2 Mandate lifecycle management service.
 *
 * Handles creation, acceptance, execution, revocation, and dispute
 * of Cart, Intent, and Payment mandates per the AP2 specification.
 */
class MandateService
{
    /**
     * Create a Cart Mandate (human-present shopping).
     */
    public function createCartMandate(CartMandate $cart): MandateResult
    {
        return $this->createMandate(
            MandateType::CART_MANDATE,
            $cart->merchantDid,
            $cart->shoppingAgentDid,
            $cart->toArray(),
            $cart->totalCents,
            $cart->currency,
            $cart->expiresAt,
        );
    }

    /**
     * Create an Intent Mandate (human-not-present autonomous).
     */
    public function createIntentMandate(IntentMandate $intent): MandateResult
    {
        return $this->createMandate(
            MandateType::INTENT_MANDATE,
            $intent->delegatorDid,
            $intent->agentDid,
            $intent->toArray(),
            $intent->budgetCents,
            $intent->currency,
            $intent->expiresAt,
        );
    }

    /**
     * Create a Payment Mandate (direct payment).
     */
    public function createPaymentMandate(PaymentMandate $payment): MandateResult
    {
        return $this->createMandate(
            MandateType::PAYMENT_MANDATE,
            $payment->payerDid,
            $payment->payeeDid,
            $payment->toArray(),
            $payment->amountCents,
            $payment->currency,
            $payment->expiresAt,
        );
    }

    /**
     * Accept a mandate.
     */
    public function acceptMandate(string $mandateId, string $acceptedByDid): MandateResult
    {
        return DB::transaction(function () use ($mandateId, $acceptedByDid): MandateResult {
            $mandate = AgentMandate::where('uuid', $mandateId)->lockForUpdate()->firstOrFail();

            if ($mandate->status !== MandateStatus::ISSUED->value) {
                throw new RuntimeException("Mandate '{$mandateId}' is not in ISSUED state.");
            }

            if ($mandate->isExpired()) {
                $mandate->update(['status' => MandateStatus::EXPIRED->value]);

                throw new RuntimeException("Mandate '{$mandateId}' has expired.");
            }

            $mandate->update(['status' => MandateStatus::ACCEPTED->value]);

            MandateAccepted::dispatch($mandateId, $acceptedByDid);

            Log::info('AP2: Mandate accepted', [
                'mandate_id'  => $mandateId,
                'accepted_by' => $acceptedByDid,
            ]);

            return new MandateResult($mandateId, MandateStatus::ACCEPTED);
        });
    }

    /**
     * Execute a mandate (trigger payment).
     */
    public function executeMandate(string $mandateId, string $paymentMethod, string $paymentReference): MandateResult
    {
        return DB::transaction(function () use ($mandateId, $paymentMethod, $paymentReference): MandateResult {
            $mandate = AgentMandate::where('uuid', $mandateId)->lockForUpdate()->firstOrFail();

            if ($mandate->status !== MandateStatus::ACCEPTED->value) {
                throw new RuntimeException("Mandate '{$mandateId}' must be ACCEPTED before execution.");
            }

            $references = (array) ($mandate->payment_references ?? []);
            $references[] = $paymentReference;

            $mandate->update([
                'status'             => MandateStatus::EXECUTED->value,
                'payment_references' => $references,
                'executed_at'        => now()->toDateTimeString(),
            ]);

            MandateExecuted::dispatch($mandateId, $paymentMethod, $paymentReference);

            Log::info('AP2: Mandate executed', [
                'mandate_id'     => $mandateId,
                'payment_method' => $paymentMethod,
                'payment_ref'    => $paymentReference,
            ]);

            return new MandateResult($mandateId, MandateStatus::EXECUTED, $references);
        });
    }

    /**
     * Complete a mandate after settlement confirmation.
     */
    public function completeMandate(string $mandateId): MandateResult
    {
        $mandate = AgentMandate::where('uuid', $mandateId)->firstOrFail();

        if ($mandate->status !== MandateStatus::EXECUTED->value) {
            throw new RuntimeException("Mandate '{$mandateId}' must be EXECUTED before completion.");
        }

        $mandate->update([
            'status'       => MandateStatus::COMPLETED->value,
            'completed_at' => now()->toDateTimeString(),
        ]);

        MandateCompleted::dispatch($mandateId);

        return new MandateResult($mandateId, MandateStatus::COMPLETED, (array) ($mandate->payment_references ?? []));
    }

    /**
     * Revoke a mandate.
     */
    public function revokeMandate(string $mandateId, string $revokedByDid, string $reason): MandateResult
    {
        $mandate = AgentMandate::where('uuid', $mandateId)->firstOrFail();

        if ($mandate->getStatusEnum()->isTerminal()) {
            throw new RuntimeException("Mandate '{$mandateId}' is already in a terminal state.");
        }

        $mandate->update(['status' => MandateStatus::REVOKED->value]);

        MandateRevoked::dispatch($mandateId, $revokedByDid, $reason);

        Log::info('AP2: Mandate revoked', [
            'mandate_id' => $mandateId,
            'revoked_by' => $revokedByDid,
            'reason'     => $reason,
        ]);

        return new MandateResult($mandateId, MandateStatus::REVOKED);
    }

    /**
     * Dispute a mandate.
     */
    public function disputeMandate(string $mandateId, string $disputedByDid, string $reason): MandateResult
    {
        $mandate = AgentMandate::where('uuid', $mandateId)->firstOrFail();

        if (! in_array($mandate->status, [MandateStatus::EXECUTED->value, MandateStatus::ACCEPTED->value], true)) {
            throw new RuntimeException("Mandate '{$mandateId}' cannot be disputed in current state.");
        }

        $mandate->update(['status' => MandateStatus::DISPUTED->value]);

        MandateDisputed::dispatch($mandateId, $disputedByDid, $reason);

        Log::info('AP2: Mandate disputed', [
            'mandate_id'  => $mandateId,
            'disputed_by' => $disputedByDid,
            'reason'      => $reason,
        ]);

        return new MandateResult($mandateId, MandateStatus::DISPUTED, disputeInfo: $reason);
    }

    /**
     * Internal: create a mandate record.
     *
     * @param array<string, mixed> $payload
     */
    private function createMandate(
        MandateType $type,
        string $issuerDid,
        string $subjectDid,
        array $payload,
        int $amountCents,
        string $currency,
        ?string $expiresAt,
    ): MandateResult {
        $mandateId = Str::uuid()->toString();

        AgentMandate::create([
            'uuid'         => $mandateId,
            'type'         => $type->value,
            'status'       => MandateStatus::ISSUED->value,
            'issuer_did'   => $issuerDid,
            'subject_did'  => $subjectDid,
            'payload'      => $payload,
            'amount_cents' => $amountCents,
            'currency'     => $currency,
            'expires_at'   => $expiresAt,
        ]);

        MandateCreated::dispatch($mandateId, $type->value, $issuerDid, $subjectDid, $amountCents, $currency);

        Log::info('AP2: Mandate created', [
            'mandate_id' => $mandateId,
            'type'       => $type->value,
            'issuer'     => $issuerDid,
            'subject'    => $subjectDid,
            'amount'     => $amountCents,
        ]);

        return new MandateResult($mandateId, MandateStatus::ISSUED);
    }
}
