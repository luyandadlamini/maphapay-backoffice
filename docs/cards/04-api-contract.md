# 04 — API Contract (Canonical)

**This file is byte-identical between repos** (`maphapay-backoffice/docs/cards/04-api-contract.md` ⇔ `maphapayrn/docs/cards/03-api-contract.md`).

Defines every endpoint touching card subscriptions, cards, transactions, and processor webhooks. See [`CONTRACT.md`](./CONTRACT.md) for status/error vocabulary and [`01-product-config.md`](./01-product-config.md) for plan codes.

All authenticated endpoints require Sanctum bearer auth + `X-Account-Id` header (existing pattern). All money-moving endpoints require `Idempotency-Key` and step-up via `mobileTrustContext`.

---

## 1. Endpoint summary

| Method | Path | Auth | Idempotency | Step-up |
|---|---|---|---|---|
| GET | `/v1/card-subscriptions/plans` | Sanctum | — | — |
| GET | `/v1/card-subscriptions/me` | Sanctum | — | — |
| POST | `/v1/card-subscriptions` | Sanctum | required | required |
| POST | `/v1/card-subscriptions/upgrade` | Sanctum | required | required |
| POST | `/v1/card-subscriptions/downgrade` | Sanctum | required | required |
| POST | `/v1/card-subscriptions/cancel` | Sanctum | required | required |
| POST | `/v1/card-subscriptions/retry-payment` | Sanctum | required | — |
| GET | `/v1/cards` | Sanctum | — | — |
| GET | `/v1/cards/{id}` | Sanctum | — | — |
| POST | `/v1/cards/virtual` | Sanctum | required | required |
| POST | `/v1/cards/{id}/freeze` | Sanctum | required | required |
| POST | `/v1/cards/{id}/unfreeze` | Sanctum | required | required |
| POST | `/v1/cards/{id}/cancel` | Sanctum | required | required |
| POST | `/v1/cards/{id}/replace` | Sanctum | required | required |
| PATCH | `/v1/cards/{id}/controls` | Sanctum | required | required |
| GET | `/v1/cards/{id}/reveal` | Sanctum | — | required |
| GET | `/v1/cards/{id}/transactions` | Sanctum | — | — |
| GET | `/v1/card-transactions/{id}` | Sanctum | — | — |
| POST | `/v1/card-transactions/{id}/dispute` | Sanctum | required | required |
| POST | `/v1/card-fees/preview` | Sanctum | — | — |
| POST | `/v1/cards/physical/request` | Sanctum | required | required |
| GET | `/v1/cards/physical/orders` | Sanctum | — | — |
| GET | `/v1/cards/physical/orders/{id}` | Sanctum | — | — |
| POST | `/v1/cards/physical/orders/{id}/activate` | Sanctum | required | required |
| POST | `/v1/cards/physical/orders/{id}/cancel` | Sanctum | required | required |
| POST | `/v1/minor-card-requests/{id}/approve` | Sanctum (guardian) | required | required |
| POST | `/v1/minor-card-requests/{id}/deny` | Sanctum (guardian) | required | required |
| POST | `/webhooks/cards/{processor}/authorisation` | HMAC | by `processor_event_id` | — |
| POST | `/webhooks/cards/{processor}/clearing` | HMAC | by `processor_event_id` | — |
| POST | `/webhooks/cards/{processor}/reversal` | HMAC | by `processor_event_id` | — |
| POST | `/webhooks/cards/{processor}/refund` | HMAC | by `processor_event_id` | — |

Route registration goes in `app/Domain/CardSubscriptions/Routes/api.php` (auto-loaded by `ModuleRouteLoader`). Mobile compatibility re-export (when contract freezes) goes into `routes/api-compat.php` with the same middleware stack as send-money: `['auth:sanctum', 'account.context', 'kyc_approved', 'idempotency', 'throttle:maphapay-card-mutation']`.

---

## 2. Common response envelope

See [`CONTRACT.md` §16](./CONTRACT.md). Every response below shows only the `data` body; the wrapping `{ status, remark, data }` is implicit.

---

## 3. Subscriptions

### 3.1 List plans — `GET /v1/card-subscriptions/plans`

Returns all active plans the **calling user** is eligible for (filtered by adult/minor).

**Response 200 `data`:**

```json
{
  "plans": [
    {
      "code": "VIRTUAL_PLUS",
      "name": "Virtual Card Plus",
      "monthly_fee": "50.00",
      "currency": "SZL",
      "max_virtual_cards": 3,
      "max_physical_cards": 0,
      "monthly_card_creation_limit": 2,
      "monthly_card_spend_limit": "15000.00",
      "single_transaction_limit": "5000.00",
      "daily_card_spend_limit": "7500.00",
      "atm_enabled": false,
      "atm_daily_limit": "0.00",
      "atm_monthly_limit": "0.00",
      "atm_fixed_fee": "0.00",
      "atm_percentage_fee_bps": 0,
      "fx_markup_bps": 300,
      "physical_card_issuance_fee": "0.00",
      "physical_card_replacement_fee": "0.00",
      "virtual_card_replacement_fee": "20.00",
      "free_virtual_reissues_per_month": 1,
      "eligibility": "adult",
      "features": [
        "Up to 3 virtual cards",
        "Online payments",
        "International payments",
        "Card spending limits",
        "1 free virtual card reissue per month"
      ]
    }
  ]
}
```

`features` is a localised string list for UI rendering. The numbers above it are authoritative; `features` is presentation only.

### 3.2 Get current subscription — `GET /v1/card-subscriptions/me`

**Response 200 `data`:**

```json
{
  "subscription": {
    "id": "uuid",
    "subscriber_user_id": "uuid",
    "payer_user_id": "uuid",
    "plan_code": "VIRTUAL_PLUS",
    "status": "active",
    "current_period_start": "2026-05-08T00:00:00+02:00",
    "current_period_end": "2026-06-08T00:00:00+02:00",
    "next_billing_date": "2026-06-08T00:00:00+02:00",
    "failed_payment_count": 0,
    "grace_period_ends_at": null,
    "is_minor_subscription": false,
    "guardian_user_id": null
  }
}
```

If the user has no subscription, returns `data: { "subscription": null }` (status `success`, NOT 404).

### 3.3 Subscribe — `POST /v1/card-subscriptions`

**Request:**

```json
{
  "plan_code": "VIRTUAL_PLUS",
  "subscriber_user_id": "uuid (optional, defaults to caller; required if guardian subscribing for minor)"
}
```

**Headers:** `Idempotency-Key`, `mobileTrustContext`.

**Success 200 `data`:**

```json
{
  "subscription": { /* same shape as 3.2 */ },
  "next_step": "none"
}
```

If verification policy returns `next_step ≠ 'none'`, the subscription is created in `pending_verification` (internal state, not exposed to mobile). Mobile must verify via existing PIN/OTP flow. On verify success, subscription transitions to `active` and the wallet is debited.

**Errors:** `PLAN_NOT_AVAILABLE`, `PLAN_NOT_ELIGIBLE_FOR_USER`, `DUPLICATE_SUBSCRIPTION`, `FULL_KYC_REQUIRED`, `INSUFFICIENT_WALLET_BALANCE`, `STEP_UP_AUTH_REQUIRED`, `MINOR_REQUEST_PENDING` (if guardian-led minor sub already pending), `GUARDIAN_APPROVAL_REQUIRED` (if minor self-tries).

### 3.4 Upgrade / Downgrade — `POST /v1/card-subscriptions/{upgrade|downgrade}`

**Request:**

```json
{
  "plan_code": "PREMIUM_CARD"
}
```

Same envelope as 3.3. Downgrade additionally returns the count of active cards exceeding the new plan's limit; mobile must surface a "you must close N cards before downgrading" prompt. Service auto-freezes excess cards if mobile sets `force: true` in the request.

### 3.5 Cancel — `POST /v1/card-subscriptions/cancel`

No request body. Subscription transitions to `cancelled`; all `active` cards under it transition to `cancelled` at cycle end (not immediately — user keeps card access until `current_period_end`). Wallet debit for the current period stays.

### 3.6 Retry payment — `POST /v1/card-subscriptions/retry-payment`

For `past_due` subscriptions only. Triggers an immediate billing attempt against the payer wallet. No step-up needed (retry is a continuation, not a new contract).

---

## 4. Cards

### 4.1 List — `GET /v1/cards`

**Response 200 `data`:**

```json
{
  "cards": [
    {
      "id": "uuid",
      "user_id": "uuid",
      "cardholder_id": "uuid",
      "card_type": "virtual",
      "card_brand": "visa",
      "last4": "1234",
      "expiry_month": 12,
      "expiry_year": 2029,
      "status": "active",
      "nickname": "Online shopping",
      "is_primary": false,
      "tier": "standard",
      "lifecycle": "standard",
      "lifecycle_config": null,
      "minor_account_uuid": null,
      "controls": {
        "per_transaction_limit": "1500.00",
        "daily_limit": "1500.00",
        "monthly_limit": "3000.00",
        "online_enabled": true,
        "international_enabled": true,
        "atm_enabled": false,
        "contactless_enabled": false,
        "blocked_mcc_groups": []
      },
      "created_at": "2026-05-08T10:00:00+02:00"
    }
  ]
}
```

PAN, CVV, full expiry are NEVER in the list response.

### 4.2 Get one — `GET /v1/cards/{id}`

Same shape as a single entry in 4.1. 404 if card not owned by caller (use 404 not 403 to avoid resource enumeration).

### 4.3 Create virtual — `POST /v1/cards/virtual`

**Request:**

```json
{
  "nickname": "Online shopping",
  "lifecycle": "standard",
  "lifecycle_config": null,
  "controls": {
    "per_transaction_limit": "1500.00",
    "daily_limit": "1500.00",
    "monthly_limit": "3000.00",
    "online_enabled": true,
    "international_enabled": true,
    "blocked_mcc_groups": ["gambling", "crypto"]
  }
}
```

`per_transaction_limit ≤ plan.single_transaction_limit`. `daily_limit ≤ plan.daily_card_spend_limit`. `monthly_limit ≤ plan.monthly_card_spend_limit`. Backend rejects with `400` and an explicit `data.code` if exceeded.

**Success 200 `data`:** `{ "card": { /* shape from 4.1 */ } }`.

**Errors:** `SUBSCRIPTION_REQUIRED`, `SUBSCRIPTION_NOT_ACTIVE`, `PLAN_DOES_NOT_ALLOW_VIRTUAL_CARD`, `VIRTUAL_CARD_LIMIT_REACHED`, `MONTHLY_CREATION_LIMIT_REACHED`, `FULL_KYC_REQUIRED`, `HIGH_RISK_USER`, `STEP_UP_AUTH_REQUIRED`, `PROCESSOR_CARD_CREATION_FAILED`.

### 4.4 Freeze / Unfreeze / Cancel / Replace

`POST /v1/cards/{id}/freeze`:

```json
{ "reason": "user_initiated" }
```

`POST /v1/cards/{id}/unfreeze`: empty body. Rejects if `card.status === 'frozen_by_admin'` with `ADMIN_FROZEN_CARD`.

`POST /v1/cards/{id}/cancel`: empty body. Card transitions to `cancelled`; processor cancellation is dispatched async.

`POST /v1/cards/{id}/replace`:

```json
{ "reason": "lost" | "stolen" | "damaged" | "expired" | "fraud" }
```

Returns the new card. Old card transitions to `replaced`. Replacement fee logic applies per [`01-product-config.md`](./01-product-config.md) §5 and is debited from the wallet (or waived for `expired`/`fraud`).

### 4.5 Update controls — `PATCH /v1/cards/{id}/controls`

Body is the `controls` object from 4.1. Partial updates accepted (only fields present are changed). Backend rejects limit values exceeding plan ceilings.

### 4.6 Reveal — `GET /v1/cards/{id}/reveal`

**Step-up required.** Backend mints a 60-second signed URL pointing at the issuer-hosted reveal page.

**Success 200 `data`:**

```json
{
  "reveal_url": "https://reveal.<issuer>.com/v1/cards/<token>?sig=...&exp=...",
  "expires_at": "2026-05-08T10:11:00+02:00",
  "ttl_seconds": 60
}
```

Mobile loads this URL in a sandboxed `react-native-webview`. The webview is closed automatically at `expires_at`. Backend writes a `card_audit_logs` entry with `action='reveal_requested'` BEFORE returning. PAN/CVV are NEVER in this response.

### 4.7 Card transactions — `GET /v1/cards/{id}/transactions`

```json
{
  "transactions": [
    {
      "id": "uuid",
      "card_id": "uuid",
      "transaction_type": "authorisation",
      "status": "approved",
      "amount": "100.00",
      "currency": "USD",
      "billing_amount": "1850.00",
      "billing_currency": "SZL",
      "merchant_name": "Amazon",
      "merchant_country": "US",
      "merchant_category_code": "5942",
      "fx_rate": "18.50000000",
      "fx_fee": "55.50",
      "mapha_fee": "0.00",
      "scheme_fee": "0.00",
      "decline_reason": null,
      "authorised_at": "2026-05-08T10:00:00+02:00",
      "settled_at": null
    }
  ],
  "pagination": {
    "cursor": "string|null",
    "has_more": true
  }
}
```

Cursor pagination, page size 50.

### 4.8 Dispute — `POST /v1/card-transactions/{id}/dispute`

```json
{
  "reason": "unrecognised" | "duplicate" | "wrong_amount" | "service_not_received" | "other",
  "description": "Free text up to 500 chars",
  "disputed_amount": "100.00"
}
```

Returns the created `card_disputes` record. Status starts as `submitted`.

---

## 5. Fees

### 5.1 Preview — `POST /v1/card-fees/preview`

**Request:**

```json
{
  "transaction_type": "online_purchase" | "atm_withdrawal" | "physical_card_issuance" | "physical_card_replacement" | "virtual_card_replacement",
  "amount": "100.00",
  "currency": "USD",
  "billing_currency": "SZL"
}
```

**Response 200 `data`:**

```json
{
  "amount": "100.00",
  "currency": "USD",
  "estimated_billing_amount": "1850.00",
  "billing_currency": "SZL",
  "fx_fee": "55.50",
  "atm_fee": "0.00",
  "issuance_fee": "0.00",
  "replacement_fee": "0.00",
  "total_debit": "1905.50"
}
```

This endpoint is read-only; no idempotency key. The `estimated_billing_amount` uses the issuer's current FX rate API; mobile MUST inform the user the final settlement may differ.

---

## 6. Physical cards

### 6.1 Request — `POST /v1/cards/physical/request`

**Request:**

```json
{
  "delivery_method": "branch_collection" | "courier",
  "delivery_address": {
    "line1": "string",
    "line2": "string|null",
    "city": "Mbabane",
    "country": "Eswatini",
    "phone_number": "+268..."
  },
  "collection_point_id": "uuid|null"
}
```

Either `delivery_address` (for `courier`) OR `collection_point_id` (for `branch_collection`) is required. Backend validates and debits the issuance fee from `payer_user_id` wallet.

**Success 200 `data`:** `{ "order": { /* shape from 6.2 */ } }`.

### 6.2 List orders — `GET /v1/cards/physical/orders`

```json
{
  "orders": [
    {
      "id": "uuid",
      "card_id": "uuid|null",
      "order_status": "production",
      "delivery_method": "branch_collection",
      "delivery_address": null,
      "collection_point_id": "uuid",
      "issuance_fee": "120.00",
      "delivery_fee": "0.00",
      "tracking_reference": "MP-2026-001234",
      "requested_at": "2026-05-08T10:00:00+02:00",
      "approved_at": "2026-05-08T10:05:00+02:00",
      "dispatched_at": null,
      "delivered_at": null,
      "activated_at": null
    }
  ]
}
```

### 6.3 Get one — `GET /v1/cards/physical/orders/{id}`

Same shape, single entry.

### 6.4 Activate — `POST /v1/cards/physical/orders/{id}/activate`

```json
{
  "activation_code": "6-digit string from card carrier",
  "pin": "4-digit string"
}
```

`pin` is forwarded to the processor (NEVER stored locally). Order transitions to `activated`; card transitions to `active`.

### 6.5 Cancel — `POST /v1/cards/physical/orders/{id}/cancel`

Empty body. Allowed when `order_status ∈ {requested, paid, approved}`. Refunds issuance fee if not yet at `production`.

---

## 7. Minor card requests (guardian endpoints)

### 7.1 Approve — `POST /v1/minor-card-requests/{id}/approve`

```json
{
  "approval_note": "string|null"
}
```

Caller must be the guardian on the minor's account. Triggers downstream subscription creation / plan change / card creation per the request type. Idempotent.

### 7.2 Deny — `POST /v1/minor-card-requests/{id}/deny`

```json
{
  "denial_reason": "string"
}
```

`denial_reason` is mandatory. The minor sees the reason in their app.

---

## 8. Processor webhooks

Path: `POST /webhooks/cards/{processor}/{event_type}`. `{processor}` ∈ `demo`, `rain`, `marqeta`. `{event_type}` ∈ `authorisation`, `clearing`, `reversal`, `refund`.

**Headers:** `X-MaphaPay-Processor-Signature: hmac-sha256=<digest>` over the raw request body using the per-processor secret.

**Body:** raw JSON from processor (provider-specific). The webhook controller persists the raw payload to `card_audit_logs.metadata` BEFORE any state mutation and uses `processor_event_id` for idempotency (no duplicate processing).

Webhook returns:

- `200 OK` on success (idempotent replay returns 200 too)
- `401 Unauthorized` on signature mismatch
- `409 Conflict` on duplicate `processor_event_id` mismatch (same id, different body — should never happen; raises a critical alert)
- `422 Unprocessable Entity` on malformed payload

---

## 9. Mobile config flags

Path: `GET /api/mobile/config` (existing endpoint). Card-related additions to `data.features`:

```json
{
  "features": {
    "cards": {
      "monetisation_enabled": false,
      "subscriptions_enabled": false,
      "virtual_card_lite_enabled": false,
      "virtual_card_plus_enabled": false,
      "physical_card_enabled": false,
      "premium_card_enabled": false,
      "minor_khula_card_enabled": false,
      "fx_fees_enabled": false,
      "atm_enabled": false,
      "disputes_enabled": false,
      "admin_risk_controls_enabled": true
    }
  }
}
```

---

## 10. Rate limits

| Endpoint group | Limiter name | Requests / minute |
|---|---|---|
| Subscribe / upgrade / downgrade / cancel | `maphapay-card-subscription` | 6 per user |
| Create virtual / request physical / replace | `maphapay-card-creation` | 10 per user |
| Freeze / unfreeze / update controls / dispute | `maphapay-card-mutation` | 30 per user |
| Reveal | `maphapay-card-reveal` | 5 per card per minute |
| Webhooks | `maphapay-card-webhook` | 600 per processor (10/sec burst) |

Limiters are registered in `app/Providers/RouteServiceProvider.php` alongside existing `maphapay-*` definitions.

---

## 11. Versioning

The path prefix `v1` is the contract version. Breaking changes go to `v2`; mobile sends an `Accept-Version` header to opt in. Pre-production, breaking changes are made in place and the contract doc is updated atomically with the code change.
