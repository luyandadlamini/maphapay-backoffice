# 05 — Services and Business Rules

Every service class in `app/Domain/CardSubscriptions/Services/` is specified here: method signatures, decision rules, error returns, ledger postings.

References:
- Enums and codes: [`CONTRACT.md`](./CONTRACT.md)
- Plan numbers: [`01-product-config.md`](./01-product-config.md)
- API shapes: [`04-api-contract.md`](./04-api-contract.md)
- Migrations: [`03-database-schema.md`](./03-database-schema.md)

All money types are `App\Domain\Shared\Money\Money` value objects; conversion via `MoneyConverter::forAsset()` (existing). All UUIDs are strings.

---

## 1. CardEntitlementService

Single decision authority for "can this user use this feature."

```php
namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardSubscriptions\Enums\CardErrorCode;
use App\Domain\CardSubscriptions\ValueObjects\EntitlementDecision;
use App\Models\User;

class CardEntitlementService
{
    public function canUseFeature(User $user, string $featureCode): EntitlementDecision;

    public function canSubscribeToPlan(User $user, string $planCode): EntitlementDecision;

    public function canCreateVirtualCard(User $user): EntitlementDecision;

    public function canRequestPhysicalCard(User $user): EntitlementDecision;

    public function canAuthorize(Card $card, AuthorizationRequest $authorization): EntitlementDecision;

    public function canRevealCard(User $user, Card $card): EntitlementDecision;
}
```

`EntitlementDecision` is a value object: `{ bool allowed, ?CardErrorCode code, ?string message }`. NO booleans returned directly — every "no" carries the reason.

### Feature codes

```
LOCAL_TRANSFER, MERCHANT_QR_PAYMENT, MERCHANT_ID_PAYMENT     -- always allowed for active users
CREATE_VIRTUAL_CARD, REQUEST_PHYSICAL_CARD                   -- require active subscription
VIEW_CARD_DETAILS, CARD_ONLINE_SPEND, CARD_INTERNATIONAL_SPEND, CARD_POS_SPEND
ATM_WITHDRAWAL                                               -- requires plan.atm_enabled
MANAGE_CARD_LIMITS, CREATE_DISPUTE
PRIORITY_SUPPORT
GUARDIAN_APPROVE_MINOR                                       -- requires guardian-of relation
```

### Decision rules

For each method, evaluate in this order; return the FIRST matching decline:

#### `canSubscribeToPlan(user, planCode)`

1. `user.account_status !== 'active'` → `USER_NOT_ACTIVE`
2. `KycVerificationStatus::canTransact(user.kyc_status)` is false → `FULL_KYC_REQUIRED`
3. `user.risk_level ∈ {high, critical}` → `HIGH_RISK_USER`
4. `plan = CardPlan::find($planCode)`; `plan.active === false` → `PLAN_NOT_AVAILABLE`
5. `plan.eligibility === 'minor'` AND `user is not a Khula-tier minor (13–17)` → `PLAN_NOT_ELIGIBLE_FOR_USER`
6. `plan.eligibility === 'adult'` AND `user is a minor` → `PLAN_NOT_ELIGIBLE_FOR_USER`
7. `user has any non-cancelled subscription` → `DUPLICATE_SUBSCRIPTION`
8. If minor plan: `user has pending minor_card_request of type subscribe` → `MINOR_REQUEST_PENDING`
9. `payerWallet.available_balance < plan.monthly_fee` → `INSUFFICIENT_WALLET_BALANCE`
   (For minor plans, payer is guardian; check guardian wallet.)
10. Otherwise: allowed.

#### `canCreateVirtualCard(user)`

1. Subscription exists AND `status === 'active'` (or `past_due` within grace) → continue, else `SUBSCRIPTION_REQUIRED` / `SUBSCRIPTION_NOT_ACTIVE`
2. `KycVerificationStatus::canTransact()` true → continue, else `FULL_KYC_REQUIRED`
3. `user.risk_level ∉ {high, critical}` → continue, else `HIGH_RISK_USER`
4. `plan.max_virtual_cards > 0` → continue, else `PLAN_DOES_NOT_ALLOW_VIRTUAL_CARD`
5. `count(active virtual cards) < plan.max_virtual_cards` → continue, else `VIRTUAL_CARD_LIMIT_REACHED`
6. `count(card creations this month) < plan.monthly_card_creation_limit` → continue, else `MONTHLY_CREATION_LIMIT_REACHED`
7. Allowed.

#### `canRequestPhysicalCard(user)`

1. Subscription `active` (NOT `past_due`) → continue
2. `plan.max_physical_cards > 0` → continue, else `PLAN_DOES_NOT_ALLOW_PHYSICAL_CARD`
3. `count(active physical cards) < plan.max_physical_cards` → continue, else `PHYSICAL_CARD_LIMIT_REACHED`
4. KYC + risk + status checks (same as virtual)
5. Allowed.

#### `canAuthorize(card, authorization)`

1. `card.status !== 'active'` → `CARD_NOT_ACTIVE`
2. `subscription.status === 'suspended'` OR `subscription.status === 'cancelled'` → `SUBSCRIPTION_INACTIVE`
3. ATM transaction AND `plan.atm_enabled === false` → `ATM_NOT_ALLOWED`
4. `authorization.amount > card.per_transaction_limit` → `PER_TRANSACTION_LIMIT_EXCEEDED`
5. `daily_spent + amount > card.daily_limit` → `DAILY_LIMIT_EXCEEDED`
6. `monthly_spent + amount > card.monthly_limit` → `MONTHLY_LIMIT_EXCEEDED`
7. ATM AND ATM-daily exceeded → `ATM_LIMIT_EXCEEDED`
8. International AND `card.international_enabled === false` → `INTERNATIONAL_DISABLED`
9. Online (ecommerce MCC) AND `card.online_enabled === false` → `ONLINE_DISABLED`
10. MCC ∈ blocked groups → `MCC_BLOCKED`
11. Risk service returns critical → `HIGH_RISK_TRANSACTION`
12. Wallet balance insufficient for total (amount + fees) → `INSUFFICIENT_FUNDS`
13. If card has `minor_account_uuid` set → also enforce `minor_card_limits` (existing logic) → on breach: `MINOR_CARD_LIMIT_EXCEEDED`
14. Allowed.

This method is called from `JitFundingService::evaluate()` BEFORE the existing funds check.

---

## 2. CardSubscriptionService

Owns subscription lifecycle. Every public method is transactional.

```php
public function subscribe(User $subscriber, string $planCode, ?User $payer = null, ?string $minorRequestId = null): CardSubscription;

public function upgrade(User $subscriber, string $newPlanCode): CardSubscription;

public function downgrade(User $subscriber, string $newPlanCode, bool $force = false): CardSubscription;

public function cancel(User $subscriber): CardSubscription;

public function getCurrent(User $subscriber): ?CardSubscription;

public function markPastDue(CardSubscription $subscription, string $failureReason): void;
public function suspend(CardSubscription $subscription): void;
public function restore(CardSubscription $subscription): void;
public function terminateUnpaid(CardSubscription $subscription): void;
```

### `subscribe`

1. `entitlements.canSubscribeToPlan($subscriber, $planCode)` → throw on decline.
2. `payer = $payer ?? $subscriber`.
3. For minor plans: require `$minorRequestId`; verify the request is `approved` and points at this user/plan.
4. Open transaction:
   - Insert `card_subscriptions` row with `status = 'active'`, `current_period_start = now`, `current_period_end = now + 1 month`, `next_billing_date = now + 1 month`.
   - Call `CardBillingService::chargeInitialPeriod($subscription)` — debits payer wallet immediately for the first month.
   - On success → audit log `subscription.created` + dispatch `CardSubscriptionActivated` event.
5. Return.

### `upgrade` / `downgrade`

1. Validate target plan eligibility.
2. Calculate prorated charge or credit:
   - If upgrading: charge difference for remaining days in current period.
   - If downgrading: credit difference. Credit becomes a wallet balance increase via `LedgerPostingService::post()` to the payer.
3. Update subscription row: `card_plan_id`, no period change.
4. For downgrade where `count(active virtual cards) > new_plan.max_virtual_cards`:
   - If `force = true`: auto-freeze excess cards (latest-created first), audit each freeze.
   - If `force = false`: throw with `data.code = "DOWNGRADE_REQUIRES_CARD_REDUCTION"` and the count of cards to close.
5. Audit + dispatch `CardSubscriptionPlanChanged`.

### `cancel`

1. `subscription.status` → `cancelled`. `cancelled_at = now`.
2. NO immediate card cancellation — cards remain active until `current_period_end`. A scheduled job (see [`07-jobs-and-events.md`](./07-jobs-and-events.md) §3) closes them on expiry.
3. NO refund of current month.
4. Audit `subscription.cancelled` + dispatch `CardSubscriptionCancelled`.

### State transitions

Only the following are valid (enforced in service, NOT in DB):

```
active        ↔ past_due ↔ suspended → cancelled
active        → cancelled
past_due      → active (on payment) | suspended (on grace expiry)
suspended     → active (on payment) | cancelled (on day-14 unpaid)
```

`pending_guardian_approval` is set by `MinorCardSubscriptionService` and transitions to `active` on approval.

---

## 3. CardBillingService

Owns wallet debits for monthly fees, retries, grace, suspension.

```php
public function chargeInitialPeriod(CardSubscription $subscription): BillingAttemptResult;
public function billRenewal(CardSubscription $subscription): BillingAttemptResult;
public function retryFailedPayment(CardSubscription $subscription): BillingAttemptResult;
public function handleSuccessfulPayment(CardSubscription $subscription, BillingAttemptResult $result): void;
public function handleFailedPayment(CardSubscription $subscription, BillingAttemptResult $result): void;
```

### `billRenewal`

1. `idempotencyKey = sha1("renewal:{$subscription->id}:{$subscription->next_billing_date}")` — stable, so a duplicate run does nothing.
2. Lookup payer wallet via `WalletService::getWallet($subscription->payer_user_id)`.
3. If `wallet.available_balance < plan.monthly_fee` → `BillingAttemptResult { result: 'failed', reason: 'INSUFFICIENT_FUNDS' }`. Persist a `card_subscription_billing_attempts` row. Call `handleFailedPayment`.
4. Otherwise: `LedgerPostingService::post()` with two entries:

   ```
   Debit  payer.wallet_account              SZL  monthly_fee
   Credit maphapay.card_revenue_account     SZL  monthly_fee
   ```

   Persist a `card_fees` row with `fee_type='subscription'`, `status='charged'`, `ledger_posting_id` set, `charged_at=now`.

5. Persist `card_subscription_billing_attempts` row with `result='success'`, `ledger_posting_id`, `attempted_at=now`.
6. Call `handleSuccessfulPayment`.
7. Return result.

### `handleSuccessfulPayment`

1. `subscription.current_period_start = subscription.next_billing_date`.
2. `subscription.current_period_end = subscription.next_billing_date + 1 month`.
3. `subscription.next_billing_date = subscription.next_billing_date + 1 month`.
4. `subscription.failed_payment_count = 0`. `grace_period_ends_at = null`.
5. If `subscription.status === 'past_due'`: → `'active'`.
6. If `subscription.status === 'suspended'`: → `'active'`. Also restore cards that were `suspended` due to billing (NOT `frozen_by_user`, NOT `frozen_by_admin`).
7. Audit `subscription.billing_succeeded`.
8. Dispatch `CardSubscriptionRestored` event (if was past_due/suspended).
9. Push notification: `subscription_payment_success`.

### `handleFailedPayment`

1. `failed_payment_count += 1`.
2. If currently `active`: → `past_due`. `grace_period_ends_at = now + 3 days`.
3. If `past_due` with grace expired: → `suspended`. Suspend all `active` cards under this subscription (status `suspended`).
4. If `suspended` for ≥ 14 days: → `cancelled`. Cancel cards via `CardLifecycleService::cancelCard()`.
5. Audit `subscription.billing_failed`.
6. Dispatch `CardSubscriptionPastDue` / `CardSubscriptionSuspended` / `CardSubscriptionCancelled` accordingly.
7. Push notification: `subscription_payment_failed` (or follow-up notice).

The wallet is **never** modified by this method — only subscription/card status.

---

## 4. CardFeeService

Owns FX, ATM, issuance, replacement, and one-off fees.

```php
public function previewTransaction(User $user, CardFeePreviewInput $input): CardFeePreview;

public function calculateFxFee(CardPlan $plan, string $currency, Money $billingAmount): Money;
public function calculateAtmFee(CardPlan $plan, Money $withdrawalAmount): Money;

public function chargeIssuanceFee(User $payer, PhysicalCardOrder $order): CardFee;
public function chargeReplacementFee(User $payer, Card $card, ReplacementReason $reason): CardFee;
public function chargeVirtualReplacementFee(User $payer, Card $card): ?CardFee;     // null if free reissue allowance available
public function chargeChargebackAbuseFee(User $user, CardDispute $dispute): CardFee;

public function waiveFee(CardFee $fee, string $reason, User $admin): CardFee;
public function refundFee(CardFee $fee, string $reason, User $admin): CardFee;
```

### `calculateFxFee`

```php
if (in_array($currency, ['SZL', 'ZAR'], true)) {
    return Money::zero('SZL');
}
return $billingAmount->multiplyBps($plan->fx_markup_bps);
// Money has multiplyBps(int) helper that does (amount * bps / 10000) with bcmath
```

### `calculateAtmFee`

```php
$fixed = Money::fromMajorString((string) $plan->atm_fixed_fee, 'SZL');
$percentage = $withdrawalAmount->multiplyBps($plan->atm_percentage_fee_bps);
return $fixed->add($percentage);
```

### `chargeReplacementFee` rules

```php
$reason = ReplacementReason::from($reasonCode);

return match (true) {
    $reason === ReplacementReason::EXPIRED => null,                                 // free
    $reason === ReplacementReason::FRAUD => null,                                   // free, requires admin confirmation
    $card->kind === CardKind::PHYSICAL  => $this->postFee($payer, 'physical_card_replacement', $plan->physical_card_replacement_fee, $card),
    $card->kind === CardKind::VIRTUAL  => $this->chargeVirtualReplacementFee($payer, $card),
};
```

### `chargeVirtualReplacementFee` rules

1. Count this user's virtual replacements this calendar month.
2. If `count < plan.free_virtual_reissues_per_month`: return `null` (free).
3. Otherwise: post fee `plan.virtual_card_replacement_fee`.

All fee charges go through `LedgerPostingService::post()` (debit payer wallet, credit MaphaPay revenue account) and create a `card_fees` row.

### `previewTransaction`

Computes FX + ATM + issuance + replacement fees for a hypothetical transaction. Pure function (no DB writes). Used by `/v1/card-fees/preview`.

---

## 5. CardLifecycleService

Wraps `CardIssuance::CardProvisioningService` with entitlement + audit + risk.

```php
public function createVirtualCard(User $user, CardSubscription $subscription, CreateVirtualCardInput $input): Card;
public function freezeCard(User $actor, Card $card, string $reason): Card;
public function unfreezeCard(User $actor, Card $card): Card;
public function cancelCard(User $actor, Card $card, string $reason): Card;
public function replaceCard(User $actor, Card $card, ReplacementReason $reason): Card;
public function updateControls(User $actor, Card $card, CardControlsInput $controls): Card;
public function adminFreeze(Admin $admin, Card $card, string $reason): Card;
public function adminUnfreeze(Admin $admin, Card $card, string $reason): Card;
```

### `createVirtualCard`

1. `entitlements.canCreateVirtualCard($user)` → throw on decline.
2. `risk = riskService.evaluateCardCreation($user)`.
3. If risk `critical`: throw `HIGH_RISK_USER`.
4. `controls = clampControls($input->controls, $subscription->plan)` — no field exceeds plan ceiling.
5. Open transaction:
   - Call `CardProvisioningService::createVirtualCard($user, $subscription, $controls)` (CardIssuance domain).
   - Provisioning service writes the row in `cards` and calls the processor.
   - If processor fails: throw `PROCESSOR_CARD_CREATION_FAILED`.
6. Audit `card.created` with before=null, after=card snapshot.
7. Dispatch `CardProvisioned` event (existing) AND `CardLifecycleAuditEmitted` (new).
8. Return Card.

### `freezeCard`

1. `card.user_id === actor.id` (authorization check).
2. `card.status === 'active'` → continue, else throw.
3. Call `CardProvisioningService::freeze($card, actorType: 'user')` — sets `frozen_by_user`, calls processor.
4. Audit `card.frozen_by_user`.
5. Dispatch event.

### `unfreezeCard`

1. `card.user_id === actor.id`.
2. `card.status === 'frozen_by_user'` → continue, else throw `ADMIN_FROZEN_CARD` if frozen_by_admin.
3. Subscription is `active`. If `suspended`: throw `SUBSCRIPTION_INACTIVE`.
4. Risk check: if `user.risk_level ∈ {high, critical}`: throw `HIGH_RISK_USER`.
5. Call `CardProvisioningService::unfreeze($card)`.
6. Audit + event.

### `adminFreeze`

Caller is an admin via Filament. Sets `frozen_by_admin`. User cannot unfreeze. Audit logs the admin user as `actor_type='admin'`.

### `replaceCard`

1. Authorization (user owns card).
2. Compute fee via `CardFeeService::chargeReplacementFee()` — may be free or paid.
3. If paid and wallet balance insufficient: throw `INSUFFICIENT_WALLET_BALANCE` BEFORE issuing the new card.
4. Call processor to create replacement card.
5. Old card → `replaced`. New card linked via `metadata.replaces_card_id`.
6. Audit + event.

---

## 6. CardRiskService

```php
public function evaluateCardCreation(User $user): RiskDecision;
public function evaluateAuthorization(Card $card, AuthorizationRequest $req): RiskDecision;
public function recordEvent(User $user, ?Card $card, string $eventType, RiskSeverity $severity, array $metadata = []): CardRiskEvent;
public function suspendCardsForUser(User $user, string $reason): void;
```

### Velocity rules (from `01-product-config.md` §9 + `config('cards.risk')`):

```php
public function evaluateAuthorization(Card $card, AuthorizationRequest $req): RiskDecision
{
    $declines10min = CardTransaction::where('card_id', $card->id)
        ->where('status', 'declined')
        ->where('created_at', '>=', now()->subMinutes(10))
        ->count();
    if ($declines10min >= config('cards.risk.declined_in_10min_threshold')) {
        $this->recordEvent($card->user, $card, 'velocity.declines_10min', RiskSeverity::HIGH, ['count' => $declines10min]);
        return RiskDecision::deny('HIGH_RISK_TRANSACTION');
    }

    $declines24h = ... ; // similar
    if ($declines24h >= config('cards.risk.declined_in_24h_threshold')) {
        $this->recordEvent($card->user, $card, 'velocity.declines_24h', RiskSeverity::HIGH, ...);
        return RiskDecision::deny('HIGH_RISK_TRANSACTION');
    }

    if ($req->isAtm() && !$card->plan()->atm_enabled) {
        $this->recordEvent($card->user, $card, 'attempt.atm_on_virtual', RiskSeverity::MEDIUM, ...);
        return RiskDecision::deny('ATM_NOT_ALLOWED');
    }

    if ($this->mccBlocked($card, $req->mcc)) {
        $this->recordEvent($card->user, $card, 'attempt.blocked_mcc', RiskSeverity::MEDIUM, ['mcc' => $req->mcc]);
        return RiskDecision::deny('MCC_BLOCKED');
    }

    return RiskDecision::allow();
}
```

`recordEvent` writes to `card_risk_events`. A listener `ApplyRiskFreezeOnCriticalEvent` handles `critical` severity by calling `suspendCardsForUser`.

---

## 7. CardAuditService

Append-only writes to `card_audit_logs`.

```php
public function record(
    string $actorType,
    ?string $actorId,
    string $action,
    string $entityType,
    ?string $entityId,
    ?array $beforeState,
    ?array $afterState,
    array $metadata = [],
): CardAuditLog;
```

Convenience helpers for common events:

```php
public function recordSubscriptionEvent(string $action, CardSubscription $sub, ?array $before, array $metadata = []): CardAuditLog;
public function recordCardEvent(string $action, Card $card, ?array $before, array $metadata = []): CardAuditLog;
public function recordAdminAction(Admin $admin, string $action, string $entityType, string $entityId, array $metadata = []): CardAuditLog;
```

The `metadata` is augmented automatically with `request_id` (from `X-Request-ID`), `ip_address`, `user_agent`, `tenant_id`. NEVER include card numbers, CVVs, or full PANs in `metadata`. The service rejects payloads matching `/^\d{12,19}$/` in any string field.

---

## 8. CardRevealService

Mints short-lived signed URLs via the issuer's reveal API.

```php
public function mintRevealUrl(User $user, Card $card): RevealUrlResult;
```

Flow:

1. `entitlements.canRevealCard($user, $card)` — throw if not allowed.
2. Step-up verification has already been confirmed at the controller layer (`MoneyMovementVerificationPolicyResolver`).
3. Call `CardIssuerInterface::generateRevealUrl($card->issuer_card_token, ttl: config('cards.reveal.ttl_seconds'))`.
4. Audit `card.reveal_requested` BEFORE returning the URL (write succeeds even if the response is dropped).
5. Return `{ reveal_url, expires_at, ttl_seconds }`.

`RevealUrlResult` is NEVER persisted in any cache. The audit log records that a reveal was requested, NOT the URL itself (URL = signed token; logging it would defeat the TTL).

---

## 9. CardDisputeService

```php
public function open(User $user, CardTransaction $transaction, DisputeInput $input): CardDispute;
public function syncStatus(CardDispute $dispute): CardDispute;
public function recordChargebackAbuse(CardDispute $dispute, string $reason): void;
```

`open`:

1. Validate transaction belongs to user.
2. Validate transaction status is `settled` AND within dispute window (default 90 days from `settled_at`).
3. Create `card_disputes` row with `status='submitted'`.
4. Call `CardIssuerInterface::openDispute()` — store the returned `processor_dispute_id`.
5. Audit + event.
6. If user has > N disputes in M days (config), call `recordChargebackAbuse` and charge the abuse fee.

---

## 10. PhysicalCardOrderService

```php
public function request(User $user, CardSubscription $subscription, RequestPhysicalCardInput $input): PhysicalCardOrder;
public function activate(User $user, PhysicalCardOrder $order, ActivateInput $input): Card;
public function cancel(User $user, PhysicalCardOrder $order, string $reason): PhysicalCardOrder;
public function transition(PhysicalCardOrder $order, string $newStatus, array $metadata = []): PhysicalCardOrder;
```

`request`:

1. `entitlements.canRequestPhysicalCard($user)` → throw on decline.
2. Validate delivery info (courier OR collection point).
3. `feeService.chargeIssuanceFee($subscription->payer, /* placeholder order */)` — debits wallet.
4. Create `physical_card_orders` row with `status='paid'`, `issuance_fee` set.
5. Audit + event.

`activate`:

1. Order status `delivered`.
2. Validate activation code via processor.
3. Create the `Card` row with `status='active'`, `kind='physical'`, link to subscription.
4. Set order `activated_at`, status `activated`.
5. Audit + dispatch `PhysicalCardOrderActivated`.

`transition` is for use by admin actions and processor webhooks. Validates the transition against the FSM in [`CONTRACT.md` §12](./CONTRACT.md).

---

## 11. MinorCardSubscriptionService

Wraps `CardSubscriptionService` for minor flows.

```php
public function requestSubscribe(User $minor, string $planCode): MinorCardRequest;
public function requestPlanChange(User $minor, string $newPlanCode): MinorCardRequest;
public function requestCardCreation(User $minor, CreateVirtualCardInput $input): MinorCardRequest;
public function requestLimitChange(User $minor, Card $card, CardLimitSet $newLimits): MinorCardRequest;

public function approve(User $guardian, MinorCardRequest $request, ?string $note = null): void;
public function deny(User $guardian, MinorCardRequest $request, string $reason): void;
```

The service uses the existing `minor_card_requests` table (see `database/migrations/tenant/2026_04_24_002654_create_minor_card_requests_table.php`).

Approval flow:
1. Validate `guardian` is on the minor's account (existing relation).
2. Mark request `status='approved'`.
3. Switch on `request.request_type`:
   - `subscribe` → call `CardSubscriptionService::subscribe(subscriber: minor, payer: guardian, minorRequestId: request.id)`.
   - `plan_change` → `CardSubscriptionService::upgrade(...)` or `downgrade(...)`.
   - `card_create` → `CardLifecycleService::createVirtualCard(...)`.
   - `limit_change` → `CardLifecycleService::updateControls(...)`.
4. Audit `minor_request.approved` with both actor (guardian) and entity (minor).
5. Dispatch `MinorCardRequestApproved` event.
6. Push to minor: "Your guardian approved your card request."

Denial:
1. Mark `status='denied'`. Set `denial_reason`.
2. Audit + event.
3. Push to minor with the reason.

If a guardian fails step-up during approval → request stays `pending_approval`.

---

## 12. Money posting (consolidated reference)

Every wallet-debit through these services uses `LedgerPostingService::post()` with these account codes:

| Source | Debit | Credit |
|---|---|---|
| Subscription billing | payer.wallet_account | maphapay.card_revenue.subscriptions |
| FX markup | user.wallet_account | maphapay.card_revenue.fx |
| ATM fee | user.wallet_account | maphapay.card_revenue.atm |
| Issuance fee | payer.wallet_account | maphapay.card_revenue.issuance |
| Replacement fee (physical) | payer.wallet_account | maphapay.card_revenue.replacement |
| Replacement fee (virtual) | payer.wallet_account | maphapay.card_revenue.replacement |
| Chargeback abuse | user.wallet_account | maphapay.card_revenue.chargeback |
| Manual adjustment (admin) | user.wallet_account or maphapay.card_revenue.adjustments | mirror |

Account codes are seeded by `LedgerAccountSeeder` and added there if not present. NEVER post to a non-existent account; the ledger service will throw.

---

## 13. Concurrency

Subscription mutations use `pessimistic` row locks (`SELECT ... FOR UPDATE`) to prevent race conditions on billing. Helper:

```php
DB::transaction(function () use ($subscription) {
    $sub = CardSubscription::where('id', $subscription->id)->lockForUpdate()->firstOrFail();
    // mutate $sub
    $sub->save();
});
```

Card status mutations follow the same pattern. Cardholder data is never mutated by this domain.

---

## 14. Error handling

Service methods throw typed exceptions:

```php
namespace App\Domain\CardSubscriptions\Exceptions;

class EntitlementDeniedException extends \DomainException
{
    public function __construct(public readonly CardErrorCode $code, string $message) { parent::__construct($message); }
}
class IdempotencyMismatchException extends \DomainException { /* ... */ }
class ProcessorFailureException extends \RuntimeException { /* ... */ }
class InvalidStateTransitionException extends \DomainException { /* ... */ }
```

Controllers catch these and translate to the response envelope (`status: error`, `data.code = $exception->code->value`). Other exceptions bubble to the global handler and return 500.

---

## 15. Service registration

`app/Domain/CardSubscriptions/Providers/CardSubscriptionsServiceProvider.php`:

```php
public function register(): void
{
    $this->app->singleton(CardEntitlementService::class);
    $this->app->singleton(CardSubscriptionService::class);
    $this->app->singleton(CardBillingService::class);
    $this->app->singleton(CardFeeService::class);
    $this->app->singleton(CardLifecycleService::class);
    $this->app->singleton(CardRiskService::class);
    $this->app->singleton(CardAuditService::class);
    $this->app->singleton(CardRevealService::class);
    $this->app->singleton(CardDisputeService::class);
    $this->app->singleton(PhysicalCardOrderService::class);
    $this->app->singleton(MinorCardSubscriptionService::class);

    $this->app->bind(CardIssuerInterface::class, function ($app) {
        $driver = config('cards.default_processor');
        return $app->make(match ($driver) {
            'demo' => DemoCardIssuerAdapter::class,
            'rain' => RainCardIssuerAdapter::class,
            default => throw new \LogicException("Unknown card processor: {$driver}"),
        });
    });
}
```

Register the provider in `config/app.php` `'providers'` array (or `bootstrap/providers.php` for Laravel 11+).
