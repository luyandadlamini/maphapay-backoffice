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
- Card issuance requests tracking (minor_card_requests table).
- Card-to-minor account linking in the cards table.

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
- Minor accounts use `account_type = 'minor'` with `permission_level` (1-8) and `tier` ('grow'|'rise') in accounts table.
- `MinorAccountAccessService` provides `canSpendAtMerchant()` for category block enforcement.
- Account domain provides `AccountQueryService` for fetching minor account limits.
- Phase 11 merchant partners table and bonus system exists (bonus system not required for card but share infrastructure).

### Operational

- Only Rise tier (age 13+) minors can request or receive a card.
- Grow tier (age 6-12) minors are excluded.
- A minor account can have at most one active virtual card.

## 4. Data Model Changes

### Entities / Services

- **MinorCardRequest** model: tracks card issuance requests with approval state.
- **MinorCardSettings** model (or field on accounts table): stores card-level preferences.
- Extend `cards` table with `minor_account_uuid` (nullable FK) for linking card to minor.
- Card issuer adapters updated to accept minor-specific metadata.

### Migrations

Add `minor_account_uuid` to `cards` table:

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
    $table->uuid('minor_account_uuid'); // FK to accounts
    $table->uuid('requested_by_account_uuid'); // parent or child account UUID
    $table->string('request_type'); // 'parent_initiated' | 'child_requested'
    $table->string('status'); // 'pending_approval' | 'approved' | 'denied' | 'card_created' | 'expired'
    $table->string('requested_network')->default('visa'); // visa | mastercard
    $table->json('requested_limits')->nullable(); // optional parent-specified limits
    $table->text('denial_reason')->nullable();
    $table->uuid('approved_by')->nullable(); // parent account UUID
    $table->timestamp('approved_at')->nullable();
    $table->timestamp('expires_at')->nullable(); // request expiry
    $table->timestamps();

    $table->foreign('minor_account_uuid')->references('uuid')->on('accounts')->onDelete('cascade');
    $table->foreign('requested_by_account_uuid')->references('uuid')->on('accounts')->onDelete('cascade');
    $table->foreign('approved_by')->references('uuid')->on('accounts')->onDelete('set null');

    $table->index(['minor_account_uuid', 'status']);
    $table->index(['status', 'expires_at']);
});
```

### Card-Level Limit Integration

The card's spending limits are driven by the minor's account limits, not by a separate configuration. At card creation time:

- `daily_limit` = `MIN(requested_daily_limit, minor.account_daily_limit)`
- `monthly_limit` = `MIN(requested_monthly_limit, minor.account_monthly_limit)`
- `single_transaction_limit` = `MIN(requested_single_txn, minor.account_single_txn_limit)`

If account has no explicit limit, card uses its default limit from the card issuer adapter.

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
`403` if: minor not Rise tier, minor already has active card, requestor not authorized.

#### Approve card request (parent only)

```
POST /api/v1/minor-cards/requests/{id}/approve
```

Response: `200` with request + created card object.
`403` if not parent guardian.
`409` if request already processed.

#### Deny card request (parent only)

```
POST /api/v1/minor-cards/requests/{id}/deny
```

Body: `{ "reason": "string" }`
Response: `200` with updated request.

#### List minor's cards (parent sees minor's cards; child sees own)

```
GET /api/v1/minor-cards
```

Query params: `minor_account_uuid` (required for parent, forbidden for child).
Response: same structure as existing `/api/v1/cards` but filtered to minor's cards.

#### Get card details

```
GET /api/v1/minor-cards/{cardId}
```

Response: card details + `spending_limits` + `account_limits_applied`.

#### Freeze / unfreeze card (parent only)

```
POST /api/v1/minor-cards/{cardId}/freeze
DELETE /api/v1/minor-cards/{cardId}/freeze
```

Response: updated card with new status.

#### Provision card for wallet (same as existing `/api/v1/cards/provision` but scoped to minor card)

```
POST /api/v1/minor-cards/{cardId}/provision
```

Body: `{ "wallet_type": "apple_pay|google_pay", "device_id": "string" }`
Response: provisioning data for wallet.

### Response / Status Model

Standard envelope. `200` for success, `201` for creation, `403` for auth/authorization, `404` for not found, `409` for conflict (e.g., card already exists), `422` for validation.

### Auth / Authorization

- Card request endpoints: authenticated user must be either the minor (self) or a guardian of the minor.
- Card management (freeze/unfreeze/approve/deny): guardian only.
- Provisioning: minor or guardian.

## 6. Operator Workflow / Filament Blueprint

### Resources / Pages / Actions

**MinorCardRequestResource** (new):

- List page: all card requests across platform, filterable by status, tier, date.
- Columns: minor name, account UUID, request type, status, requested network, created at, expires at.
- View page: full request details + linked minor account info.
- Relation manager: linked card (if created).
- Actions:
  - `ApproveAction` - approve and trigger card creation.
  - `DenyAction` - deny with reason.

**MinorCardResource** (extend existing CardResource or create new):

- Filter: link to minor account cards only.
- Columns: card token (masked), minor name, network, status, spend limits, frozen_at.
- Actions:
  - `FreezeAction`, `UnfreezeAction` (independent of account).
- Info widget: show account-level limits next to card limits.

### Audit Trail

- Every approval/deny logged via standard Filament audit.
- Card freeze/unfreeze logged.
- Card creation linked to request via `minor_card_request_id`.

## 7. Failure Modes + Risk Register

### Financial Integrity Risks

- Card limit exceeds account limit — enforced via `MIN(card, account)` at creation and via JIT funding at transaction time.
- Card used after account is frozen — card status check in `VirtualCard::isUsable()` + `JitFundingService` re-checks account standing.
- Card used after minor turns 18 — lifecycle automation (Phase 10) handles conversion; card should be converted to personal account card (deferred, card remains frozen).

### Abuse Vectors

- Minor requesting card without parent knowledge — dual approval flow ensures parent must approve.
- Child spoofing parent identity to approve own request — endpoint validates caller is guardian via `MinorAccountAccessService`.
- Parent creating card for Grow-tier minor — age check rejects at request time.
- Parent setting excessive limits — card limit = `MIN(parent_requested, account_limit)`.
- Race condition: two approval requests for same minor — unique constraint on `(minor_account_uuid, status)` with status IN ('pending_approval').

### Controls

- Age gate: only Rise tier (age 13+) eligible.
- Parent approval gate: both flow patterns require guardian.
- Limit enforcement: `MIN(card, account)` at creation + JIT check at transaction time.
- One active card per minor: uniqueness constraint.
- Request expiry: requests expire after 72 hours if not processed.

## 8. Verification Strategy

### Test Matrix

- Unit:
  - Age gate: Rise tier eligible, Grow tier rejected.
  - Limit calculation: `MIN(card_limit, account_limit)` enforced.
  - One active card per minor: second request returns conflict.
  - Request type validation: `parent_initiated` vs `child_requested`.
  - Approval/deny state transitions.
  - Card freeze independent of account.
  - Merchant category block enforced on card transactions.
- Feature/API:
  - Child request flow: creates pending request.
  - Parent approve flow: creates card, updates request to `card_created`.
  - Parent deny flow: updates request to `denied`.
  - Parent freeze/unfreeze card.
  - Minor provisions card to Apple Pay.
  - Card listing filtered by minor account.
  - 403 returned for unauthorized caller.
  - 409 returned for duplicate card request.
- Filament:
  - Request list and filters work.
  - Approve action creates card.
  - Deny action updates status.
  - Card freeze independent action works.

### Regression Suites

- Existing `CardProvisioningService` tests.
- Existing `MinorAccountAccessService` auth tests.
- Existing JIT funding authorization tests.
- Existing card lifecycle tests (freeze/unfreeze/cancel).

### Static Analysis

- `phpstan` clean for new services/models.
- No new broad suppressions.