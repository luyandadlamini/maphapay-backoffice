# Minor Accounts Phase 11 Merchant & QR Integration Implementation Plan

Date: 2026-04-23

## CRITICAL WARNINGS - READ FIRST

> **WARNING 1**: This phase extends `merchant_partners` table, NOT `merchants` table. These are separate systems.
> - `merchants` = Domain/Commerce for crypto payments (controlled by MobileCommerceController)
> - `merchant_partners` = root Models for partner program (what this phase extends)

> **WARNING 2**: `merchant_partners` table lacks `tenant_id` column. This is a multi-tenancy gap from Phase 10.
> - Migration MUST add nullable `tenant_id` for new records.

> **WARNING 3**: Points precision issue
> - Points MUST be integers.
> - Use: `floor(amount_szl * POINTS_PER_SZL * multiplier)`

---

## Overview

This plan implements Phase 11: Merchant & QR Integration for minor accounts, following the spec at `docs/superpowers/specs/2026-04-23-minor-accounts-phase11-merchant-qr-integration-spec.md`.

**Primary objective**: Enable minor account users to earn bonus points (2x multiplier) on QR payments at partnered Eswatini merchants.

**Scope**: Backend merchant program infrastructure, bonus calculation service, API endpoints, and Filament management surface.

---

## Task Breakdown

### Phase 11.1: Baseline & Guardrails

- [ ] **11.1.1** Verify Phase 10 lifecycle suite is green
  - Run: `./vendor/bin/pest --filter=lifecycle`
  - Run: `php artisan minor-accounts:lifecycle-evaluate --dry-run`

- [ ] **11.1.2** Define bonus constant caps
  - Create or update `app/Domain/Account/Constants/MerchantBonusConstants.php`:
    ```php
    const MAX_BONUS_MULTIPLIER = 5.0;
    const DEFAULT_BONUS_MULTIPLIER = 2.0;  // 2x as per business spec
    const MIN_AGE_DEFAULT = 0;
    const POINTS_PER_SZL = 0.1;  // 1 point per 10 SZL spent
    const SOURCE_MERCHANT_BONUS = 'merchant_bonus';
    ```
  - Note: Uses existing `MinorPointsService` from `app/Domain/Account/Services/MinorPointsService.php`

- [ ] **11.1.3** Verify category slug enum exists
  - Check: `app/Domain/Budget/Constants/BudgetCategorySlugs.php`
  - If missing, create with standard slugs: `groceries`, `retail`, `dining`, `entertainment`, `airtime`, `utilities`, `health`, `general`

### Phase 11.2: Data Model

- [ ] **11.2.1** Create additive migration on `merchant_partners`
  - File: `database/migrations/tenant/2026_04_23_xxxxxx_add_minor_bonus_fields_to_merchant_partners_table.php`
  - **CRITICAL**: Add `tenant_id` (uuid, nullable) - existing table lacks multi-tenancy!
  - Fields: `bonus_multiplier` (decimal 3,2, default 2.0), `min_age_allowance` (smallInt, default 0), `category_slugs` (json, nullable), `is_active_for_minors` (boolean, default true), `bonus_terms` (text, nullable), `updated_by` (uuid, nullable)
  - Add indexes: `(is_active_for_minors)`, `(tenant_id, is_active_for_minors)`

- [ ] **11.2.2** Create `minor_merchant_bonus_transactions` table
  - File: `database/migrations/tenant/2026_04_23_xxxxxx_create_minor_merchant_bonus_transactions_table.php`
  - Keys: `id` (uuid), `tenant_id`, `merchant_partner_id`, `minor_account_uuid`, `parent_transaction_uuid`
  - Fields: `bonus_points` (integer), `multiplier_applied` (decimal 3,2), `base_points` (integer), `status` (enum: awarded, redeemed, expired, reversed), `metadata` (json), timestamps
  - Indexes: `(tenant_id, minor_account_uuid)`, `(merchant_partner_id, created_at)`, unique `(parent_transaction_uuid)`

- [ ] **11.2.3** Create `MinorMerchantBonusTransaction` model
  - File: `app/Domain/Reward/Models/MinorMerchantBonusTransaction.php`
  - Relationships: belongsTo `MerchantPartner`, belongsTo `Account` (minor)

- [ ] **11.2.4** Update `MerchantPartner` model for minor eligibility
  - Add: `bonus_multiplier`, `min_age_allowance`, `category_slugs`, `is_active_for_minors`, `bonus_terms` to `$fillable`
  - Add: `isActiveForMinorsScope` global scope (optional, can filter in query instead)

### Phase 11.3: Domain Service

- [ ] **11.3.0** INVESTIGATE: Find transaction completion hook point (BLOCKER)
  - Search codebase for where QR payment to merchant completes.
  - Possible locations:
    - `SendMoneyStoreController::complete()`
    - `MobileCommerceController::processPayment()`
    - Event listener for `TransactionCompleted` or similar
    - Queue job after payment confirmation
  - **DELIVERABLE**: Document exact method/event to extend or subscribe to.
  - If no hook exists, create NEW integration point WITH discussion first.

- [ ] **11.3.1** Create `MinorMerchantBonusService`
  - File: `app/Domain/Account/Services/MinorMerchantBonusService.php`
  - Methods:
    - `calculateBonus(Transaction $transaction, MerchantPartner $partner, int $basePoints): array`
    - `awardBonus(..., User $actor): MinorMerchantBonusTransaction`
    - `getEligibleMerchants(array $filters): Collection`
    - `getBonusDetails(MerchantPartner $partner): array`
    - `checkEligibility(Account $minor, MerchantPartner $partner): bool`
  - Idempotency: check `MinorMerchantBonusTransaction::where('parent_transaction_uuid', $txn->uuid)->exists()`
  - Uses existing `MinorPointsService::award()` for actual point credits

- [ ] **11.3.2** Implement bonus calculation logic
  - Check merchant `is_active_for_minors` AND `is_active`
  - Check minor age >= `min_age_allowance` (from account DOB)
  - Check transaction category in `merchant.category_slugs` (if set)
  - Check parent has NOT blocked this merchant category via `MinorAccountAccessService::canSpendAtMerchant()`
  - **CRITICAL**: Apply multiplier cap from constants (max 5.0)
  - **CRITICAL**: Use `floor()` for points — NEVER award fractional points
  - Points calculation: `floor(amount_szl * MerchantBonusConstants::POINTS_PER_SZL * $multiplier)`
  - Return: `['bonus_points_awarded' => int, 'multiplier_applied' => decimal, 'eligible' => bool]`

- [ ] **11.3.3** Integrate with transaction completion
  - In `SendMoneyService::complete()` or via event listener
  - Trigger bonus calculation after successful QR payment to merchant
  - Use async queue job for non-blocking

### Phase 11.4: API Contracts

- [ ] **11.4.1** Add merchant partner discovery endpoint
  - Extend existing `MobileCommerceController` from `app/Domain/Commerce/Http/Controllers/Api/MobileCommerceController.php`
  - Route: `GET /api/v1/commerce/merchants` (existing)
  - Query params: `include_minor_bonus` (bool), `category` (string), `search` (string)
  - Response: paginated list with minor-specific fields

- [ ] **11.4.2** Add bonus details endpoint
  - Route: `GET /api/v1/commerce/merchants/{partnerId}/bonus-details`
  - Response: bonus_rate, min_age, terms, eligible_categories

- [ ] **11.4.3** Add internal bonus calculation endpoint
  - Route: `POST /internal/minor-merchant-bonus/calculate`
  - Guard: internal API key (`X-Internal-Api-Key`)
  - Body: `transaction_uuid`, `merchant_partner_id`, `minor_account_uuid`, `base_amount`
  - Response: `bonus_points_awarded`, `multiplier_applied`

- [ ] **11.4.4** Add API resource transformers
  - Add minor-specific fields to `MerchantPartnerResource`

### Phase 11.5: Filament Workflows

- [ ] **11.5.1** Extend `MerchantPartnerResource` form
  - Add fields: `bonus_multiplier`, `min_age_allowance`, `category_slugs` (checkbox), `is_active_for_minors` (toggle), `bonus_terms`

- [ ] **11.5.2** Add list filters
  - Add filter: "Show minor-eligible only" (checkbox)

- [ ] **11.5.3** Add relation manager for bonus transactions
  - List bonus transactions per merchant partner
  - Columns: minor account, bonus points, date, status

- [ ] **11.5.4** Add actions
  - `ToggleMinorEligibilityAction`
  - `ViewBonusHistoryAction`

### Phase 11.6: Observability

- [ ] **11.6.1** Add bonus metrics to existing metrics bucket
  - `minor_merchant_bonus.awarded_total` (counter)
  - `minor_merchant_bonus.redeemed_total` (counter)
  - `minor_merchant_bonus.active_partners` (gauge)

- [ ] **11.6.2** Add log context
  - Log bonus calculation with context: `merchant_id`, `minor_account_id`, `multiplier`

### Phase 11.7: Final Hardening

- [ ] **11.7.1** Write unit tests (EXHAUSTIVE EDGE CASES)
  - Test: bonus calculation with valid partner → correct integer points
  - Test: bonus calculation with 25 SZL × 2x = 5 points (floor works)
  - Test: bonus calculation with 20 SZL × 2x = 4 points ✓
  - Test: bonus calculation with 15 SZL × 2x = 3 points ✓
  - Test: bonus calculation with inactive partner → returns 0 (eligible=false)
  - Test: bonus calculation with inactive_for_minors → returns 0
  - Test: bonus calculation with age restriction (12 < 13) → returns 0
  - Test: bonus calculation with blocked category → returns 0
  - Test: idempotency prevents duplicate award (same transaction_id)
  - Test: multiplier cap enforced (6.0 → capped at 5.0)
  - Test: multiplier cap enforced (5.0 → stays at 5.0)
  - Test: transaction not completed → returns 0
  - Test: null merchant partner → graceful failure, no crash
  - Test: negative amount_szl → returns 0 (defensive)
  - Test: zero amount_szl → returns 0

- [ ] **11.7.2** Write feature/API tests
  - Test: `GET /api/merchants/partners` returns minor fields
  - Test: `GET /api/merchants/partners?include_minor_eligible=true` filters correctly
  - Test: `GET /api/merchants/partners/{id}/bonus-details` returns correct data
  - Test: internal endpoint calculates and awards bonus

- [ ] **11.7.3** Write Filament tests
  - Test: minor eligibility toggle persists
  - Test: bonus multiplier validation (0-5 range)
  - Test: list filter works

- [ ] **11.7.4** Run static analysis
  - `./vendor/bin/phpstan analyse --memory-limit=2G`
  - `./vendor/bin/php-cs-fixer fix --dry-run --diff`

- [ ] **11.7.5** Run regression suites
  - `./vendor/bin/pest --filter=MinorAccount`
  - `./vendor/bin/pest --filter=MerchantPartner`

---

## Stop/Go Gates

| Gate | Criteria | Verification Command |
|------|----------|---------------------|
| 11.A | Migration + model tests pass | `./vendor/bin/pest tests/Feature/Database/MinorMerchantBonus*` |
| 11.B | Bonus idempotency proven | `./vendor/bin/pest tests/Unit/Domain/Reward/Services/MinorMerchantBonusServiceTest.php --filter=idempotency` |
| 11.C | Age restriction rejection proven | `./vendor/bin/pest tests/Unit/Domain/Reward/Services/MinorMerchantBonusServiceTest.php --filter=age` |
| 11.D | Regression suites (Phase 10 + points) green | `./vendor/bin/pest --filter="MinorAccount" && ./vendor/bin/pest --filter="Point"` |

---

## Definition of Done

- [ ] Minors can earn bonus points on QR payments at partnered merchants (2x default)
- [ ] Parents can view bonus details and filter minor-eligible merchants
- [ ] Operators can configure bonus rates and eligibility in Filament
- [ ] Bonus awards are idempotent and auditable
- [ ] No financial side effects (points are non-monetary)
- [ ] All tests pass: unit, feature, integration
- [ ] Static analysis clean (phpstan + php-cs-fixer)
- [ ] Observability hooks in place

---

## File Manifest

### New Files

| File | Description |
|------|-------------|
| `app/Domain/Account/Constants/MerchantBonusConstants.php` | Bonus caps and constants |
| `app/Domain/Account/Models/MinorMerchantBonusTransaction.php` | Bonus award record (for tracking) |
| `app/Domain/Account/Services/MinorMerchantBonusService.php` | Bonus calculation service |
| `app/Domain/Commerce/Http/Controllers/Api/MerchantPartnerBonusController.php` | Extended Commerce controller |
| `app/Filament/Admin/Resources/MerchantPartnerResource.php` | Extended with minor fields |
| `database/migrations/tenant/2026_04_23_xxxxxx_add_minor_bonus_fields_to_merchant_partners_table.php` | Partner table extension |
| `database/migrations/tenant/2026_04_23_xxxxxx_create_minor_merchant_bonus_transactions_table.php` | Bonus tracking table |
| `tests/Unit/Domain/Account/Services/MinorMerchantBonusServiceTest.php` | Unit tests |
| `tests/Feature/Http/Controllers/Api/MerchantPartnerBonusEndpointTest.php` | API tests |
| `tests/Feature/Filament/MerchantPartnerMinorEligibilityTest.php` | Filament tests |

### Modified Files

| File | Change |
|------|--------|
| `app/Models/MerchantPartner.php` | Add minor fields tofillable |
| `app/Http/Resources/Api/MerchantPartnerResource.php` | Add minor fields to transform |
| `routes/api.php` | Add discovery + bonus routes |
| `app/Providers/AppServiceProvider.php` | Register service if needed |

---

## Dependencies

- **Blockers**: None (Phase 10 is complete, merchant partners table exists)
- **Required by**: Mobile app for merchant discovery (future Phase 13)
- **Parallel**: None

---

## Notes

- Bonus points arenon-monetary — no financial risk from calculation bugs
- Category blocking enforcement is additive — existing `MinorAccountAccessService` checks remain in place
- Use async queue for bonus calculation to not block payment completion