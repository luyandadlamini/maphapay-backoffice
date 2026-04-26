# Phase 12 Code Review
**Date:** 2026-04-26
**Reviewer:** Claude (automated via superpowers:code-reviewer agent)
**Branch:** main (post-merge)
**Verdict:** NEEDS-FIXES

---

## Scope

Phase 12 added:

- `MinorCardRequestService` / `MinorCardService` — card request lifecycle and freeze/unfreeze with guardian authorization
- Filament admin resources: `MinorCardRequestResource`, `RevenueTargetResource`, `RevenueTargetAudit`
- Filament revenue admin pages: `RevenuePricingPage`, `RevenueStreamsPage`, `RevenuePerformanceOverview`, `RevenueProfitabilityPage`, `RevenueUnitEconomicsPage`
- Console commands: `ExpireMinorCardRequests`, `RevenueAnomalyScan`, `RevenueAnomalyScanForTenants`
- New domain: `app/Domain/Analytics/` (wallet revenue analytics)
- Migrations: `create_minor_card_limits_table`, `create_minor_card_requests_table`, `add_minor_account_uuid_to_cards_table`, `create_revenue_targets_table`, `add_deleted_at_to_revenue_targets_table`

---

## Step 1 — Tests

**Result: PASS** (effectively)

Four failures observed in parallel run — none are Phase 12 regressions:

| Test | Disposition |
|---|---|
| `HealthCheckerTest` — "unhealthy status when check fails" | Acceptable pre-existing failure (listed in review brief) |
| `AmlScreeningAggregateTest` — "tracks state correctly" | Parallel-collision artifact — passes in isolation |
| `LiquidityPoolServiceTest` — "get provider positions returns positions with pools" | Parallel-collision artifact — passes in isolation |
| `BackfillAccountMembershipsTest` — "it backfills missing memberships..." | Pre-existing DB isolation fragility; count assertion breaks when other suites seed data concurrently. Not a Phase 12 change. |

No Phase 12 feature test or unit test failed.

---

## Step 2 — PHPStan

**Result: PASS**

Zero errors. `phpstan-baseline-phase12.neon` is correctly included in `phpstan.neon` (line 24).

---

## Step 3 — Code Style

**Result: PASS**

Zero files flagged by php-cs-fixer.

---

## Step 4 — Security Findings

| Item | Area | Result |
|---|---|---|
| A | `MinorCardRequestService::createRequest()` — `isMinor` AND `isGuardian` checks, tier enforcement, duplicate guard | PASS |
| B | `MinorCardService::freezeCard/unfreezeCard` — `&&` vs `\|\|` guard logic | **CRITICAL FAIL** |
| C | `Card::minorAccount()` — third argument `'uuid'` present | PASS |
| D | `RevenueTargetAudit` — `recordSaved()` and `recordDeleted()` both present | PASS |
| E | `RevenuePricingPage` — scoped `Cache::tags(['revenue','pricing'])->flush()` | PASS |
| F | `RevenueTargetResource` — cross-field currency validation + `->searchable()` | PASS |
| G | `SmsPricingService` — `bcmul()` not `ceil()` for USDC conversion | PASS |
| H | `PublicMinorFundingLinkController` — length guard + `hash('sha256', $token)` lookup | PASS |
| I | `MinorFamilyIntegrationService` — `Str::random(64)`, hash-only stored | PASS |
| J | `CoGuardianController::store()` — SCA called before invite creation | PASS |

### Finding B — Critical: `&&` instead of `||` in guardian guard

**File:** `app/Domain/Account/Services/MinorCardService.php`, lines 62 and 74

Both `freezeCard()` and `unfreezeCard()` use:

```php
// BROKEN — null $minor short-circuits to false, skipping guardian check entirely
if ($minor instanceof Account && ! $this->accessService->hasGuardianAccess($guardian, $minor)) {
    throw new InvalidArgumentException(...);
}
```

When `$card->minorAccount` returns `null` (all pre-Phase-12 cards without a backfilled `minor_account_uuid`, or any non-minor card), the condition evaluates to `false` and **no exception is thrown**. Any authenticated user can freeze or unfreeze any such card without authorization.

**Fix:**

```php
if (! $minor instanceof Account || ! $this->accessService->hasGuardianAccess($guardian, $minor)) {
    throw new InvalidArgumentException(...);
}
```

### Additional Finding — `approve()` / `deny()` have no internal authorization guard

**File:** `app/Domain/Account/Services/MinorCardRequestService.php`, lines 63–90

Both methods accept an arbitrary `User $guardian` and perform no authorization check internally. Enforcement is currently delegated entirely to the Filament layer. Any future API caller that omits the pre-authorization step will silently succeed.

**Recommendation:** Add an internal `hasGuardianAccess()` call at the top of both methods.

---

## Step 5 — Test Coverage

All expected test files are present. One critical gap:

**`tests/Unit/Domain/Account/Services/MinorCardServiceTest.php`**

The `freeze_card_requires_guardian` and `unfreeze_card_requires_guardian` tests exercise the `hasGuardianAccess() == false` branch against a card that *has* a valid `minorAccount`. There is no test for the case where `$card->minorAccount` is `null`. This is precisely the path the `&&` bug silently bypasses — the security regression is **not caught by the existing test suite**.

All other expected files confirmed present with happy-path and negative-case coverage:

| Test file | Status |
|---|---|
| `tests/Unit/Domain/Account/Services/MinorCardRequestServiceTest.php` | Present — tier rejection, non-guardian, duplicate, expiry, type detection |
| `tests/Unit/Domain/Account/Services/MinorCardServiceTest.php` | Present — guardian check, limit resolution (null-minor case missing — see above) |
| `tests/Feature/Http/Controllers/Api/MinorCardControllerTest.php` | Present |
| `tests/Feature/Http/Controllers/Api/CoGuardianScaTest.php` | Present |
| `tests/Feature/Http/Controllers/Api/PublicMinorFundingLinkTokenHashTest.php` | Present |
| `tests/Feature/Http/Controllers/Api/MinorPermissionLevelIdempotencyTest.php` | Present |
| `tests/Feature/Filament/RevenuePricingPageTest.php` | Present |
| `tests/Feature/Filament/RevenueTargetResourceTest.php` | Present |
| `tests/Feature/Console/RevenueAnomalyScanCommandTest.php` | Present |
| `tests/Unit/Domain/Analytics/Services/WalletRevenueActivityMetricsTest.php` | Present |
| `tests/Unit/Domain/SMS/SmsPricingServiceBcmathTest.php` | Present |

---

## Step 6 — Migrations

| Migration | Result |
|---|---|
| `create_minor_card_limits_table` | PASS — reversible, `decimal`, UUID FK, correct `onDelete('cascade')` |
| `create_minor_card_requests_table` | PASS — reversible, correct types, `onDelete('cascade')` |
| `add_minor_account_uuid_to_cards_table` | PASS — nullable column addition, no table lock risk |
| `create_revenue_targets_table` | PASS — reversible, `decimal(18,2)`, correct structure |
| `add_deleted_at_to_revenue_targets_table` | **CRITICAL FAIL** — see below |

### Migration Issue 1 — Wrong directory (critical)

**File:** `database/migrations/2026_04_26_000000_add_deleted_at_to_revenue_targets_table.php`

This migration is in the **landlord** directory, but `revenue_targets` is created in `database/migrations/tenant/` and `RevenueTarget` uses `UsesTenantConnection`. The `deleted_at` column will be added to the landlord database only — `SoftDeletes` will fail at runtime on all tenant databases.

**Fix:** Move to `database/migrations/tenant/`.

### Migration Issue 2 — Duplicate timestamps (low)

Three tenant migrations share timestamp `2026_04_24_002653`:
- `create_minor_card_limits_table`
- `create_minor_card_requests_table`
- `add_minor_account_uuid_to_cards_table`

Ordering within the same timestamp is non-deterministic in fresh installs. Assign sequential distinct timestamps.

---

## Step 7 — Code Conventions

Spot-checked: `MinorCardRequestService.php`, `MinorCardService.php`, `RevenuePricingPage.php`, `RevenueTargetAudit.php`, `ExpireMinorCardRequests.php`.

All five have `<?php declare(strict_types=1);`, correct import ordering, no `$guarded = []`, and no `Cache::flush()`.

One violation:

**`app/Domain/Account/Constants/MinorCardConstants.php`, lines 11–15**

```php
const DEFAULT_DAILY_LIMIT = '2000.00';
const DEFAULT_MONTHLY_LIMIT = '10000.00';
const DEFAULT_SINGLE_TRANSACTION_LIMIT = '1500.00';
```

Monetary thresholds must reference `config/minor_family.php` or `config/banking.php`. These should be read via `config('minor_family.card_limits.daily_default', '2000.00')` etc.

---

## Verdict: NEEDS-FIXES

### Required before next release

| Priority | File | Fix |
|---|---|---|
| 1 — CRITICAL | `app/Domain/Account/Services/MinorCardService.php:62,74` | Change `&&` to `\|\|` in both `freezeCard` and `unfreezeCard` guardian guards |
| 2 — CRITICAL | `database/migrations/2026_04_26_000000_add_deleted_at_to_revenue_targets_table.php` | Move to `database/migrations/tenant/` |
| 3 — IMPORTANT | `tests/Unit/Domain/Account/Services/MinorCardServiceTest.php` | Add test case where `$card->minorAccount` is `null` — must fail before fix #1 is applied, pass after |
| 4 — IMPORTANT | `app/Domain/Account/Services/MinorCardRequestService.php:63–90` | Add internal `hasGuardianAccess()` guard inside `approve()` and `deny()` |
| 5 — SUGGESTION | `app/Domain/Account/Constants/MinorCardConstants.php:11–15` | Move monetary defaults to `config/minor_family.php` |
| 6 — SUGGESTION | Tenant migrations with timestamp `2026_04_24_002653` | Assign sequential distinct timestamps |
