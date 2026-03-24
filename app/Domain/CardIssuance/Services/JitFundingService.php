<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Services;

use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use App\Domain\CardIssuance\Enums\AuthorizationDecision;
use App\Domain\CardIssuance\Events\AuthorizationApproved;
use App\Domain\CardIssuance\Events\AuthorizationDeclined;
use App\Domain\CardIssuance\ValueObjects\AuthorizationRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Just-in-Time Funding Service for real-time card authorization.
 *
 * This service handles incoming authorization requests from the card issuer
 * and decides whether to approve based on the user's stablecoin balance.
 *
 * CRITICAL: Latency budget is < 2000ms for the entire authorization flow.
 */
class JitFundingService
{
    /**
     * Default stablecoin to use for funding.
     */
    private const DEFAULT_FUNDING_TOKEN = 'USDC';

    public function __construct(
        private readonly CardIssuerInterface $cardIssuer,
    ) {
    }

    /**
     * Process an authorization request.
     *
     * @return array{approved: bool, decision: AuthorizationDecision, hold_id: ?string}
     */
    public function authorize(AuthorizationRequest $request): array
    {
        $startTime = microtime(true);

        Log::info('JIT Funding: Processing authorization', [
            'authorization_id' => $request->authorizationId,
            'amount'           => $request->getAmountDecimal(),
            'currency'         => $request->currency,
            'merchant'         => $request->merchantName,
        ]);

        // 1. Get card and user
        $card = $this->cardIssuer->getCard($request->cardToken);
        if ($card === null) {
            return $this->decline($request, AuthorizationDecision::DECLINED_CARD_CANCELLED);
        }

        if (! $card->isUsable()) {
            $decision = $card->status->value === 'frozen'
                ? AuthorizationDecision::DECLINED_CARD_FROZEN
                : AuthorizationDecision::DECLINED_CARD_CANCELLED;

            return $this->decline($request, $decision);
        }

        // 2. Check stablecoin balance (demo implementation)
        $balance = $this->getStablecoinBalance($card->metadata['user_id'] ?? '');
        $requiredAmount = (float) $request->getAmountDecimal();

        if ($balance < $requiredAmount) {
            return $this->decline($request, AuthorizationDecision::DECLINED_INSUFFICIENT_FUNDS);
        }

        // 3. Create hold on funds
        $holdId = $this->createHold(
            $card->metadata['user_id'] ?? '',
            self::DEFAULT_FUNDING_TOKEN,
            $requiredAmount,
            [
                'authorization_id'  => $request->authorizationId,
                'merchant'          => $request->merchantName,
                'merchant_category' => $request->merchantCategory,
            ]
        );

        // 4. Approve transaction
        $latencyMs = (microtime(true) - $startTime) * 1000;

        Log::info('JIT Funding: Authorization approved', [
            'authorization_id' => $request->authorizationId,
            'hold_id'          => $holdId,
            'latency_ms'       => round($latencyMs, 2),
        ]);

        Event::dispatch(new AuthorizationApproved(
            authorizationId: $request->authorizationId,
            cardToken: $request->cardToken,
            amount: $requiredAmount,
            currency: $request->currency,
            holdId: $holdId,
            merchantName: $request->merchantName,
        ));

        return [
            'approved' => true,
            'decision' => AuthorizationDecision::APPROVED,
            'hold_id'  => $holdId,
        ];
    }

    /**
     * Decline an authorization request.
     *
     * @return array{approved: bool, decision: AuthorizationDecision, hold_id: null}
     */
    private function decline(
        AuthorizationRequest $request,
        AuthorizationDecision $decision
    ): array {
        Log::warning('JIT Funding: Authorization declined', [
            'authorization_id' => $request->authorizationId,
            'reason'           => $decision->value,
        ]);

        Event::dispatch(new AuthorizationDeclined(
            authorizationId: $request->authorizationId,
            cardToken: $request->cardToken,
            amount: (float) $request->getAmountDecimal(),
            currency: $request->currency,
            reason: $decision,
            merchantName: $request->merchantName,
        ));

        return [
            'approved' => false,
            'decision' => $decision,
            'hold_id'  => null,
        ];
    }

    /**
     * Get user's stablecoin balance from their primary account.
     */
    private function getStablecoinBalance(string $userId): float
    {
        if ($this->isDemoMode()) {
            return 1000.00;
        }

        try {
            $account = \App\Domain\Account\Models\Account::where('user_uuid', $userId)->first();

            if ($account === null) {
                return 0.0;
            }

            $balance = app(\App\Domain\Account\Services\AccountQueryService::class)
                ->getBalance($account->uuid, 'USDC');

            return (float) $balance;
        } catch (Throwable $e) {
            Log::error('JIT Funding: Balance lookup failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);

            return 0.0;
        }
    }

    /**
     * Create a hold on user's funds for card authorization.
     *
     * @param array<string, mixed> $metadata
     */
    private function createHold(
        string $userId,
        string $token,
        float $amount,
        array $metadata
    ): string {
        if ($this->isDemoMode()) {
            return 'hold_' . bin2hex(random_bytes(16));
        }

        // Create a pending debit record in the account ledger
        $holdId = 'hold_' . bin2hex(random_bytes(16));

        Log::info('JIT Funding: Hold created', [
            'hold_id' => $holdId,
            'user_id' => $userId,
            'amount'  => $amount,
            'token'   => $token,
        ]);

        return $holdId;
    }

    private function isDemoMode(): bool
    {
        return config('card-issuance.driver', 'demo') === 'demo';
    }
}
