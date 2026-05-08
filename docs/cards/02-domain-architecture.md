# 02 — Backend Domain Architecture

A new domain `app/Domain/CardSubscriptions/` owns everything monetisation-specific. It depends on the existing `CardIssuance` domain for actual card lifecycle and the existing `Ledger` and `Wallet` domains for money movement.

---

## 1. Folder layout

```
app/Domain/CardSubscriptions/
├── module.json                            -- declares deps, events, paths
├── Models/
│   ├── CardPlan.php
│   ├── CardSubscription.php
│   ├── CardSubscriptionBillingAttempt.php
│   ├── CardFee.php
│   ├── CardAuditLog.php
│   ├── CardRiskEvent.php
│   ├── CardDispute.php
│   └── PhysicalCardOrder.php
├── Enums/
│   ├── CardPlanCode.php
│   ├── CardSubscriptionStatus.php
│   ├── CardFeeType.php
│   ├── CardFeeStatus.php
│   ├── PhysicalCardOrderStatus.php
│   ├── CardRiskSeverity.php
│   ├── CardRiskEventStatus.php
│   ├── CardDisputeReason.php
│   ├── CardDisputeStatus.php
│   ├── CardLifecycle.php
│   ├── CardTier.php
│   ├── CardKind.php
│   ├── CardActorType.php                  -- enum for audit_logs.actor_type
│   ├── CardErrorCode.php                  -- machine-readable code returned in error envelope data.code
│   └── DeclineReason.php
├── ValueObjects/
│   ├── CardLimitSet.php                   -- per_transaction / daily / monthly / atm / contactless
│   ├── CardControlsInput.php
│   ├── CardFeePreviewInput.php
│   ├── CardFeePreview.php
│   ├── PhysicalCardDeliveryAddress.php
│   ├── BillingAttemptResult.php
│   └── RiskDecision.php
├── Services/
│   ├── CardEntitlementService.php
│   ├── CardSubscriptionService.php
│   ├── CardBillingService.php
│   ├── CardFeeService.php
│   ├── CardLifecycleService.php           -- delegates to CardIssuance::CardProvisioningService
│   ├── CardRiskService.php
│   ├── CardAuditService.php
│   ├── CardRevealService.php              -- mints signed reveal URLs
│   ├── CardDisputeService.php
│   ├── PhysicalCardOrderService.php
│   └── MinorCardSubscriptionService.php   -- wraps SubscriptionService with minor_card_requests
├── Events/
│   ├── CardSubscriptionActivated.php      -- ShouldBeStored
│   ├── CardSubscriptionPlanChanged.php
│   ├── CardSubscriptionPastDue.php
│   ├── CardSubscriptionSuspended.php
│   ├── CardSubscriptionCancelled.php
│   ├── CardSubscriptionRestored.php
│   ├── CardSubscriptionBillingAttempted.php
│   ├── CardFeeCharged.php
│   ├── CardFeeWaived.php
│   ├── CardFeeRefunded.php
│   ├── CardRiskEventOpened.php
│   ├── CardRiskEventResolved.php
│   ├── CardDisputeSubmitted.php
│   ├── CardDisputeResolved.php
│   ├── PhysicalCardOrderRequested.php
│   ├── PhysicalCardOrderActivated.php
│   ├── PhysicalCardOrderCancelled.php
│   ├── MinorCardRequestApproved.php
│   └── MinorCardRequestDenied.php
├── Listeners/
│   ├── NotifyCardSubscriptionLifecycle.php
│   ├── NotifyCardFeeCharged.php
│   ├── ApplyRiskFreezeOnCriticalEvent.php
│   ├── EmitCardLifecycleAuditLog.php
│   └── BroadcastSubscriptionStateToMobile.php
├── Jobs/
│   ├── BillCardSubscriptionsJob.php
│   ├── RetryFailedBillingJob.php
│   ├── SuspendPastDueSubscriptionsJob.php
│   ├── CancelLongPastDueSubscriptionsJob.php
│   ├── ProcessSingleSubscriptionRenewalJob.php
│   └── PurgeExpiredRevealUrlsJob.php
├── Http/
│   ├── Controllers/
│   │   ├── CardPlanController.php
│   │   ├── CardSubscriptionController.php
│   │   ├── CardController.php             -- bridges to CardIssuance internally
│   │   ├── VirtualCardController.php
│   │   ├── PhysicalCardOrderController.php
│   │   ├── CardControlController.php
│   │   ├── CardTransactionController.php
│   │   ├── CardDisputeController.php
│   │   ├── CardFeePreviewController.php
│   │   ├── CardRevealController.php
│   │   └── MinorCardRequestController.php
│   ├── Requests/
│   │   ├── SubscribeRequest.php
│   │   ├── UpgradeSubscriptionRequest.php
│   │   ├── CreateVirtualCardRequest.php
│   │   ├── UpdateCardControlsRequest.php
│   │   ├── RequestPhysicalCardRequest.php
│   │   ├── ActivatePhysicalCardRequest.php
│   │   ├── DisputeRequest.php
│   │   ├── CardFeePreviewRequest.php
│   │   └── ApproveMinorRequestRequest.php
│   └── Resources/
│       ├── CardPlanResource.php
│       ├── CardSubscriptionResource.php
│       ├── CardResource.php               -- API resource (not Filament)
│       ├── CardTransactionResource.php
│       ├── PhysicalCardOrderResource.php
│       ├── CardDisputeResource.php
│       └── CardFeePreviewResource.php
└── Routes/
    └── api.php                            -- loaded by ModuleRouteLoader
```

## 2. `module.json`

```json
{
  "name": "maphapay/card-subscriptions",
  "type": "core",
  "depends_on": [
    "shared",
    "wallet",
    "ledger",
    "card-issuance",
    "mobile",
    "agent-protocol"
  ],
  "provides_events": [
    "CardSubscriptionActivated",
    "CardSubscriptionPlanChanged",
    "CardSubscriptionPastDue",
    "CardSubscriptionSuspended",
    "CardSubscriptionCancelled",
    "CardSubscriptionRestored",
    "CardSubscriptionBillingAttempted",
    "CardFeeCharged",
    "CardRiskEventOpened",
    "CardDisputeSubmitted",
    "PhysicalCardOrderRequested",
    "PhysicalCardOrderActivated",
    "MinorCardRequestApproved"
  ],
  "routes": "Routes/api.php",
  "providers": [
    "CardSubscriptionsServiceProvider"
  ]
}
```

## 3. Dependency graph

```
                     ┌────────────────────────┐
                     │  CardSubscriptions     │
                     │  (this domain)         │
                     └──────────┬─────────────┘
                                │ uses
       ┌────────────────────────┼────────────────────────────────┐
       │                        │                                │
       ▼                        ▼                                ▼
┌──────────────┐        ┌────────────────┐              ┌──────────────┐
│ CardIssuance │        │     Ledger     │              │    Wallet    │
│ (existing)   │        │   (existing)   │              │  (existing)  │
└──────┬───────┘        └────────┬───────┘              └──────┬───────┘
       │ used by                  │                            │
       ▼                          ▼                            ▼
   processor                  immutable                    available_balance
   adapters                  ledger entries                checks, debits
```

**Hard rule:** `CardSubscriptions` MAY call into `CardIssuance`, `Ledger`, `Wallet`, `Mobile` (for push notifications), `AgentProtocol` (for KYC enum). Reverse dependencies are forbidden — `CardIssuance` MUST NOT import anything from `CardSubscriptions`.

This is enforced architecturally by the existing module loader's `depends_on` checks; if you find a circular import in implementation review, redesign before merging.

## 4. Service responsibility map

| Service | Owns | Calls |
|---|---|---|
| `CardEntitlementService` | Plan-feature decisions | `KycVerificationStatus::canTransact()` |
| `CardSubscriptionService` | Subscribe/upgrade/downgrade/cancel | `EntitlementService`, `BillingService`, `FeeService`, `AuditService` |
| `CardBillingService` | Wallet debit for monthly fees, retry, grace, suspension | `LedgerPostingService::post()`, `MoneyConverter`, `CardFeeService`, `AuditService` |
| `CardFeeService` | FX, ATM, issuance, replacement, dispute, manual-adjustment fees | `LedgerPostingService::post()`, `MoneyConverter` |
| `CardLifecycleService` | Create/freeze/unfreeze/cancel/replace cards | `CardIssuance::CardProvisioningService`, `EntitlementService`, `AuditService`, `RiskService` |
| `CardRiskService` | Decline-velocity rules, replacement velocity, MCC abuse, etc. | `RiskEventRepository`, `AuditService`. Returns `RiskDecision` |
| `CardAuditService` | Append-only audit log writes | `card_audit_logs` table |
| `CardRevealService` | Mint short-TTL signed URLs to issuer reveal page | `CardIssuance::CardIssuerInterface::revealUrl()`, `AuditService` |
| `CardDisputeService` | Open disputes, push to processor, track resolution | `CardIssuance::CardIssuerInterface::openDispute()`, `AuditService` |
| `PhysicalCardOrderService` | Order lifecycle, address validation, delivery method | `CardIssuance::CardProvisioningService`, `FeeService`, `AuditService` |
| `MinorCardSubscriptionService` | Wrap mutations with minor_card_requests approval flow | `SubscriptionService`, `MinorCardRequestRepository`, `AuditService`, push notifications |

## 5. Where existing CardIssuance services are touched

- **`CardProvisioningService::createVirtualCard()`** — new optional argument `?CardSubscription $subscription`. When present, the caller is `CardLifecycleService`; controls are clamped to subscription plan limits.
- **`CardProvisioningService::freezeCard()`** — new argument `string $actorType` (user/admin/system/processor). Writes to audit log via `CardAuditService`.
- **`JitFundingService::evaluate()`** — at the top of the method, before funds checks, call `CardEntitlementService::canAuthorize($card, $authorizationRequest)`. If the result is a decline, return that decline reason from `DeclineReason` enum (extended with `SUBSCRIPTION_INACTIVE`, `MCC_BLOCKED`, etc.).
- **`CardIssuerInterface`** — extended with one new method:

  ```php
  public function generateRevealUrl(string $issuerCardToken, int $ttlSeconds): RevealUrlResult;
  ```

  Adapters implement it (Demo: returns a static URL with HMAC; Rain: calls Rain's reveal-link API). See [`08-processor-gateway.md`](./08-processor-gateway.md).

## 6. Tenant scope

| Table | Scope |
|---|---|
| `card_plans` | global (no tenant) |
| `card_subscriptions` | tenant |
| `card_subscription_billing_attempts` | tenant |
| `card_fees` | tenant |
| `card_audit_logs` | tenant |
| `card_risk_events` | tenant |
| `card_disputes` | tenant |
| `physical_card_orders` | tenant |
| `idempotency_keys` | tenant (existing if already there) |

Models on tenant tables use `UsesTenantConnection` trait. Models on `card_plans` do NOT.

## 7. Event sourcing model

Every event class extends `Spatie\EventSourcing\StoredEvents\ShouldBeStored`. Constructor uses `public readonly` properties only — no setters, no behaviour.

Example:

```php
namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CardSubscriptionActivated extends ShouldBeStored
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $subscriberUserId,
        public readonly string $payerUserId,
        public readonly string $planCode,
        public readonly string $billedAmount,         // string SZL major
        public readonly string $currentPeriodStart,   // ISO 8601
        public readonly string $currentPeriodEnd,
        public readonly bool $isMinorSubscription,
        public readonly ?string $guardianUserId,
    ) {}
}
```

Listeners are independent. Listeners that mutate state (e.g. `ApplyRiskFreezeOnCriticalEvent`) MUST also write to `card_audit_logs`.

## 8. Where new code is forbidden from going

- `app/Models/Card.php` — does not exist. Do NOT create it. The model is `app/Domain/CardIssuance/Models/Card.php`.
- `app/Services/Cards/...` — wrong location. Use `app/Domain/CardSubscriptions/Services/...`.
- `routes/api.php` — for these new mobile-facing endpoints, use `app/Domain/CardSubscriptions/Routes/api.php` (auto-loaded).
- `routes/api-compat.php` — only when the contract freezes; pre-prod, the new domain routes are sufficient.
- `database/migrations/202X_XX_XX_create_mapha_cards_table.php` — there is no `mapha_cards` table; we extend the existing `cards` table.

## 9. Where Filament resources go

`app/Filament/Admin/Resources/Cards/`:

```
CardPlanResource.php
CardSubscriptionResource.php
MinorCardSubscriptionResource.php
CardResource.php                     -- if extending the existing one is impractical
CardTransactionResource.php
PhysicalCardOrderResource.php
CardRiskEventResource.php
CardDisputeResource.php
CardAuditLogResource.php
```

See [`06-filament-admin.md`](./06-filament-admin.md).

## 10. Configuration files

```
config/cards.php
```

Contents:

```php
return [
    'processors' => [
        'demo' => [
            'driver' => 'demo',
            'webhook_secret' => env('CARDS_DEMO_WEBHOOK_SECRET'),
            'reveal_secret' => env('CARDS_DEMO_REVEAL_SECRET'),
        ],
        'rain' => [
            'driver' => 'rain',
            'api_base_url' => env('CARDS_RAIN_API_BASE_URL'),
            'api_key' => env('CARDS_RAIN_API_KEY'),
            'webhook_secret' => env('CARDS_RAIN_WEBHOOK_SECRET'),
        ],
    ],
    'default_processor' => env('CARDS_DEFAULT_PROCESSOR', 'demo'),
    'reveal' => [
        'ttl_seconds' => 60,
    ],
    'mcc_groups' => [
        'gambling' => ['7995'],
        'crypto' => ['6051'],
        'adult' => ['5967'],
        'high_risk_digital' => ['7273'],
        'cash_like' => ['6010', '6011'],
    ],
    'risk' => [
        'declined_in_10min_threshold' => 5,
        'declined_in_24h_threshold' => 10,
        'replacements_in_30d_threshold' => 2,
        'disputes_in_60d_threshold' => 2,
    ],
];
```

The MCC group list is editable via Filament without a deploy.
