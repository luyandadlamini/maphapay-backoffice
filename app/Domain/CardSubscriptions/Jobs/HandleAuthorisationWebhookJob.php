<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Jobs;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\ValueObjects\AuthorizationRequest;
use App\Domain\CardSubscriptions\Services\CardEntitlementService;
use App\Domain\CardSubscriptions\Services\CardFeeService;
use App\Domain\CardSubscriptions\Services\CardRiskService;
use App\Domain\Shared\Money\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HandleAuthorisationWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $processor,
        public readonly array $payload
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(
        CardEntitlementService $entitlementService,
        CardRiskService $riskService,
        CardFeeService $feeService
    ): void {
        DB::transaction(function () use ($entitlementService, $riskService, $feeService) {
            if (empty($this->payload['card_token'])) {
                Log::warning('Authorisation webhook missing card_token.', ['payload' => $this->payload]);

                return;
            }

            $card = Card::where('issuer_card_token', $this->payload['card_token'])->lockForUpdate()->first();

            if (! $card) {
                Log::warning("Card not found for token: {$this->payload['card_token']}");

                return;
            }

            $authReq = AuthorizationRequest::fromWebhook($this->payload);

            // 1. Entitlement check (subscription, limits, controls)
            $entitlement = $entitlementService->canAuthorize($card, $authReq);
            if (! $entitlement->allowed) {
                $this->respondToProcessor(decline: $entitlement->code->value ?? 'insufficient_funds');

                return;
            }

            // 2. Risk check
            $risk = $riskService->evaluateAuthorization($card, $authReq);
            if (! $risk->allowed) {
                $this->respondToProcessor(decline: $risk->code->value ?? 'suspected_fraud');

                return;
            }

            // 3. Calculate fees (FX, ATM)
            $plan = $card->plan();
            if ($plan === null) {
                $this->respondToProcessor(decline: 'no_plan');

                return;
            }

            $billingAmount = new Money((string) $authReq->amountCents, 'SZL');
            $fxFee = $feeService->calculateFxFee($plan, $authReq->currency, $billingAmount);

            // TODO: Place hold on wallet, record transaction, etc.
            Log::info("Authorisation successful for card {$card->id}", [
                'amount' => $billingAmount->amount,
                'fx_fee' => $fxFee->amount,
            ]);

            $this->respondToProcessor(decline: null);
        });
    }

    private function respondToProcessor(?string $decline): void
    {
        // Emit processor-specific response or log it
        if ($decline) {
            Log::info("Declining authorisation for {$this->processor}: {$decline}", ['event_id' => $this->payload['event_id'] ?? null]);
        } else {
            Log::info("Approving authorisation for {$this->processor}", ['event_id' => $this->payload['event_id'] ?? null]);
        }
    }
}
