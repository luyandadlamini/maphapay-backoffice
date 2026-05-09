<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\AgentProtocol\Enums\KycVerificationStatus;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\ValueObjects\AuthorizationRequest;
use App\Domain\CardSubscriptions\Enums\CardErrorCode;
use App\Domain\CardSubscriptions\Enums\CardPlanEligibility;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\ValueObjects\EntitlementDecision;
use App\Models\User;
use Illuminate\Support\Carbon;

class CardEntitlementService
{
    /**
     * ATM MCC codes for detecting ATM transactions.
     *
     * @var array<string>
     */
    private const ATM_MCC_CODES = ['6011', '6010'];

    /**
     * Feature codes that are always available to any active user.
     *
     * @var array<string>
     */
    private const ALWAYS_ALLOWED_FEATURES = [
        'LOCAL_TRANSFER',
        'MERCHANT_QR_PAYMENT',
        'MERCHANT_ID_PAYMENT',
    ];

    /**
     * Feature codes that require an active or past-due subscription.
     *
     * @var array<string>
     */
    private const SUBSCRIPTION_REQUIRED_FEATURES = [
        'CREATE_VIRTUAL_CARD',
        'REQUEST_PHYSICAL_CARD',
        'VIEW_CARD_DETAILS',
        'CARD_ONLINE_SPEND',
        'CARD_INTERNATIONAL_SPEND',
        'CARD_POS_SPEND',
        'MANAGE_CARD_LIMITS',
        'CREATE_DISPUTE',
    ];

    /**
     * Determine whether a user may use a named feature.
     *
     * Feature codes:
     * - Always allowed (active users):     LOCAL_TRANSFER, MERCHANT_QR_PAYMENT, MERCHANT_ID_PAYMENT
     * - Require active subscription:       CREATE_VIRTUAL_CARD, REQUEST_PHYSICAL_CARD,
     *                                      VIEW_CARD_DETAILS, CARD_ONLINE_SPEND,
     *                                      CARD_INTERNATIONAL_SPEND, CARD_POS_SPEND,
     *                                      MANAGE_CARD_LIMITS, CREATE_DISPUTE
     * - Requires plan.atm_enabled:         ATM_WITHDRAWAL
     * - Unknown code:                      denied with PLAN_NOT_AVAILABLE (safe default)
     */
    public function canUseFeature(User $user, string $featureCode): EntitlementDecision
    {
        // All feature checks start with an active-account gate.
        if ($this->isUserNotActive($user)) {
            return EntitlementDecision::deny(CardErrorCode::USER_NOT_ACTIVE);
        }

        if (in_array($featureCode, self::ALWAYS_ALLOWED_FEATURES, true)) {
            return EntitlementDecision::allow();
        }

        if (in_array($featureCode, self::SUBSCRIPTION_REQUIRED_FEATURES, true)) {
            $subscription = $this->findActiveOrPastDueSubscription($user);

            if ($subscription === null) {
                return EntitlementDecision::deny(CardErrorCode::SUBSCRIPTION_REQUIRED);
            }

            return EntitlementDecision::allow();
        }

        if ($featureCode === 'ATM_WITHDRAWAL') {
            $subscription = $this->findActiveOrPastDueSubscription($user);

            if ($subscription === null) {
                return EntitlementDecision::deny(CardErrorCode::SUBSCRIPTION_REQUIRED);
            }

            $plan = $subscription->plan;

            if ($plan === null || ! $plan->atm_enabled) {
                return EntitlementDecision::deny(CardErrorCode::PLAN_DOES_NOT_ALLOW_ATM);
            }

            return EntitlementDecision::allow();
        }

        // Unknown feature code — deny with the safest error to avoid silently
        // granting access to unrecognised capabilities.
        return EntitlementDecision::deny(
            CardErrorCode::PLAN_NOT_AVAILABLE,
            "Unknown feature code: {$featureCode}"
        );
    }

    /**
     * Determine whether a user may subscribe to the given plan.
     *
     * Decision order:
     * 1. User account not active          → USER_NOT_ACTIVE
     * 2. KYC not transact-eligible        → FULL_KYC_REQUIRED
     * 3. User risk level is high/critical  → HIGH_RISK_USER
     * 4. Plan does not exist or inactive  → PLAN_NOT_AVAILABLE
     * 5. Plan is minor-only but user is adult (or vice-versa)
     *                                     → PLAN_NOT_ELIGIBLE_FOR_USER
     * 6. User already has a non-cancelled subscription
     *                                     → DUPLICATE_SUBSCRIPTION
     * 7. → allow
     *
     * NOTE: Phase 4 will add guardian approval and wallet balance checks once
     *       CardSubscriptionService is implemented.
     */
    public function canSubscribeToPlan(User $user, string $planCode): EntitlementDecision
    {
        // 1. Account active gate.
        if ($this->isUserNotActive($user)) {
            return EntitlementDecision::deny(CardErrorCode::USER_NOT_ACTIVE);
        }

        // 2. KYC must allow transacting.
        if (! $this->userKycCanTransact($user)) {
            return EntitlementDecision::deny(CardErrorCode::FULL_KYC_REQUIRED);
        }

        // 3. High-risk users cannot subscribe.
        if ($this->isHighRiskUser($user)) {
            return EntitlementDecision::deny(CardErrorCode::HIGH_RISK_USER);
        }

        // 4. Plan must exist and be active in the global catalogue.
        $plan = CardPlan::where('code', $planCode)->where('active', true)->first();

        if ($plan === null) {
            return EntitlementDecision::deny(CardErrorCode::PLAN_NOT_AVAILABLE);
        }

        // 5. Eligibility: minor plan for adult user, or adult plan for minor user.
        $userIsMinor = $this->isMinorUser($user);

        if ($plan->eligibility === CardPlanEligibility::Minor && ! $userIsMinor) {
            return EntitlementDecision::deny(CardErrorCode::PLAN_NOT_ELIGIBLE_FOR_USER);
        }

        if ($plan->eligibility === CardPlanEligibility::Adult && $userIsMinor) {
            return EntitlementDecision::deny(CardErrorCode::PLAN_NOT_ELIGIBLE_FOR_USER);
        }

        // 6. User must not already hold an active subscription (any non-cancelled status).
        $hasExistingSubscription = CardSubscription::where('subscriber_user_id', $user->id)
            ->whereNotIn('status', [CardSubscriptionStatus::Cancelled->value])
            ->exists();

        if ($hasExistingSubscription) {
            return EntitlementDecision::deny(CardErrorCode::DUPLICATE_SUBSCRIPTION);
        }

        // TODO: Phase 4 — check guardian wallet balance before allowing a minor subscription.
        // TODO: Phase 4 — check MINOR_REQUEST_PENDING status once CardSubscriptionService exists.

        return EntitlementDecision::allow();
    }

    /**
     * Determine whether a user may create a new virtual card.
     *
     * Decision order:
     * 1. No active/past-due subscription  → SUBSCRIPTION_REQUIRED / SUBSCRIPTION_NOT_ACTIVE
     * 2. KYC not transact-eligible        → FULL_KYC_REQUIRED
     * 3. User risk level is high/critical  → HIGH_RISK_USER
     * 4. Plan disallows virtual cards     → PLAN_DOES_NOT_ALLOW_VIRTUAL_CARD
     * 5. At or above virtual card limit   → VIRTUAL_CARD_LIMIT_REACHED
     * 6. Monthly creation limit reached  → MONTHLY_CREATION_LIMIT_REACHED
     * 7. → allow
     */
    public function canCreateVirtualCard(User $user): EntitlementDecision
    {
        // 1. Must have a subscription.
        $subscription = $this->findActiveOrPastDueSubscription($user);

        if ($subscription === null) {
            // Differentiate: the user has a subscription but it's in a non-billable state
            // vs. having no subscription at all.
            $hasAnySubscription = CardSubscription::where('subscriber_user_id', $user->id)
                ->whereNotIn('status', [CardSubscriptionStatus::Cancelled->value])
                ->exists();

            return $hasAnySubscription
                ? EntitlementDecision::deny(CardErrorCode::SUBSCRIPTION_NOT_ACTIVE)
                : EntitlementDecision::deny(CardErrorCode::SUBSCRIPTION_REQUIRED);
        }

        // 2. KYC must allow transacting.
        if (! $this->userKycCanTransact($user)) {
            return EntitlementDecision::deny(CardErrorCode::FULL_KYC_REQUIRED);
        }

        // 3. High-risk users cannot create cards.
        if ($this->isHighRiskUser($user)) {
            return EntitlementDecision::deny(CardErrorCode::HIGH_RISK_USER);
        }

        $plan = $subscription->plan;

        // 4. Plan must permit virtual cards.
        if ($plan === null || $plan->max_virtual_cards === 0) {
            return EntitlementDecision::deny(CardErrorCode::PLAN_DOES_NOT_ALLOW_VIRTUAL_CARD);
        }

        // 5. Count active virtual cards against the plan limit.
        $activeVirtualCount = $subscription->cards()
            ->where('kind', 'virtual')
            ->whereNotIn('status', ['cancelled', 'terminated'])
            ->count();

        if ($activeVirtualCount >= $plan->max_virtual_cards) {
            return EntitlementDecision::deny(CardErrorCode::VIRTUAL_CARD_LIMIT_REACHED);
        }

        // 6. Count cards created this calendar month against the monthly creation limit.
        $monthStart = Carbon::now()->startOfMonth();
        $cardsThisMonth = $subscription->cards()
            ->where('created_at', '>=', $monthStart)
            ->count();

        if ($plan->monthly_card_creation_limit > 0 && $cardsThisMonth >= $plan->monthly_card_creation_limit) {
            return EntitlementDecision::deny(CardErrorCode::MONTHLY_CREATION_LIMIT_REACHED);
        }

        return EntitlementDecision::allow();
    }

    /**
     * Determine whether a user may request a new physical card.
     *
     * Decision order:
     * 1. No subscription with status=active (past_due excluded for physical) → SUBSCRIPTION_REQUIRED / SUBSCRIPTION_NOT_ACTIVE
     * 2. Plan disallows physical cards    → PLAN_DOES_NOT_ALLOW_PHYSICAL_CARD
     * 3. At or above physical card limit  → PHYSICAL_CARD_LIMIT_REACHED
     * 4. KYC + risk checks               → FULL_KYC_REQUIRED / HIGH_RISK_USER
     * 5. → allow
     */
    public function canRequestPhysicalCard(User $user): EntitlementDecision
    {
        // 1. Physical cards require a fully active subscription (past_due not sufficient).
        $subscription = CardSubscription::where('subscriber_user_id', $user->id)
            ->where('status', CardSubscriptionStatus::Active->value)
            ->with('plan')
            ->first();

        if ($subscription === null) {
            $hasAnySubscription = CardSubscription::where('subscriber_user_id', $user->id)
                ->whereNotIn('status', [CardSubscriptionStatus::Cancelled->value])
                ->exists();

            return $hasAnySubscription
                ? EntitlementDecision::deny(CardErrorCode::SUBSCRIPTION_NOT_ACTIVE)
                : EntitlementDecision::deny(CardErrorCode::SUBSCRIPTION_REQUIRED);
        }

        $plan = $subscription->plan;

        // 2. Plan must permit physical cards.
        if ($plan === null || $plan->max_physical_cards === 0) {
            return EntitlementDecision::deny(CardErrorCode::PLAN_DOES_NOT_ALLOW_PHYSICAL_CARD);
        }

        // 3. Count active physical cards against the plan limit.
        $activePhysicalCount = $subscription->cards()
            ->where('kind', 'physical')
            ->whereNotIn('status', ['cancelled', 'terminated'])
            ->count();

        if ($activePhysicalCount >= $plan->max_physical_cards) {
            return EntitlementDecision::deny(CardErrorCode::PHYSICAL_CARD_LIMIT_REACHED);
        }

        // 4. KYC must allow transacting.
        if (! $this->userKycCanTransact($user)) {
            return EntitlementDecision::deny(CardErrorCode::FULL_KYC_REQUIRED);
        }

        // 4b. High-risk users cannot request cards.
        if ($this->isHighRiskUser($user)) {
            return EntitlementDecision::deny(CardErrorCode::HIGH_RISK_USER);
        }

        return EntitlementDecision::allow();
    }

    /**
     * Determine whether a card may be authorized for a given transaction.
     *
     * Decision order:
     * 1. Card is not active               → CARD_NOT_ACTIVE
     * 2. Subscription is suspended/cancelled → SUBSCRIPTION_INACTIVE
     * 3. ATM transaction on ATM-disabled card → ATM_NOT_ALLOWED
     * 4. Amount exceeds per-transaction limit → PER_TRANSACTION_LIMIT_EXCEEDED
     * 5. → allow
     *
     * NOTE: Daily/monthly limit, international, and MCC checks are Phase 9
     *       risk-service territory — TODO comments mark their future positions.
     */
    public function canAuthorize(Card $card, AuthorizationRequest $authorization): EntitlementDecision
    {
        // 1. Card must be active.
        if ($card->status !== 'active') {
            return EntitlementDecision::deny(CardErrorCode::CARD_NOT_ACTIVE);
        }

        // 2. Subscription must not be suspended or cancelled.
        $subscription = $card->card_subscription_id !== null
            ? CardSubscription::where('id', $card->card_subscription_id)->first()
            : null;

        if ($subscription === null) {
            return EntitlementDecision::deny(CardErrorCode::SUBSCRIPTION_INACTIVE);
        }

        if (in_array($subscription->status, [
            CardSubscriptionStatus::Suspended,
            CardSubscriptionStatus::Cancelled,
        ], true)) {
            return EntitlementDecision::deny(CardErrorCode::SUBSCRIPTION_INACTIVE);
        }

        // 3. ATM transaction check: category string 'ATM' or MCC 6011/6010.
        $isAtmTransaction = strtoupper($authorization->merchantCategory) === 'ATM'
            || in_array($authorization->merchantCategory, self::ATM_MCC_CODES, true);

        if ($isAtmTransaction && ! $card->atm_enabled) {
            return EntitlementDecision::deny(CardErrorCode::ATM_NOT_ALLOWED);
        }

        // 4. Per-transaction limit check.
        // Card stores per_transaction_limit as a decimal (currency units, not cents).
        // Authorization amount is in cents; convert before comparing.
        if ($card->per_transaction_limit !== null) {
            $limitCents = (int) round((float) $card->per_transaction_limit * 100);

            if ($authorization->amountCents > $limitCents) {
                return EntitlementDecision::deny(CardErrorCode::PER_TRANSACTION_LIMIT_EXCEEDED);
            }
        }

        // TODO: Phase 9 — check daily_limit against rolling daily spend via CardTransactionService.
        // TODO: Phase 9 — check monthly_limit against rolling monthly spend via CardTransactionService.
        // TODO: Phase 9 — check international_enabled when merchantCountry differs from card's home country.
        // TODO: Phase 9 — check online_enabled for e-commerce / CNP transactions (MCC or channel flag).
        // TODO: Phase 9 — check blocked_mcc_groups against authorization->merchantCategory.
        // TODO: Phase 9 — route high-risk transactions to CardRiskService for final decision.

        return EntitlementDecision::allow();
    }

    /**
     * Determine whether a user may reveal the sensitive details of a card.
     *
     * Decision order:
     * 1. Card does not belong to user     → CARD_NOT_FOUND
     * 2. Card is in a non-revealable state → CARD_NOT_ACTIVE
     * 3. Subscription is suspended/cancelled → SUBSCRIPTION_INACTIVE
     * 4. → allow
     */
    public function canRevealCard(User $user, Card $card): EntitlementDecision
    {
        // 1. Ownership: card must belong to the requesting user.
        if ((string) $card->user_id !== (string) $user->id) {
            return EntitlementDecision::deny(CardErrorCode::CARD_NOT_FOUND);
        }

        // 2. Card must be in a state that permits revealing PAN/CVV.
        $revealableStatuses = ['active', 'frozen_by_user', 'frozen_by_admin'];

        if (! in_array($card->status, $revealableStatuses, true)) {
            return EntitlementDecision::deny(CardErrorCode::CARD_NOT_ACTIVE);
        }

        // 3. Subscription must not be suspended or cancelled.
        $subscription = $card->card_subscription_id !== null
            ? CardSubscription::where('id', $card->card_subscription_id)->first()
            : null;

        if ($subscription === null) {
            return EntitlementDecision::deny(CardErrorCode::SUBSCRIPTION_INACTIVE);
        }

        if (in_array($subscription->status, [
            CardSubscriptionStatus::Suspended,
            CardSubscriptionStatus::Cancelled,
        ], true)) {
            return EntitlementDecision::deny(CardErrorCode::SUBSCRIPTION_INACTIVE);
        }

        return EntitlementDecision::allow();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return true if the user's account should be treated as inactive.
     *
     * TODO: The User model has no `account_status` column (as of Phase 3).
     *       The closest existing signal is `frozen_at` (set by admin freeze).
     *       When an `account_status` column is added to the users table, replace
     *       this implementation with: `return $user->account_status !== 'active'`.
     */
    private function isUserNotActive(User $user): bool
    {
        // A frozen account is not considered active for entitlement purposes.
        return $user->frozen_at !== null;
    }

    /**
     * Return true if the user's KYC status permits transacting.
     *
     * The `kyc_status` column is a plain string on User; this method tries to
     * parse it as a KycVerificationStatus enum before calling `canTransact()`.
     */
    private function userKycCanTransact(User $user): bool
    {
        /** @var string|null $rawStatus */
        $rawStatus = $user->kyc_status ?? null;

        if ($rawStatus === null) {
            return false;
        }

        $status = KycVerificationStatus::tryFrom($rawStatus);

        if ($status === null) {
            return false;
        }

        return $status->canTransact();
    }

    /**
     * Return true if the user's risk rating is high or critical.
     *
     * TODO: The User model has `risk_rating` in $fillable but no column-level
     *       enum enforcement. The expected values for a high-risk block are
     *       'high' and 'critical'. When a formal Risk domain type is introduced,
     *       replace this check with the appropriate domain enum comparison.
     */
    private function isHighRiskUser(User $user): bool
    {
        /** @var string|null $riskRating */
        $riskRating = $user->risk_rating ?? null;

        return in_array($riskRating, ['high', 'critical'], true);
    }

    /**
     * Return true if the user is a minor account holder.
     *
     * TODO: The User model has no `account_type` column as of Phase 3.
     *       Minor status is tracked on AccountMembership.account_type = 'minor',
     *       not directly on the User row.  When a denormalised `is_minor` flag
     *       or `account_type` column is added to users, replace the body below.
     *
     * Current behaviour: conservatively returns false so adult plans are not
     * incorrectly blocked.  A dedicated MinorCardSubscriptionService already
     * handles the minor-specific flow and guards this path separately.
     */
    private function isMinorUser(User $user): bool
    {
        // TODO: Phase 4 — resolve minor status from AccountMembership when the
        //       request context (account_type attribute) is available here.
        //       For now, check account_type if it exists as a dynamic attribute;
        //       otherwise fall back to false.
        /** @var string|null $accountType */
        $accountType = $user->getAttributes()['account_type'] ?? null;

        return $accountType === 'minor';
    }

    /**
     * Find the user's subscription if it is in an active or past-due state.
     *
     * Both statuses are considered "billable" and permit card creation /
     * feature usage, matching the intent of the grace-period model.
     */
    private function findActiveOrPastDueSubscription(User $user): ?CardSubscription
    {
        /** @var CardSubscription|null */
        return CardSubscription::where('subscriber_user_id', $user->id)
            ->whereIn('status', [
                CardSubscriptionStatus::Active->value,
                CardSubscriptionStatus::PastDue->value,
            ])
            ->with('plan')
            ->first();
    }
}
