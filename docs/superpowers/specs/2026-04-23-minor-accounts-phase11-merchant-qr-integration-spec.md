# Minor Accounts Phase 11 Merchant & QR Integration Spec

Date: 2026-04-23

## 1. Executive Summary

Phase 11 delivers the merchant partnership infrastructure and QR payment bonus system for minor accounts, enabling the "Earn 2x points on QR payments at partnered Eswatini merchants" feature from the original roadmap. The timing is appropriate because Phase 10 lifecycle automation is now in place, merchant category blocking already works for minors, and the `merchant_partners` table exists with basic partner data. Business outcomes: differentiated merchant engagement for minor users, points redemption velocity, and local merchant ecosystem growth. Risk outcomes: no financial side effects (bonus points are non-monetary), and merchant category filtering remains enforced for minors.

## 2. Scope

### In Scope

- Merchant partner registry extending `merchant_partners` table with minor program metadata (`bonus_multiplier`, `min_age_allowance`, `category_slugs`, `is_active_for_minors`).
- QR payment bonus tracking: detect QR payments to partnered merchants, calculate and credit bonus points via existing `MinorPointsService`.
- Merchant discovery endpoint: extend existing `/v1/commerce/merchants` with minor-specific bonus filters.
- Bonus points ledger: reuse `MinorPointsLedger` with `source='merchant_bonus'` for audit trail.
- Filament management surface for merchant partner minor program configuration.
- Category-specific bonus rules (e.g., grocery = 2x, airtime = 1.5x, retail = 1x).

### Out of Scope

- Physical card acceptance at merchants (virtual card is Phase 12).
- Merchant onboarding workflow (reusing existing `MerchantOnboardingService` for now).
- Real-time merchant loyalty campaigns or promotions (deferred to Phase 13).
- External merchant API integration (webhook-based settlement only).
- Merchant settlement or commission disbursement (finance team owns this).

### Deferred Follow-ons

- Dynamic bonus multipliers (time-based, event-based campaigns).
- Merchant analytics dashboard.
- Push notifications for nearby deals.
- Cross-merchant loyalty programs.

## 3. Preconditions / Dependencies

### Technical

- `merchant_partners` table exists with base schema (name, category, logo_url, qr_endpoint, api_key, commission_rate, payout_schedule, is_active).
- Existing points system uses `MinorPointsLedger` table via `MinorPointsService` (`app/Domain/Account/Services/MinorPointsService.php`).
- Points are awarded via `MinorPointsService::award()` with `source='merchant_bonus'` for idempotency.
- Transaction event sourcing via Spatie Event Sourcing captures QR payments at `SendMoneyService`.
- Merchant category blocking already in place via `MinorAccountAccessService::canSpendAtMerchant()`.

### Operational

-.ops runbook for merchant partner config and bonus overrides.
- Bonus calculation runs synchronously or via async job (non-blocking on payment).

### Compliance / Security

- No monetary value to bonus points (non-financial).
- Parentalcontrols still enforce merchant category blocks at bonus calculation time.
- Audit trail via point transaction history.

### Data / Observability

- Bonus metrics: total bonus points awarded, bonus redemptions, merchant participation.
- Mobile app can query bonus-eligible merchants.

## 4. Domain + Data Model Changes

### Architectural Decision REQUIRED

**Two merchant systems exist in codebase:**

1. `merchants` table (Domain/Commerce) — used by `MobileCommerceController` for crypto payments.
2. `merchant_partners` table (root Models) — the partner program table.

**DECISION**: This phase extends `merchant_partners` table (existing partner program).
- Rationale: Partner program is separate from payment processing.
- Bonus calculation happens AFTER successful payment, not during.

### Entities / Services

- Add `MinorMerchantBonusTransaction` model (persisted bonus award record for idempotency tracking).
- Add `MinorMerchantBonusService` (calculates bonus points via existing `MinorPointsService`).
- Extend `MerchantPartner` migration for minor-specific fields (FIX: add `tenant_id` for multi-tenancy).
- Use existing `MinorPointsLedger` with `source='merchant_bonus'` for point credit audit.

### Event Model

Not required — bonus awards are point transactions and already auditable via existing `PointTransaction` events.

### Migrations / Index Expectations

Additive migration on `merchant_partners`:

- **FIX CRITICAL**: `tenant_id` (uuid, nullable) — existing table lacks tenant isolation; REQUIRED for multi-tenancy.
- `bonus_multiplier` (decimal 3,2, default 2.0) — 2x matches business spec, not neutral 1.0.
- `min_age_allowance` (smallInteger, default 0)
- `category_slugs` (json, nullable)
- `is_active_for_minors` (boolean, default true)
- `bonus_terms` (text, nullable)
- `updated_by` (uuid, nullable) — FK to users table.

Add table `minor_merchant_bonus_transactions`:

- keys: `id` (uuid), `tenant_id`, `merchant_partner_id`, `minor_account_uuid`, `parent_transaction_uuid`
- fields: `bonus_points_awarded` (integer), `multiplier_applied` (decimal 3,2), `amount_szl` (decimal 12,2), `status` (enum: pending, awarded, failed), `error_reason` (string, nullable), `metadata` (json), timestamps
- indexes:
  - `(tenant_id, minor_account_uuid)`
  - `(merchant_partner_id, created_at)`
  - unique `(parent_transaction_uuid)` for idempotency dedup.

### Transaction Trigger Point

- **REQUIRED**: Integration hook in payment completion flow.
- **Known potential hooks**:
  - `App\Domain\AgentProtocol\Events\TransactionCompleted` (already exists, extends ShouldBeStored)
  - Event listener pattern: `Listener-handles-TransactionCompleted`
- **Decision**: Recommended is event listener (loose coupling).
  - Create: `app/Domain/Account/Listeners/AwardMerchantBonusOnTransactionCompleted.php`
  - Listens to: `TransactionCompleted` event
  - Condition: `$event->status === 'success'` AND transaction is QR payment to merchant partner

### Idempotency / Replay Guarantees

- One bonus record per `parent_transaction_uuid` — uniqueness constraint prevents duplicate bonus awards on payment retry.
- Bonus recalculation is safe: re-running calculation job does not duplicate if record exists.
- Non-financial: bonus points have no cash value.
- **Points precision**: Always use `floor()` — points must be integer. Calculation: `floor(amount_szl * POINTS_PER_SZL * multiplier)`.
  - Example: 15 SZL × 0.1 × 2.0 = 3.0 points ✓
  - Example: 20 SZL × 0.1 × 2.0 = 4.0 points ✓
  - Example: 25 SZL × 0.1 × 2.0 = 5.0 points ✓

## 5. API Contract Plan

### Add / Change

Extend existing Commerce merchant discovery (read-heavy):

- `GET /api/v1/commerce/merchants?include_minor_bonus=true`
  - returns: existing fields + `bonus_multiplier`, `min_age_allowance`, `category_slugs`, `is_active_for_minors`
- `GET /api/v1/commerce/merchants/{partnerId}/bonus-details`
  - returns: current bonus rate, age restrictions, terms, eligible categories

Add bonus calculation trigger (internal only, called from payment completion):

- `POST /internal/minor-merchant-bonus/award` (guarded by internal api key)
  - body: `transaction_uuid`, `merchant_partner_id`, `minor_account_uuid`, `amount_szl`
  - returns: `bonus_points_awarded`, `multiplier_applied`

### Response / Status Model

- Standard success envelope.
- 200: success with bonus details.
- 403: merchant not eligible for minor or category blocked (not 422 - not validation error).

### Auth / Authorization

- Discovery endpoints: authenticated, read-only.
- Internal bonus endpoint: internal API key only, not exposed to mobile.

## 6. Operator Workflow / Filament Blueprint

### Resources / Pages / Actions

- Extend existing `MerchantPartnerResource` with minor-specific fields:
  - `bonus_multiplier` (numeric input)
  - `min_age_allowance` (number input)
  - `category_slugs` (multi-select checkbox)
  - `is_active_for_minors` (toggle)
  - `bonus_terms` (textarea)

- Add list view filter: "Show minor-eligible only"

### Required Actions

- Toggle minor eligibility
- Edit bonus multiplier
- View bonus transaction history for a partner

### Audit Trail

- Every bonus config change logged via standard Filament audit.
- Bonus awards visible in point transaction history.

## 7. Failure Modes + Risk Register

### Financial Integrity Risks

- None — bonus points are non-monetary and non-transferable.
- Category blocking still enforced at bonus time (parent control preserved).

### Abuse Vectors

- Partner marking non-partner transactions as eligible.
- Inflation of bonus multiplier beyond configured cap.
- Minor bypassing age restrictions.
- Race condition: bonus awarded before transaction final (payment fails after bonus).

### Additional Controls REQUIRED

- **DOUBLE-VERIFY transaction status**: Only award bonus when transaction.status = 'completed' (not pending/failed).
- Transaction must be linked to valid `merchant_partners` entry, not arbitrary merchant.
- Log both transaction reference AND merchant_partner_id for audit.
- **Reversal handling**: If transaction reversed, bonus remains (non-monetary) — this is feature, not bug.

### Callback / Provider Race Conditions

- Bonus calculation happens post-transaction commit, not during.
- Idempotency key prevents duplicate awards.

### Manual Ops Failure Paths

- Misconfigured multiplier (too high) — cap at platform level.
- Partner deactivated mid-campaign — deactivate at calculation time.

### Controls

- Platform-level multiplier cap.
- Real-time eligibility check at calculation.

## 8. Verification Strategy

### Test Matrix

- Unit:
  - bonus multiplier calculation with integer floor precision.
  - age restriction enforcement (minor age < min_age_allowance → 0 points).
  - idempotency check (same transaction_id → 0 points, no duplicate).
  - category block bypass attempt rejection (parent blocked → 0 points).
  - transaction status verification (not completed → 0 points).
  - multiplier cap enforcement (>5.0 → capped at 5.0).
- Feature/API:
  - merchant partner list endpoint filtered by `include_minor_bonus`.
  - bonus details endpoint returns correct data.
  - internal bonus endpoint guards with internal API key.
  - 403 returned for ineligible.
- Filament:
  - minor eligibility toggle persists.
  - bonus multiplier validation (0-5 range enforced).
  - list filter works.
- Integration:
  - full QR payment flow with bonus award.
  - replay does not duplicate bonus.

### Reconciliation / Replay Cases

- Re-running bonus calculation job does not duplicate awards.
- Partner deactivation does not reverse past awards.

### Regression Suites

- Existing `MinorAccountAccessService` auth tests.
- Existing points transaction tests.
- Existing merchant partner CRUD tests.

### Static Analysis Expectations

- `phpstan` clean for new services/models.
- No new broad suppressions.

## 9. Implementation Plan (Sequenced)

1. Baseline and guardrails
   - verify Phase 10 lifecycle suite green.
   - define bonus multiplier caps and category slug enum.
2. Data model
   - additive migration on `merchant_partners`.
   - add `minor_merchant_bonus_transactions` table.
3. Domain service
   - implement `MinorMerchantBonusService`.
4. API contracts
   - add discovery and bonus detail endpoints.
5. Filament workflows
   - extend `MerchantPartnerResource` with minor fields.
6. Observability
   - bonus metrics in existing metrics bucket.
7. Final hardening
   - full test matrix, static analysis.

### Stop/Go Gates

- Gate A: migration + model tests pass.
- Gate B: bonus calculation idempotency proven.
- Gate C: age restriction rejection proven.
- Gate D: regression suites (lifecycle + points) remain green.

### Definition of Done

- Minors can earn bonus points on QR payments at partnered merchants.
- Parents can view and filter minor-eligible merchants.
- Bonus awards are auditable and idempotent.
- No financial side effects.
- CI test matrix and static analysis pass.

## 10. Open Questions

### MUST DECIDE BEFORE IMPLEMENTATION

1. **Transaction Trigger Point** (CRITICAL)
   - Where in codebase does QR payment complete → call bonus calculation?
   - Options: Event listener, service extension, queue job.
   - Current state: Unknown. Need to locate exact hook.

2. **merchant_partners tenant_id** (CRITICAL)
   - Existing table lacks `tenant_id` column.
   - Decision: Add nullable `tenant_id` in migration OR allow null for backward compatibility.

3. **Points Precision**
   - Use `floor()` (current spec) or `round()`?
   - Recommendation: floor() — partial points have no meaning.

### Business/Product Questions

4. What is the platform-level cap on bonus multiplier (recommend 5.0x)?
5. Should bonus eligibility be per-transaction or cumulative (monthly cap)?
6. Do we require merchants to opt-in per category or apply to all categories?
7. How do we handle bonus expiry (points never expire per original spec — confirm)?
8. Do we need location-based discovery (lat/lng) in this phase or defer?