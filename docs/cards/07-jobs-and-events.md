# 07 — Jobs and Events

Spatie event-sourcing events, queued jobs, scheduled commands, and notification flows for cards.

References: [`02-domain-architecture.md`](./02-domain-architecture.md) §7, [`05-services-and-rules.md`](./05-services-and-rules.md), existing patterns in `app/Domain/Mobile/Jobs/` and `routes/console.php`.

---

## 1. Event-class catalogue

Every event extends `Spatie\EventSourcing\StoredEvents\ShouldBeStored`. Constructor parameters are `public readonly` — no behaviour. Stored in the `stored_events` table by Spatie.

```
CardSubscriptionActivated(subscriptionId, subscriberUserId, payerUserId, planCode, billedAmount, currentPeriodStart, currentPeriodEnd, isMinorSubscription, guardianUserId)
CardSubscriptionPlanChanged(subscriptionId, oldPlanCode, newPlanCode, prorationAmount)
CardSubscriptionPastDue(subscriptionId, failedPaymentCount, gracePeriodEndsAt, failureReason)
CardSubscriptionSuspended(subscriptionId, suspendedAt)
CardSubscriptionCancelled(subscriptionId, cancelledAt, cancelledBy)         -- cancelledBy: user|admin|system
CardSubscriptionRestored(subscriptionId, restoredAt)
CardSubscriptionBillingAttempted(subscriptionId, billingAttemptId, result, amount, failureReason)
CardFeeCharged(feeId, userId, feeType, amount, relatedEntityType, relatedEntityId)
CardFeeWaived(feeId, adminId, reason)
CardFeeRefunded(feeId, adminId, reason)
CardRiskEventOpened(riskEventId, userId, cardId, eventType, severity)
CardRiskEventResolved(riskEventId, adminId, resolutionNotes)
CardDisputeSubmitted(disputeId, userId, cardTransactionId, reason, disputedAmount)
CardDisputeResolved(disputeId, finalStatus, resolutionNotes)
PhysicalCardOrderRequested(orderId, userId, deliveryMethod, issuanceFee)
PhysicalCardOrderStatusChanged(orderId, oldStatus, newStatus)
PhysicalCardOrderActivated(orderId, cardId)
PhysicalCardOrderCancelled(orderId, reason, refunded)
MinorCardRequestApproved(requestId, minorAccountUuid, guardianUserId, requestType)
MinorCardRequestDenied(requestId, minorAccountUuid, guardianUserId, denialReason)
```

Existing `CardIssuance` events (`CardProvisioned`, `AuthorizationApproved`, `AuthorizationDeclined`) are NOT duplicated — they continue to fire from the existing domain.

---

## 2. Listeners

Listeners are independent and idempotent. Spatie ensures each is invoked once per event.

| Listener | Subscribes to | Action |
|---|---|---|
| `NotifyCardSubscriptionLifecycle` | All subscription events | Push notification to subscriber (and guardian for minor subs) |
| `NotifyCardFeeCharged` | `CardFeeCharged` | Push if `fee_type ∈ {subscription, physical_card_issuance, physical_card_replacement}` |
| `ApplyRiskFreezeOnCriticalEvent` | `CardRiskEventOpened` | If `severity = critical`, call `CardRiskService::suspendCardsForUser()` |
| `EmitCardLifecycleAuditLog` | `CardProvisioned`, `AuthorizationApproved`, `AuthorizationDeclined` | Append to `card_audit_logs` (CardIssuance events get audit entries here, not in CardIssuance) |
| `BroadcastSubscriptionStateToMobile` | `CardSubscriptionActivated`, `CardSubscriptionPastDue`, `CardSubscriptionSuspended`, `CardSubscriptionRestored`, `CardSubscriptionCancelled` | Pusher broadcast on `private-user.{userId}.cards` channel so the app can refetch instantly |
| `NotifyMinorCardRequest` | `MinorCardRequestApproved`, `MinorCardRequestDenied` | Push to minor with the outcome |
| `EmitMrrAggregateRecalc` | `CardFeeCharged` (subscription type) | Dispatch `RecalculateCardsMrrJob` |

All listeners implement `ShouldQueue` and use the `notifications` queue (existing).

Register in `app/Domain/CardSubscriptions/Providers/CardSubscriptionsServiceProvider::boot()`:

```php
Projectionist::addReactors([
    NotifyCardSubscriptionLifecycle::class,
    NotifyCardFeeCharged::class,
    ApplyRiskFreezeOnCriticalEvent::class,
    EmitCardLifecycleAuditLog::class,
    BroadcastSubscriptionStateToMobile::class,
    NotifyMinorCardRequest::class,
    EmitMrrAggregateRecalc::class,
]);
```

---

## 3. Scheduled jobs

In `routes/console.php` (project convention):

```php
use App\Domain\CardSubscriptions\Jobs\BillCardSubscriptionsJob;
use App\Domain\CardSubscriptions\Jobs\RetryFailedBillingJob;
use App\Domain\CardSubscriptions\Jobs\SuspendPastDueSubscriptionsJob;
use App\Domain\CardSubscriptions\Jobs\CancelLongPastDueSubscriptionsJob;
use App\Domain\CardSubscriptions\Jobs\PurgeExpiredRevealUrlsJob;
use App\Domain\CardSubscriptions\Jobs\CloseCardsOnSubscriptionEndJob;
use App\Domain\CardSubscriptions\Jobs\RecalculateCardsMrrJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new BillCardSubscriptionsJob())
    ->dailyAt('02:00')
    ->onQueue('billing')
    ->withoutOverlapping(60)
    ->name('cards.bill_subscriptions');

Schedule::job(new RetryFailedBillingJob())
    ->dailyAt('02:30')
    ->onQueue('billing')
    ->withoutOverlapping(60)
    ->name('cards.retry_failed_billing');

Schedule::job(new SuspendPastDueSubscriptionsJob())
    ->dailyAt('03:00')
    ->onQueue('billing')
    ->withoutOverlapping(30)
    ->name('cards.suspend_past_due');

Schedule::job(new CancelLongPastDueSubscriptionsJob())
    ->dailyAt('03:30')
    ->onQueue('billing')
    ->withoutOverlapping(30)
    ->name('cards.cancel_long_past_due');

Schedule::job(new CloseCardsOnSubscriptionEndJob())
    ->dailyAt('04:00')
    ->onQueue('billing')
    ->name('cards.close_cards_on_subscription_end');

Schedule::job(new PurgeExpiredRevealUrlsJob())
    ->everyFiveMinutes()
    ->onQueue('default')
    ->name('cards.purge_expired_reveal_urls');

Schedule::job(new RecalculateCardsMrrJob())
    ->hourly()
    ->onQueue('default')
    ->name('cards.recalc_mrr');
```

Schedule timezone is the project default (Africa/Mbabane). All jobs use `withoutOverlapping()` to prevent double-runs.

### `BillCardSubscriptionsJob`

```php
class BillCardSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    public int $tries = 1;        // Per-tenant; failures retried by RetryFailedBillingJob the next day.
    public int $timeout = 600;

    public function handle(CardSubscriptionService $svc, CardBillingService $billing): void
    {
        CardSubscription::query()
            ->where('status', 'active')
            ->where('next_billing_date', '<=', now())
            ->orderBy('next_billing_date')
            ->chunkById(100, function ($subs) use ($billing) {
                foreach ($subs as $sub) {
                    ProcessSingleSubscriptionRenewalJob::dispatch($sub->id)
                        ->onQueue('billing');
                }
            });
    }

    public function tags(): array { return ['cards', 'billing', ...$this->tenantTags()]; }
}
```

The orchestrator only enqueues per-subscription jobs — it never calls billing directly. This way one subscription's failure doesn't block others, and Horizon shows per-subscription progress.

### `ProcessSingleSubscriptionRenewalJob`

```php
class ProcessSingleSubscriptionRenewalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    public int $tries = 3;
    public int $backoff = 120;

    public function __construct(public string $subscriptionId) {}

    public function handle(CardBillingService $billing): void
    {
        $sub = CardSubscription::lockForUpdate()->find($this->subscriptionId);
        if (!$sub || $sub->status !== 'active' || $sub->next_billing_date > now()) {
            return; // someone else handled it; idempotent.
        }
        $billing->billRenewal($sub);
    }

    public function tags(): array { return ['cards', 'billing', "sub:{$this->subscriptionId}", ...$this->tenantTags()]; }
}
```

The unique idempotency key inside `billRenewal` (sha1 of subscription id + billing date) ensures retries don't double-charge.

### `RetryFailedBillingJob`

Iterates `card_subscriptions` where `status='past_due'` and `now ≥ last_attempt + 24h` and dispatches `ProcessSingleSubscriptionRenewalJob`. (Same job; it knows what to do with a `past_due` sub.)

### `SuspendPastDueSubscriptionsJob`

```php
CardSubscription::where('status', 'past_due')
    ->where('grace_period_ends_at', '<=', now())
    ->chunkById(100, function ($subs) {
        foreach ($subs as $sub) {
            DB::transaction(fn () => app(CardSubscriptionService::class)->suspend($sub));
        }
    });
```

### `CancelLongPastDueSubscriptionsJob`

```php
CardSubscription::where('status', 'suspended')
    ->where('suspended_at', '<=', now()->subDays(11))   // 14 days from past_due (3 grace + 11 suspended)
    ->chunkById(100, function ($subs) {
        foreach ($subs as $sub) {
            DB::transaction(fn () => app(CardSubscriptionService::class)->terminateUnpaid($sub));
        }
    });
```

### `CloseCardsOnSubscriptionEndJob`

When a user-initiated `cancel` was processed earlier, cards stayed active until `current_period_end`. This job closes them on the day they expire.

```php
CardSubscription::where('status', 'cancelled')
    ->whereDate('current_period_end', '<=', today())
    ->whereHas('cards', fn ($q) => $q->where('status', 'active'))
    ->chunkById(50, function ($subs) {
        foreach ($subs as $sub) {
            foreach ($sub->cards()->where('status', 'active')->get() as $card) {
                app(CardLifecycleService::class)->cancelCard($sub->subscriber, $card, 'subscription_ended');
            }
        }
    });
```

### `PurgeExpiredRevealUrlsJob`

Reveal URLs aren't stored in the DB, but reveal-request audit entries can accumulate. This job is a placeholder for future cleanup of reveal-related rate-limit caches (per-card reveal rate limiter uses Redis with TTL, so usually no DB cleanup needed). Keep the job registered but with a no-op body for now; document the placeholder.

### `RecalculateCardsMrrJob`

Recomputes a materialised view (or cached aggregate) for the admin dashboard's MRR widget. Avoids COUNT/SUM on the full `card_fees` table on every dashboard load.

```php
public function handle(): void
{
    $mrr = DB::table('card_fees')
        ->where('fee_type', 'subscription')
        ->where('status', 'charged')
        ->where('charged_at', '>=', now()->subDays(30))
        ->sum('amount');

    Cache::put('cards.mrr', $mrr, now()->addHours(2));
}
```

The dashboard widget reads from the cache.

---

## 4. Push notification flows

All sends use `PushNotificationService::sendToUser()` (existing in `app/Domain/Mobile/Services/`). Payload shape:

```php
[
    'type' => 'cards.<event>',
    'title' => 'Localised title',
    'body' => 'Localised body',
    'data' => [
        'subscription_id' => 'uuid',
        'card_id' => 'uuid|null',
        'cta' => 'cards.subscription.retry_payment' | 'cards.card.detail' | 'cards.physical_order.status' | 'cards.minor_request.review',
    ],
]
```

| Trigger | Notification type | Recipient | Title | Body |
|---|---|---|---|---|
| `CardSubscriptionActivated` | `cards.subscription_activated` | subscriber (and guardian for minor) | "Card subscription active" | "Your {plan_name} subscription is now active." |
| `CardSubscriptionBillingAttempted` (success) | `cards.payment_success` | payer | "Card payment successful" | "We've billed your {plan_name} subscription. Next charge {date}." |
| `CardSubscriptionPastDue` | `cards.payment_failed` | payer (and subscriber if different) | "Card payment failed" | "Add money to your wallet by {grace_end}. Your wallet still works for local payments." |
| `CardSubscriptionSuspended` | `cards.subscription_suspended` | payer (and subscriber if different) | "Card access paused" | "Your subscription is overdue. Pay now to restore card access." |
| `CardSubscriptionCancelled` | `cards.subscription_cancelled` | subscriber (and guardian for minor) | "Card subscription ended" | "Your wallet still works. Choose a plan to use cards again." |
| `CardSubscriptionRestored` | `cards.subscription_restored` | payer | "Card access restored" | "Payment received. Your cards are active again." |
| `CardProvisioned` (existing event) | `cards.virtual_created` | user | "Virtual card created" | "Your card ending in {last4} is ready to use." |
| `PhysicalCardOrderStatusChanged` (to `dispatched`, `ready_for_collection`, `delivered`) | `cards.physical_order_update` | user | varies | varies |
| `PhysicalCardOrderActivated` | `cards.physical_activated` | user | "Card activated" | "Your physical card is ready." |
| `AuthorizationApproved` (existing) | `cards.transaction_approved` | user | "Card payment" | "{merchant}: {amount}" |
| `AuthorizationDeclined` (existing) | `cards.transaction_declined` | user | "Card declined" | "{merchant}: {decline_reason}" |
| `CardDisputeSubmitted` | `cards.dispute_submitted` | user | "Dispute submitted" | "We received your dispute on {merchant}." |
| `CardRiskEventOpened` (severity ≥ high) | `cards.risk_alert` | user | "Card temporarily restricted" | "We paused your card after unusual activity. Tap to learn more." |
| `MinorCardRequestApproved` | `cards.minor_request_approved` | minor | "Card request approved" | "Your guardian approved your request." |
| `MinorCardRequestDenied` | `cards.minor_request_denied` | minor | "Card request denied" | "Your guardian denied: {reason}." |
| New `MinorCardRequest` (status=pending_approval) | `cards.minor_request_pending` | guardian | "Card request from {minor_first_name}" | "Tap to review and approve." |

Localisation strings live in `lang/en/cards.php` and `lang/ss/cards.php`. SiSwati translations come from product later.

---

## 5. Real-time WebSocket broadcasts

When mobile is open, push notifications are nice-to-have but real-time updates are better. Broadcast to `private-user.{userId}.cards`:

```php
// in BroadcastSubscriptionStateToMobile listener
broadcast(new CardSubscriptionStateUpdated($subscription))->toOthers();
```

The mobile app subscribes to this channel during sign-in and invalidates `CARDS_QUERY_KEYS.subscription()` on every event — a no-op refetch if data is already current, instant update otherwise.

Existing `BroadcastServiceProvider` and Pusher config (`config/broadcasting.php`) are reused; no new infra needed.

---

## 6. Failure handling

Every job overrides `failed(Throwable $e)`:

```php
public function failed(Throwable $e): void
{
    Log::error('Card job failed', [
        'job' => static::class,
        'tenant' => $this->getTenantId(),
        'exception' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    // Sentry or similar
    \Sentry::captureException($e);
    // For billing jobs only:
    if (in_array(static::class, [BillCardSubscriptionsJob::class, ProcessSingleSubscriptionRenewalJob::class], true)) {
        \App\Notifications\Ops\CardBillingJobFailed::send(...);
    }
}
```

Billing job failures alert the ops channel because revenue depends on them.

---

## 7. Horizon configuration

In `config/horizon.php`, declare:

```php
'environments' => [
    'production' => [
        'cards-billing-supervisor' => [
            'connection' => 'redis',
            'queue' => ['billing'],
            'balance' => 'simple',
            'minProcesses' => 2,
            'maxProcesses' => 10,
            'tries' => 3,
            'timeout' => 600,
        ],
        'cards-notifications-supervisor' => [
            'connection' => 'redis',
            'queue' => ['notifications'],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 5,
        ],
    ],
],
```

The `billing` queue is dedicated so card billing never gets stuck behind a stampede of notifications.

---

## 8. Testing jobs

Pest tests live in `tests/Feature/Cards/Jobs/`:

```
BillCardSubscriptionsJobTest.php          -- enqueues per-subscription jobs for due subs only
ProcessSingleSubscriptionRenewalJobTest.php -- happy path + insufficient funds + idempotency on retry
SuspendPastDueSubscriptionsJobTest.php    -- transitions only after grace expiry
CancelLongPastDueSubscriptionsJobTest.php -- transitions only after 14 days
CloseCardsOnSubscriptionEndJobTest.php    -- closes only cards under cancelled subs at period end
```

Use `Bus::fake()` and `Notification::fake()` to assert dispatches and notifications without side effects.

---

## 9. Outbound idempotency

Every webhook to processor uses HTTP idempotency keys (per-processor convention). Reveal-URL minting is itself idempotent — the issuer's API is responsible for enforcing TTL. Card creation uses the existing CardIssuance idempotency.

---

## 10. Operational dashboard (link to Filament)

A "Cards Operations" dashboard (`/admin/cards-dashboard`) reads:

- `cards.mrr` from cache (recomputed by `RecalculateCardsMrrJob`)
- `card_subscriptions` count by status (cached 5 min)
- Open `card_risk_events` count by severity (cached 1 min)
- Stuck billing jobs (jobs in `billing` queue ≥ 5 min) — read from Horizon's `failed_jobs` table

Widgets refresh on `wire:poll.10s`. They never run unindexed COUNT.
