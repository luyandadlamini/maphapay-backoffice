<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Domain\CardIssuance\ValueObjects\AuthorizationRequest;
use App\Domain\CardSubscriptions\Enums\CardErrorCode;
use App\Domain\CardSubscriptions\Enums\CardFeeType;
use App\Domain\CardSubscriptions\Enums\CardRiskEventStatus;
use App\Domain\CardSubscriptions\Enums\CardRiskSeverity;
use App\Domain\CardSubscriptions\Events\CardRiskEventOpened;
use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Domain\CardSubscriptions\Models\CardFee;
use App\Domain\CardSubscriptions\Models\CardRiskEvent;
use App\Domain\CardSubscriptions\Models\CardTransaction;
use App\Domain\CardSubscriptions\ValueObjects\RiskDecision;
use App\Models\User;
use Throwable;

class CardRiskService
{
    public function evaluateCardCreation(User $user): RiskDecision
    {
        $rating = strtolower((string) ($user->risk_rating ?? ''));

        if (in_array($rating, ['high', 'critical', 'prohibited'], true)) {
            return RiskDecision::deny(CardErrorCode::HIGH_RISK_USER, 'User risk rating blocks card operations.');
        }

        $replacementWindowStart = now()->subDays(30);
        $replacementCount = CardFee::query()
            ->where('user_id', $user->id)
            ->whereIn('fee_type', [
                CardFeeType::VirtualCardReplacement,
                CardFeeType::PhysicalCardReplacement,
            ])
            ->where('created_at', '>=', $replacementWindowStart)
            ->count();

        $blockAt = (int) config('cards.risk.replacements_30d_block_at', 3);

        if ($replacementCount >= $blockAt) {
            $this->recordEvent($user, null, 'velocity.replacements_30d', CardRiskSeverity::Medium, [
                'count' => $replacementCount,
            ]);

            return RiskDecision::deny(
                CardErrorCode::HIGH_RISK_TRANSACTION,
                'Too many card replacements in the last 30 days.',
            );
        }

        return RiskDecision::allow();
    }

    public function evaluateAuthorization(Card $card, AuthorizationRequest $req): RiskDecision
    {
        $owner = $card->user ?? User::query()->find($card->user_id);

        if ($owner === null) {
            return RiskDecision::allow();
        }

        $declines10m = CardTransaction::query()
            ->where('card_id', $card->id)
            ->where('status', 'declined')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        $deny10 = (int) config('cards.risk.declines_10min_deny_at', 6);

        if ($declines10m >= $deny10) {
            $this->recordEvent($owner, $card, 'velocity.declines_10min', CardRiskSeverity::High, [
                'count' => $declines10m,
            ]);

            return RiskDecision::deny(CardErrorCode::HIGH_RISK_TRANSACTION, 'Too many declined authorisations in 10 minutes.');
        }

        $declines24h = CardTransaction::query()
            ->where('card_id', $card->id)
            ->where('status', 'declined')
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        $deny24 = (int) config('cards.risk.declines_24h_deny_at', 11);

        if ($declines24h >= $deny24) {
            $this->recordEvent($owner, $card, 'velocity.declines_24h', CardRiskSeverity::High, [
                'count' => $declines24h,
            ]);

            return RiskDecision::deny(CardErrorCode::HIGH_RISK_TRANSACTION, 'Too many declined authorisations in 24 hours.');
        }

        $distinctMerchants = CardTransaction::query()
            ->where('card_id', $card->id)
            ->where('status', 'declined')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->pluck('merchant_name')
            ->unique()
            ->count();

        $merchantDenyAt = (int) config('cards.risk.distinct_declined_merchants_30min_deny_at', 3);

        if ($distinctMerchants >= $merchantDenyAt) {
            $this->recordEvent($owner, $card, 'velocity.distinct_merchants_declined_30min', CardRiskSeverity::High, [
                'count' => $distinctMerchants,
            ]);

            return RiskDecision::deny(CardErrorCode::HIGH_RISK_TRANSACTION, 'Unusual decline pattern across merchants.');
        }

        $disputeCount = CardDispute::query()
            ->where('user_id', $card->user_id)
            ->where('created_at', '>=', now()->subDays(60))
            ->count();

        $disputeReviewAt = (int) config('cards.risk.disputes_60d_review_at', 3);

        if ($disputeCount >= $disputeReviewAt) {
            $this->recordEvent($owner, $card, 'velocity.disputes_60d', CardRiskSeverity::Medium, [
                'count' => $disputeCount,
            ]);
        }

        if ($req->isAtmWithdrawal()) {
            $plan = $card->plan();

            if ($plan !== null && ! $plan->atm_enabled) {
                $this->recordEvent($owner, $card, 'attempt.atm_on_virtual', CardRiskSeverity::Medium, [
                    'plan_code' => $plan->code,
                ]);

                return RiskDecision::deny(CardErrorCode::ATM_NOT_ALLOWED, 'ATM withdrawals are not enabled for this plan.');
            }
        }

        $mcc = $req->normalizedMcc();

        if ($mcc !== null && $this->isMccBlocked($card, $mcc)) {
            $this->recordEvent($owner, $card, 'attempt.blocked_mcc', CardRiskSeverity::Medium, ['mcc' => $mcc]);

            return RiskDecision::deny(CardErrorCode::MCC_BLOCKED, 'This merchant category is blocked for your card.');
        }

        return RiskDecision::allow();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function recordEvent(User $user, ?Card $card, string $eventType, CardRiskSeverity $severity, array $metadata = []): CardRiskEvent
    {
        $tenantId = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : null;

        /** @var CardRiskEvent $row */
        $row = CardRiskEvent::create([
            'tenant_id'   => $tenantId,
            'user_id'     => (string) $user->id,
            'card_id'     => $card?->id,
            'event_type'  => $eventType,
            'severity'    => $severity,
            'description' => $eventType,
            'metadata'    => $metadata,
            'status'      => CardRiskEventStatus::Open,
        ]);

        event(new CardRiskEventOpened(
            riskEventId: (string) $row->id,
            userId:      (string) $user->id,
            cardId:      $card !== null ? (string) $card->id : null,
            eventType:   $eventType,
            severity:    $severity->value,
        ));

        return $row;
    }

    public function suspendCardsForUser(User $user, string $_reason): void
    {
        $provisioning = app(CardProvisioningService::class);

        Card::query()
            ->where('user_id', (string) $user->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(50, function ($cards) use ($provisioning): void {
                foreach ($cards as $issuedCard) {
                    /** @var Card $issuedCard */
                    try {
                        $provisioning->freezeCard($issuedCard->issuer_card_token);
                    } catch (Throwable) {
                        // Processor failure should not block freezing remaining cards.
                    }

                    $issuedCard->forceFill([
                        'status'    => 'frozen',
                        'frozen_at' => now(),
                    ])->save();
                }
            });
    }

    private function isMccBlocked(Card $card, string $mcc): bool
    {
        /** @var array<int, string> $global */
        $global = (array) config('cards.blocked_mccs', []);

        if (in_array($mcc, $global, true)) {
            return true;
        }

        /** @var array<string, mixed>|null $groups */
        $groups = $card->blocked_mcc_groups;

        if (! is_array($groups) || $groups === []) {
            return false;
        }

        foreach ($groups as $group) {
            if (is_array($group) && in_array($mcc, $group, true)) {
                return true;
            }
        }

        return false;
    }
}
