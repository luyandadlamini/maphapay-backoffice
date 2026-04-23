# Minor Accounts Phase 12 Virtual Card Support (Rise Tier 13+) Spec

Date: 2026-04-24

## 1. Executive Summary

Phase 12 delivers virtual card issuance and management for Rise tier minor accounts (ages 13-17). The timing is appropriate because the existing CardIssuance domain is fully functional, Phase 10 lifecycle automation is in place, and merchant category blocking already works for minors. The primary user benefit is enabling Rise tier children to have a virtual card for online purchases and contactless payments via Apple Pay / Google Pay. Parental controls remain central: parents must approve issuance, spending limits mirror account-level controls, cards can be frozen independently, and merchant category blocks are enforced at the card level.

## 2. Scope

### In Scope

- Virtual card issuance for minor accounts on Rise tier (age 13-17) only.
- Parent approval workflow (two patterns: parent-initiated or child-requested + parent-approved).
- Card spending limits derived from account-level limits (daily/monthly), enforced as `MIN(card_limit, account_limit)`.
- Independent card freeze/unfreeze independent of account status.
- Apple Pay / Google Pay provisioning via existing `CardProvisioningService`.
- Merchant category blocklist enforcement at card level (alcohol, tobacco, gambling blocked).
- Filament admin surface for card management and approval workflow.
- Card issuance requests tracking (`minor_card_requests` table).
- Card-to-minor account linking via `minor_account_uuid` column on `cards` table.
- Scheduled job to auto-expire stale pending requests.

### Out of Scope

- Physical card issuance (virtual only for this phase).
- Card replacement or reissuance.
- International transaction controls (Phase 13+).
- Per-merchant spending limits.
- Card-to-card transfers.
- Minor-initiated card cancellation (parent-only action).
- Spending analytics or parental insights beyond existing transaction history.

### Deferred Follow-ons

- Physical card issuance.
- International transaction controls.
- Per-merchant spending limits on card.
- Card spending analytics dashboard.

## 3. Preconditions / Dependencies

### Technical

- CardIssuance domain is fully operational (`app/Domain/CardIssuance/`).
- `CardProvisioningService` provides `createCard()`, `getProvisioningData()`, `freezeCard()`, `unfreezeCard()`, `cancelCard()`, `updateSpendingLimits()`.
- Demo and Rain card issuer adapters implement `CardIssuerInterface`.
- Minor accounts use `account_type = 'minor'` with `permission_level` (1-8) in accounts table.
- `MinorAccountAccessService` takes `User + Account` pairs and provides `hasGuardianAccess(User, Account)` and `authorizeGuardian(User, Account)` — THIS IS THE API. Do NOT use Account-only variants.
- Phase 11 merchant partners table and bonus system exists.
- Existing card `cards` table uses `issuer_card_token` as the primary card identifier (NOT `card_token`).

### Authorization Pattern (CRITICAL)

All guardian checks must follow this pattern:

```php
$user = $request->user(); // App\Models\User
$minorAccount = Account::where('uuid', $minorUuid)->firstOrFail();
$this->accessService->authorizeGuardian($user, $minorAccount); // throws AuthorizationException if not guardian
```

Do NOT pass `Account` objects to `MinorAccountAccessService`. The service operates on `User + Account` pairs.

### Operational

- Only Rise tier (age 13+) minors can request or receive a card.
- Grow tier (age 6-12) minors are excluded.
- A minor account can have at most one active virtual card.

## 4. Data Model Changes

### Entities / Services

- **MinorCardRequest** model: tracks card issuance requests with approval state.
- Extend `cards` table with `minor_account_uuid` (nullable FK) for linking card to minor.
- Card issuer adapters continue to accept metadata; the `minor_account_uuid` is also stored as a DB column for efficient queries.

### Migrations

Add `minor_account_uuid` to `cards` table (additive, nullable for backward compatibility):

```php
Schema::table('cards', function (Blueprint $table) {
    $table->uuid('minor_account_uuid')->nullable()->after('user_id');
    $table->foreign('minor_account_uuid')
          ->references('uuid')
          ->on('accounts')
          ->onDelete('cascade');
    $table->index(['minor_account_uuid', 'status']);
});
```

Add `minor_card_requests` table:

```php
Schema::create('minor_card_requests', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->nullable();
    $table->uuid('minor_account_uuid');
    $table->uuid('requested_by_account_uuid');
    $table->string('request_type');
    $table->string('status')->default('pending_approval');
    $table->string('requested_network')->default('visa');
    $table->json('requested_limits')->nullable();
    $table->text('denial_reason')->nullable();
    $table->uuid('approved_by')->nullable();
    $table->timestamp('approved_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();

    $table->foreign('minor_account_uuid')->references('uuid')->on('accounts')->onDelete('cascade');
    $table->foreign('requested_by_account_uuid')->references('uuid')->on('accounts')->onDelete('cascade');
    $table->foreign('approved_by')->references('uuid')->on('accounts')->onDelete('set null');

    $table->index(['minor_account_uuid', 'status']);
    $table->index(['status', 'expires_at']);
});
```

### Card-Level Limit Integration

The card's spending limits are driven by the minor's account limits. At card creation time:

- `daily_limit` = `MIN(requested_daily_limit, minor.account_daily_limit)`
- `monthly_limit` = `MIN(requested_monthly_limit, minor.account_monthly_limit)`
- `single_transaction_limit` = `MIN(requested_single_txn, minor.account_single_txn_limit)`

If the account has no explicit limit set, the card uses the default from the card issuer adapter.

### JIT Funding Limit Enforcement

At authorization time, `JitFundingService` must enforce limits for minor cards:

1. Detect minor cards via `$card->minor_account_uuid` (DB column) or `$card->metadata['minor_account_uuid']`.
2. Load the minor `Account` from the linked UUID.
3. Aggregate the minor's card spend in the current daily/monthly period using `AccountQueryService` or a dedicated spend aggregation query.
4. If `current_spend + authorization_amount > MIN(card_limit, account_limit)`, decline with `AuthorizationDecision::DECLINED_LIMIT_EXCEEDED`.

Note: The implementation must stay within the 2000ms latency budget. Prefer a single optimized query over multiple round-trips.

## 5. API Contract

### Endpoints (authenticated with Sanctum, minor or parent role validated)

#### List card requests (parent sees all for their minor(s); child sees own requests)

```
GET /api/v1/minor-cards/requests
```

Response:
```json
{
  "data": [
    {
      "id": "uuid",
      "minor_account_uuid": "uuid",
      "minor_name": "string",
      "request_type": "parent_initiated|child_requested",
      "status": "pending_approval|approved|denied|card_created|expired",
      "network": "visa|mastercard",
      "created_at": "datetime",
      "expires_at": "datetime|null"
    }
  ],
  "meta": { "pagination": {...} }
}
```

#### Create card request (child or parent)

```
POST /api/v1/minor-cards/requests
```

Body:
```json
{
  "minor_account_uuid": "uuid",
  "network": "visa|mastercard",
  "requested_limits": {
    "daily": 5000,
    "monthly": 15000,
    "single_transaction": 2000
  }
}
```

Response: `201` with request object.
`403` if: requestor not the minor or a guardian.
`422` if: minor not Rise tier, minor already has active card, pending request exists.

#### Approve card request (parent guardian only — CRITICAL authorization check)

```
POST /api/v1/minor-cards/requests/{id}/approve
```

`403` if not parent guardian (checked via `MinorAccountAccessService::hasGuardianAccess(User, Account)`).
`409` if request already processed.
Response: `200` with request + created card object.

#### Deny card request (parent guardian only — CRITICAL authorization check)

```
POST /api/v1/minor-cards/requests/{id}/deny
```

Body: `{ "reason": "string" }`
`403` if not parent guardian.
Response: `200` with updated request.

#### List minor's cards (parent sees minor's cards; child sees own)

```
GET /api/v1/minor-cards
```

Query params: `minor_account_uuid` (required for parent, forbidden for child).
Response: card array filtered to minor's linked cards.

#### Get card details

```
GET /api/v1/minor-cards/{cardId}
```

Response: card details + `spending_limits` + `account_limits_applied`.

#### Freeze / unfreeze card (parent guardian only)

```
POST /api/v1/minor-cards/{cardId}/freeze
DELETE /api/v1/minor-cards/{cardId}/freeze
```

`403` if not guardian.

#### Provision card for wallet

```
POST /api/v1/minor-cards/{cardId}/provision
```

Body: `{ "wallet_type": "apple_pay|google_pay", "device_id": "string" }`
Response: provisioning data for wallet.

### Response / Status Model

Standard envelope. `200` success, `201` creation, `403` auth/authorization, `404` not found, `409` conflict, `422` validation.

### Auth / Authorization

- Card request creation: authenticated user must be either the minor or a guardian.
- Approve/deny/freeze/unfreeze: must be a guardian (via `MinorAccountAccessService::hasGuardianAccess(User, Account)`).
- Provisioning: minor or guardian.

## 6. Operator Workflow / Filament Blueprint

### Resources / Pages / Actions

**MinorCardRequestResource** (new):

- List page: all card requests across platform, filterable by status, date.
- Columns: minor name, account UUID, request type, status, network, created at, expires at.
- View page: full request details + linked minor account info.
- Relation manager: linked card (read-only).
- Actions:
  - `ApproveAction` - approve and trigger card creation (guardian-only).
  - `DenyAction` - deny with reason (guardian-only).

**MinorCardResource** (extend or wrap existing CardResource):

- Filter: link to minor account cards only.
- Columns: card token (masked), minor name, network, status, spend limits, frozen_at.
- Actions: `FreezeAction`, `UnfreezeAction` (independent of account).
- Info widget: show account-level limits next to card limits.

### Audit Trail

- Every approval/deny logged via standard Filament audit.
- Card freeze/unfreeze logged.
- Card creation linked to request via `card_request_id` in card metadata.

## 7. Failure Modes + Risk Register

### Financial Integrity Risks

- Card limit exceeds account limit — enforced via `MIN(card, account)` at creation and via JIT funding at transaction time.
- Card used after account is frozen — card status check in `VirtualCard::isUsable()` + `JitFundingService` re-checks account standing.
- Card used after minor turns 18 — lifecycle automation (Phase 10) handles conversion; card frozen pending review (deferred to Phase 13+ for full conversion flow).

### Abuse Vectors

- Minor requesting card without parent knowledge — dual approval flow ensures parent must approve.
- Non-guardian approving own request — `approveRequest` endpoint guards with `MinorAccountAccessService::hasGuardianAccess(User, Account)`.
- Parent creating card for Grow-tier minor — age check rejects at request time.
- Parent setting excessive limits — card limit = `MIN(parent_requested, account_limit)`.
- Race condition: two concurrent approval requests — wrapped in `DB::transaction()` with optimistic locking on request status.
- Non-guardian freezing card — freeze endpoint requires guardian check.

### Controls

- Age gate: only Rise tier (age 13+) eligible.
- Guardian authorization gate: approve/deny/freeze/unfreeze require `hasGuardianAccess(User, Account)`.
- Limit enforcement: `MIN(card, account)` at creation + JIT check at transaction time.
- One active card per minor: check + uniqueness constraint.
- Request expiry: scheduled job auto-expires stale pending requests after 72 hours.

## 8. Verification Strategy

### Test Matrix

- Unit:
  - Age gate: Rise tier eligible, Grow tier rejected.
  - Limit calculation: `MIN(card_limit, account_limit)` enforced.
  - One active card per minor: second request returns conflict.
  - Request type: `parent_initiated` vs `child_requested` correctly determined.
  - Approval/deny state transitions.
  - Card freeze independent of account.
  - `hasGuardianAccess(User, Account)` returns correct result for guardian vs non-guardian.
  - `authorizeGuardian(User, Account)` throws for non-guardian.
  - JIT authorization declines when minor exceeds daily/monthly limit.
  - Merchant category block enforced on card transactions.
- Feature/API:
  - Child request flow: creates pending request.
  - Parent approve flow: creates card, updates request to `card_created`.
  - Parent deny flow: updates request to `denied`.
  - Non-guardian POST /approve → 403.
  - Non-guardian POST /deny → 403.
  - Non-guardian POST /freeze → 403.
  - Card listing filtered by minor account.
  - 409 returned for duplicate active card request.
  - 409 returned for pending request already open.
- Filament:
  - Request list and filters work.
  - Approve action creates card and updates status.
  - Deny action updates status with reason.
  - Card freeze independent action works.

### Regression Suites

- Existing `CardProvisioningService` tests.
- Existing `MinorAccountAccessService` auth tests.
- Existing JIT funding authorization tests.
- Existing card lifecycle tests.

### Static Analysis

- `phpstan` clean for new services/models.
- No new broad suppressions.