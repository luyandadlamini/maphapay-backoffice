# MaphaPay Cards — Cross-Repo Contract

**This file is canonical and byte-identical between:**
- `maphapayrn/docs/cards/CONTRACT.md`
- `maphapay-backoffice/docs/cards/CONTRACT.md`

If you change one, change the other in the same commit and verify with:

```sh
diff -q maphapayrn/docs/cards/CONTRACT.md maphapay-backoffice/docs/cards/CONTRACT.md
```

This file defines every shared identifier. Anything not defined here MUST NOT be sent across the wire.

---

## 1. Plan codes

```
FREE_WALLET
VIRTUAL_LITE
VIRTUAL_PLUS
PHYSICAL_CARD
PREMIUM_CARD
MINOR_KHULA_CARD
```

`MINOR_KHULA_CARD` is the only minor-eligible plan and is always guardian-billed.

**Naming note:** *Khula* (siSwati: "to grow") is the tier name for MaphaPay's minor card product, ages 13–17. Cards code and copy use "Khula" exclusively — never "Rise". If you encounter "Rise" anywhere in cards code, it is a bug; rename it.

## 2. Subscription status

```
active        -- paid, in good standing
past_due      -- last billing failed, in 3-day grace period
suspended     -- grace expired, card spending blocked, wallet untouched
cancelled     -- user-initiated end OR 14-day failed payment terminal state
pending_guardian_approval  -- minor subscription awaiting guardian via minor_card_requests
```

## 3. Card status

```
pending       -- created, awaiting first activation/provision
active        -- usable
frozen_by_user
frozen_by_admin
suspended     -- subscription suspended; auto-restores on payment
cancelled
expired
replaced
lost_stolen
```

User-frozen cards may be unfrozen by the user. Admin-frozen cards may NOT be unfrozen by the user.

## 4. Card type

```
virtual
physical
```

Disposable cards are out of scope for MVP.

## 5. Card lifecycle

```
standard
merchant_locked
single_use
trial
```

`merchant_locked`, `single_use`, `trial` require `lifecycle_config` JSON with the relevant constraint (locked merchant, charge count, expiry date).

## 6. Card transaction types

```
authorisation
clearing
reversal
refund
atm_withdrawal
```

## 7. Card transaction status

```
pending
approved
declined
settled
reversed
refunded
```

## 8. Decline reasons

```
CARD_NOT_FOUND
CARD_NOT_ACTIVE
USER_NOT_ACTIVE
SUBSCRIPTION_INACTIVE
INSUFFICIENT_FUNDS
PER_TRANSACTION_LIMIT_EXCEEDED
DAILY_LIMIT_EXCEEDED
MONTHLY_LIMIT_EXCEEDED
ATM_NOT_ALLOWED
ATM_LIMIT_EXCEEDED
INTERNATIONAL_DISABLED
ONLINE_DISABLED
MCC_BLOCKED
HIGH_RISK_TRANSACTION
PROCESSOR_ERROR
MINOR_CARD_LIMIT_EXCEEDED
GUARDIAN_NOT_APPROVED
```

## 9. API error codes

Every 4xx/5xx response from `/v1/cards/*` and `/v1/card-subscriptions/*` returns one of these in `data.code`:

```
USER_NOT_ACTIVE
FULL_KYC_REQUIRED
HIGH_RISK_USER
SUBSCRIPTION_REQUIRED
SUBSCRIPTION_NOT_ACTIVE
PLAN_DOES_NOT_ALLOW_VIRTUAL_CARD
PLAN_DOES_NOT_ALLOW_PHYSICAL_CARD
PLAN_DOES_NOT_ALLOW_ATM
VIRTUAL_CARD_LIMIT_REACHED
PHYSICAL_CARD_LIMIT_REACHED
MONTHLY_CREATION_LIMIT_REACHED
STEP_UP_AUTH_REQUIRED
PROCESSOR_CARD_CREATION_FAILED
INSUFFICIENT_WALLET_BALANCE
PLAN_NOT_AVAILABLE
PLAN_NOT_ELIGIBLE_FOR_USER     -- e.g. minor trying adult plan
GUARDIAN_APPROVAL_REQUIRED
MINOR_REQUEST_PENDING
DUPLICATE_SUBSCRIPTION
CARD_NOT_FOUND
ADMIN_FROZEN_CARD
IDEMPOTENCY_KEY_REUSE_MISMATCH
PROCESSOR_UNAVAILABLE
```

## 10. Fee types (for `card_fees.fee_type` and audit)

```
subscription
fx_markup
atm
virtual_card_replacement
physical_card_issuance
physical_card_replacement
chargeback_abuse
manual_adjustment
```

## 11. Fee status

```
pending
charged
waived
refunded
failed
```

## 12. Physical card order status

```
requested
paid
approved
production
dispatched
ready_for_collection
delivered
activated
cancelled
```

Allowed transitions:

```
requested → paid → approved → production → dispatched → delivered → activated
requested → cancelled
paid → cancelled
approved → cancelled
production → ready_for_collection → delivered → activated
```

## 13. Risk event severity

```
low
medium
high
critical
```

## 14. Risk event status

```
open
in_review
resolved
dismissed
```

## 15. Audit log actor types

```
user
admin
system
processor
```

## 16. Response envelope

Every authenticated mobile-facing endpoint returns this exact shape:

**Success:**

```json
{
  "status": "success",
  "remark": "card_subscription_created",
  "data": { /* endpoint-specific */ }
}
```

**Error:**

```json
{
  "status": "error",
  "remark": "card_subscription_create_failed",
  "message": ["User-facing reason in array form"],
  "data": {
    "code": "PLAN_NOT_ELIGIBLE_FOR_USER"
  }
}
```

Mobile MUST treat anything other than `status === "success"` as failure. `data.code` is the machine-readable identifier; `message` is user-facing.

## 17. Money representation

| Layer | Format | Example |
|---|---|---|
| API request body | string, major units | `"50.00"` |
| API response body | string, major units | `"50.00"` |
| DB column type | `numeric(18,2)` OR `string(64)` per backend convention | `'50.00'` |
| Backend computation | bcmath via `MoneyConverter::forAsset()` | n/a |
| Mobile rendering | `safeParseBalance()` from `src/utils/currency` | `E50` |

Currency is implicit `SZL` for all wallet/subscription/fee values. Card transactions may have a `transaction_currency` and `billing_currency`; FX markup applies when `transaction_currency NOT IN ('SZL','ZAR')`.

## 18. Idempotency

Endpoints requiring idempotency declare it explicitly. Header:

```
Idempotency-Key: <uuid v4>
```

Mobile generates one UUID per logical operation, stores it in `useRef`, retries with the SAME key, and only clears it when a terminal response (success OR final failure) arrives. Backend uses the existing `idempotency` middleware and the `OperationRecord` cache.

Required for: subscribe, upgrade, downgrade, cancel, retry-payment, create-virtual, request-physical, activate-physical, replace, dispute, all processor webhooks.

## 19. Step-up authentication

Required for:

```
subscribe, upgrade, downgrade, cancel
create-virtual, request-physical, activate-physical, replace, cancel-card
update-card-controls
reveal-card-details (always)
admin-freeze-card, admin-unfreeze-card (admin Filament action)
```

Mobile sends step-up via existing `mobileTrustContext` (`mergeMobileTrustPayload()`). Backend resolves the policy through `MoneyMovementVerificationPolicyResolver` and returns `next_step ∈ {pin, otp, none}` in the success envelope.

## 20. Webhook signatures

Processor webhooks are unauthenticated by Sanctum but carry an HMAC signature header (per-processor). The webhook controller MUST:

1. Verify HMAC using the per-processor secret from `config('cards.processors.<key>.webhook_secret')`.
2. Reject (401) on mismatch.
3. Apply idempotency on the `processor_event_id`.
4. Persist the raw payload to `card_audit_logs` before mutating any state.

## 21. KYC gate

Card features require `KycVerificationStatus::VERIFIED` (constant from `app/Domain/AgentProtocol/Enums/KycVerificationStatus.php`). The method `KycVerificationStatus::canTransact()` is the single check used by `CardEntitlementService`.

## 22. Tenant scope

`card_subscriptions`, `card_fees`, `card_audit_logs`, `card_risk_events`, `card_disputes`, `physical_card_orders` are tenant-scoped (use `UsesTenantConnection` trait). `card_plans` is global (single source of truth across tenants).

## 23. PCI scope statement

PAN and CVV NEVER touch MaphaPay infrastructure. Card details reveal is implemented as a short-lived (60s) signed redirect to the issuer-hosted reveal page, loaded in a sandboxed `react-native-webview` with `allowsBackForwardNavigationGestures={false}` and screen-recording disabled at the OS level where supported. Backend stores and logs only:

- `last4`
- `expiry_month`, `expiry_year`
- `processor_card_id` / `issuer_card_token`
- `card_brand`

Backend MUST refuse to deserialize, accept, or log a full PAN under any circumstance, including from inbound webhooks or admin entries.

## 24. Strict version note

This contract is **versionless** for now (pre-production). When the first production user subscribes, this file is forked into `CONTRACT-v1.md` and changes thereafter require a versioned migration.
