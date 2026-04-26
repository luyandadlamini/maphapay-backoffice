# MaphaPay Wallet — Admin Revenue & Performance Implementation Plan

> **For agentic workers:** Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement **one task at a time** with review between tasks. Track progress with checkboxes.

**Goal:** Ship persona-aware Filament admin dashboards, a wallet-scoped `Revenue & Performance` area, Legacy navigation shelf, and governed pricing surfaces—**reusing** `BackofficeWorkspaceAccess`, `SettingsService` / `Settings`, and existing Filament resources—without parallel permission or fee stores.

**Architecture:** Thin composition layer (`AdminDashboardWidgets` or equivalent) maps **workspaces + Gates** → ordered widget class list for `Dashboard::getWidgets()`. Revenue pages are standard Filament `Page` classes with `canAccess()` aligned to the same access service. Aggregates prefer existing read paths; optional `finance_facts_daily` only after documented ADR gate.

**Tech stack:** Laravel, Filament v3, PHPUnit, Spatie permission (existing), MySQL tenant DB.

**Norm:** Before merging any task, ask: *Can this be simpler? More robust? Does it duplicate an existing implementation?*

**Specification:** [2026-04-24-maphapay-wallet-revenue-admin-spec.md](./2026-04-24-maphapay-wallet-revenue-admin-spec.md) (REQ IDs referenced below).

---

## Progress (living)

| Task | Status | Notes |
|------|--------|--------|
| Task 1 — `activeWorkspaces()` | **Done** | `BackofficeWorkspaceAccess` + Pest: `tests/Feature/Backoffice/BackofficeWorkspaceAccessTest.php` |
| Task 2 — `AdminDashboardWidgets` | **Done** | `app/Filament/Admin/Support/AdminDashboardWidgets.php` + `tests/Feature/Backoffice/AdminDashboardWidgetsTest.php` |
| Task 3 — Wire `Dashboard::getWidgets()` | **Done** | `app/Filament/Admin/Pages/Dashboard.php` + `tests/Feature/Filament/AdminDashboardPageTest.php`; trait `VisibleOnlyOnFinanceAdminSurface` |
| Task 4 — Persona doc | **Done** | [docs/06-DEVELOPMENT/admin-dashboard-personas.md](../../06-DEVELOPMENT/admin-dashboard-personas.md) |
| Task 5 — `RevenuePerformanceOverview` | **Done** | `app/Filament/Admin/Pages/RevenuePerformanceOverview.php`, blade, `tests/Feature/Filament/RevenuePerformanceOverviewPageTest.php`; **REQ-REV-001 activity v1:** `WalletRevenueActivityMetrics` + Filament form (range), reporting-currency line, KPI row from `transaction_projections` (transfer+withdrawal subset), trend placeholder, non-positive `revenue_targets` strip — **activity / volume, not recognized revenue** (ADR-006). |
| Task 6 — Nav group `Revenue & Performance` | **Done** | [app/Providers/Filament/AdminPanelProvider.php](../../../app/Providers/Filament/AdminPanelProvider.php) `navigationGroups` |
| Task 5b — Nav badge type fixes | **Done** | `CgoNotificationResource`, `SubscriberResource`, `SupportCaseResource`, `MtnMomoTransactionResource`, `GcuVotingProposalResource` — `?string` badges (fixes 500 when rendering admin nav for finance users) |
| Task 7 — Legacy shelf + config | **Done** | `config/maphapay.php`, `LegacyAdminNavigation`, last nav group in `AdminPanelProvider`, nine resources → legacy group; gate in `RespectsModuleVisibility`; `LegacyNavigationShelfTest` |
| Task 8 — `RevenueStreamsPage` | **Done** | `WalletRevenueStream` enum, `WalletRevenueStreamEvidence`, page + blade, `RevenueStreamsPageTest`; **REQ-REV-002 v1:** same `WalletRevenueActivityMetrics` — mapped cards for P2P (`transfer`) and cash-out (`withdrawal`); other streams remain pending. |
| Task 9 — Profitability + unit economics | **Done** | `RevenueProfitabilityPage`, `RevenueUnitEconomicsPage`, blades, `RevenueProfitabilityAndUnitEconomicsPageTest` |
| Task 10 — `RevenuePricingPage` | **Done** | Fees-only form via `SettingsFieldFactory` + `SettingsService` validate/update; governance reason; audit `backoffice.revenue_pricing.fees_saved`; `RevenuePricingPageTest` |
| Task 11 — Revenue targets | **Done** | `revenue_targets` migration, `RevenueTarget` + `RevenueTargetPolicy`, `RevenueTargetResource` (nav **Targets & forecasts**), `RevenueTargetAudit`, `RevenueTargetResourceTest` |
| Task 12 — Alerts v1 | **Done** | `RevenueAnomalyScanner` + `revenue:scan-anomalies` / `revenue:scan-anomalies:for-tenants` (per-tenant `tenancy()->initialize`); schedule **`for-tenants`** in `routes/console.php`; central DB notifications when tenancy active; `RevenueAnomalyScanCommandTest`, `RevenueAnomalyScanForTenantsCommandTest` (skip only if `TestCase::canCreateTenantDatabases()` is false — local: `scripts/reset-local-mysql-test-access.sh`; CI: MySQL root in feature job) |
| Task 13 — Mart gate (ADR) | **Done** | [docs/ADR/ADR-006-wallet-revenue-mart.md](../../ADR/ADR-006-wallet-revenue-mart.md) — Phase A defer mart + cache-wrapped reads; Phase B grain/retention/backfill/idempotency specified |
| Task 13b — Phase A perf | **Done** | `RevenuePricingPage` fee keys: `Cache::remember` + `maphapay.revenue_admin_read_cache_ttl_seconds` (`MAPPHAPAY_REVENUE_ADMIN_READ_CACHE_TTL`); workshop doc [wallet-revenue-recognition-matrix.md](../../06-DEVELOPMENT/wallet-revenue-recognition-matrix.md) |

---

## Traceability matrix (REQ → Tasks)

- REQ-NAV-001, REQ-NAV-002 → Tasks 6, 7  
- REQ-DASH-001, REQ-DASH-002 → Tasks 1, 2, 3, 4  
- REQ-REV-001 … REQ-REV-004 → Tasks 5, 8, 9 (REQ-REV-001/002 **activity v1** satisfied by `WalletRevenueActivityMetrics` + overview/streams UI — not recognized revenue; REQ-REV-003/004 remain blocked until COR / CAC-LTV contracts exist)  
- REQ-FEE-001, REQ-SEC-001, REQ-SEC-003 → Task 10  
- REQ-TGT-001 → Task 11  
- REQ-ALR-001 → Task 12 (minimal)  
- REQ-DATA-010 → Task 13 (decision + optional migration)  
- REQ-SEC-002, REQ-PERF-001, REQ-OBS-001 → Tasks 3, 5, 8, 13 + ongoing review  

---

## Prerequisite commands (local)

```bash
cd /Users/Lihle/Development/Coding/maphapay-backoffice
php artisan test --filter=AdminDashboardWidgetsTest
./vendor/bin/phpstan analyse app/Support/Backoffice app/Filament/Admin/Pages --memory-limit=1G
```

Adjust paths as your CI defines.

---

### Task 1: Extend `BackofficeWorkspaceAccess` for explicit workspace enumeration

**Covers:** REQ-DASH-001, REQ-SEC-001 (foundation).

**Files:**

- Modify: [app/Support/Backoffice/BackofficeWorkspaceAccess.php](app/Support/Backoffice/BackofficeWorkspaceAccess.php)
- Tests (as shipped): [tests/Feature/Backoffice/BackofficeWorkspaceAccessTest.php](../../../tests/Feature/Backoffice/BackofficeWorkspaceAccessTest.php) (Pest + `RolesAndPermissionsSeeder`)

**Design:** Add a method such as `public function activeWorkspaces(?Authenticatable $user = null): array` returning a **deduplicated** list of workspace strings the user may act within for **dashboard composition** (subset of all workspaces). Reuse the **same** match logic as `canAccess()`—do not fork conditions into a second switch.

- [x] **Step 1: Write failing unit tests** (superseded — shipped as Pest feature tests)

Create `tests/Unit/Support/Backoffice/BackofficeWorkspaceAccessTest.php` (original sketch; **implemented** as):

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Backoffice;

use App\Models\User;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BackofficeWorkspaceAccessTest extends TestCase
{
    public function test_active_workspaces_returns_empty_for_guest(): void
    {
        $svc = new BackofficeWorkspaceAccess();

        $this->assertSame([], $svc->activeWorkspaces(null));
    }

    // Add: super-admin receives all four workspaces; finance role receives finance only;
    // support with view-users receives support; user with no roles returns [].
}
```

- [x] **Step 2–4:** Implemented `ORDERED_WORKSPACES`, `activeWorkspaces()`, and Pest tests — run `php artisan test tests/Feature/Backoffice/BackofficeWorkspaceAccessTest.php`.

- [ ] **Step 5: Commit** (when batching PR)

```bash
git add app/Support/Backoffice/BackofficeWorkspaceAccess.php tests/Unit/Support/Backoffice/BackofficeWorkspaceAccessTest.php
git commit -m "feat(backoffice): enumerate active workspaces for dashboard composition"
```

---

### Task 2: Add `AdminDashboardWidgets` composer (pure PHP, no framework magic)

**Covers:** REQ-DASH-001.

**Files:**

- Create: [app/Filament/Admin/Support/AdminDashboardWidgets.php](app/Filament/Admin/Support/AdminDashboardWidgets.php)
- Tests (as shipped): [tests/Feature/Backoffice/AdminDashboardWidgetsTest.php](../../../tests/Feature/Backoffice/AdminDashboardWidgetsTest.php)

**Design:** Constructor-inject `BackofficeWorkspaceAccess`. Method `widgetsFor(?User $user): array` returns `array<class-string>` **ordered**. Start by **re-homing** the exact widget list currently in [app/Filament/Admin/Pages/Dashboard.php](app/Filament/Admin/Pages/Dashboard.php) for `platform_administration` / `finance` users; support users get a **short** list (e.g. `OperationsStatsOverview` if they can view it, plus links-only widgets later).

- [x] **Steps 1–3:** Composer + Pest tests (`AdminDashboardWidgetsTest`); mapping: finance **or** platform → 7 treasury widgets; else → `OperationsStatsOverview` + `FailedMomoTransactionsWidget`.

- [ ] **Step 4: Commit** (when batching PR)

---

### Task 3: Wire `Dashboard::getWidgets()` to composer + verify Filament boot

**Covers:** REQ-DASH-001, REQ-DASH-002, REQ-PERF-001 (indirectly—composer stays thin).

**Files:**

- Modify: [app/Filament/Admin/Pages/Dashboard.php](app/Filament/Admin/Pages/Dashboard.php)
- Tests (as shipped): [tests/Feature/Filament/AdminDashboardPageTest.php](../../../tests/Feature/Filament/AdminDashboardPageTest.php)

**Pattern:** Mirror setup from [tests/Feature/Backoffice/BackofficeGovernancePagesTest.php](tests/Feature/Backoffice/BackofficeGovernancePagesTest.php) (`RolesAndPermissionsSeeder`, Filament panel boot).

**Feature test sketch:**

```php
public function test_finance_user_sees_finance_widget_subset(): void
{
    // Arrange: user with approve-adjustments OR finance-equivalent role per seeder
    // Act: resolve Dashboard::getWidgets() via container or instantiate page
    // Assert: subset includes expected classes; excludes PrimaryBasketWidget if policy says support-only exclusion
}
```

- [x] Implement tests with **real** roles (`finance-lead`, `support-l1`) + Filament panel boot.

- [x] Replace static array in `getWidgets()` with `app(AdminDashboardWidgets::class)->widgetsFor(...)`.

- [x] **Harden widgets:** `VisibleOnlyOnFinanceAdminSurface` on basket/bank widgets; `canView()` on `OperationsStatsOverview` and `FailedMomoTransactionsWidget`. Account resource widgets unchanged (see persona doc).

- [x] Run: `php artisan test tests/Feature/Filament/AdminDashboardPageTest.php`

- [ ] Commit (when batching PR).

---

### Task 4: Document persona → widget matrix in repo (single doc, no drift)

**Covers:** AC-005, operational clarity.

**Files:**

- Create: [docs/06-DEVELOPMENT/admin-dashboard-personas.md](docs/06-DEVELOPMENT/admin-dashboard-personas.md) (create directory `docs/06-DEVELOPMENT/` if absent in your tree)

Content must list: workspace, allowed widgets, forbidden widgets, and link to REQ IDs.

- [x] Doc created; commit when batching PR.

---

### Task 5: `RevenuePerformanceOverview` Filament page (shell + access)

**Covers:** REQ-REV-001, REQ-SEC-001, REQ-SEC-002.

**Files:**

- Create: [app/Filament/Admin/Pages/RevenuePerformanceOverview.php](../../../app/Filament/Admin/Pages/RevenuePerformanceOverview.php)
- Create: [resources/views/filament/admin/pages/revenue-performance-overview.blade.php](../../../resources/views/filament/admin/pages/revenue-performance-overview.blade.php)
- Create: [tests/Feature/Filament/RevenuePerformanceOverviewPageTest.php](../../../tests/Feature/Filament/RevenuePerformanceOverviewPageTest.php)

**Rules:**

- `public static function canAccess(): bool` uses `BackofficeWorkspaceAccess` for `finance` **or** `platform_administration` only (**v1 default** — support uses persona home dashboard, not revenue pages). If product later wants read-only revenue tiles for support, add a **separate** guarded page to avoid widening `canAccess` accidentally.

- View: date range form Livewire-safe; no raw SQL in blade.

- Tests: 403 for unauthorized; 200 for finance.

- [x] Implemented (shell + Pest). [ ] Commit when batching PR.

---

### Task 6: Register `Revenue & Performance` navigation group + sort keys

**Covers:** REQ-NAV-001.

**Files:**

- Modify: [app/Providers/Filament/AdminPanelProvider.php](../../../app/Providers/Filament/AdminPanelProvider.php)
- Page: `RevenuePerformanceOverview` sets `$navigationGroup = 'Revenue & Performance'` + `$navigationSort = 1`

- [x] Group registered after `Transactions`; GET page as `finance-lead` asserts 200 (covers nav build + access).

- [ ] Commit when batching PR.

---

### Task 7: Legacy navigation shelf + config gate

**Covers:** REQ-NAV-002, AC-004.

**Files:**

- Create: [config/maphapay.php](config/maphapay.php) — new file with `show_legacy_admin_nav` boolean default `false` (no `config/maphapay.php` exists today)
- Modify: selected Filament resources’ `$navigationGroup` and optional `public static function shouldRegisterNavigation(): bool`
- Create: [tests/Feature/Filament/LegacyNavigationShelfTest.php](tests/Feature/Filament/LegacyNavigationShelfTest.php)

**Rule:** `shouldRegisterNavigation` returns `true` always for super-admin **or** when `config('maphapay.show_legacy_admin_nav')` is true; otherwise false for **legacy-tagged** resources only—do **not** hide core wallet resources.

- [x] Product list must be applied—until then, use **minimum** set from planning doc: `OrderResource`, `OrderBookResource`, `DeFiPositionResource`, `BridgeTransactionResource`, `PollResource`, `VoteResource`, `GcuVotingProposalResource`, `CertificateResource`, `VirtualsAgentResource`.

- [ ] Commit.

---

### Task 8: `RevenueStreamsPage` (read-only cards + explicit pending states)

**Covers:** REQ-REV-002.

**Files:**

- Create: [app/Filament/Admin/Pages/RevenueStreamsPage.php](app/Filament/Admin/Pages/RevenueStreamsPage.php)
- Create: [resources/views/filament/admin/pages/revenue-streams-page.blade.php](resources/views/filament/admin/pages/revenue-streams-page.blade.php)
- Create: [app/Domain/Analytics/WalletRevenueStream.php](app/Domain/Analytics/WalletRevenueStream.php) — backed enum or const list matching spec section 6 (single source for labels + stream codes)

- [x] No fabricated numbers: if extraction not implemented, card shows “Pending finance mapping” badge.

- [x] Tests: page loads for finance; streams enum covers all spec codes.

- [ ] Commit.

---

### Task 9: `ProfitabilityPage` + `UnitEconomicsPage` (blocked / honest empty states)

**Covers:** REQ-REV-003, REQ-REV-004.

**Files:**

- Create: [app/Filament/Admin/Pages/RevenueProfitabilityPage.php](app/Filament/Admin/Pages/RevenueProfitabilityPage.php)
- Create: [app/Filament/Admin/Pages/RevenueUnitEconomicsPage.php](app/Filament/Admin/Pages/RevenueUnitEconomicsPage.php)
- Create matching blade views + feature tests for access + empty states.

- [x] Shell pages + tests (blocked / not-connected copy per REQ-REV-003 / REQ-REV-004).

- [ ] Commit.

---

### Task 10: `RevenuePricingPage` — reuse settings, no duplicate validation

**Covers:** REQ-FEE-001, REQ-SEC-001, REQ-SEC-003.

**Preferred implementation (simplest robust):** The page embeds the **same** `SettingsService::getConfig()['fees']` field definitions by reusing form components **or** redirects to `/admin/settings` with hash `#fees` and contextual Filament notification “Edit fees in Platform Settings”.

**Files:**

- Create: [app/Filament/Admin/Pages/RevenuePricingPage.php](app/Filament/Admin/Pages/RevenuePricingPage.php), [resources/views/filament/admin/pages/revenue-pricing-page.blade.php](resources/views/filament/admin/pages/revenue-pricing-page.blade.php), [app/Filament/Admin/Support/SettingsFieldFactory.php](app/Filament/Admin/Support/SettingsFieldFactory.php), [tests/Feature/Filament/RevenuePricingPageTest.php](tests/Feature/Filament/RevenuePricingPageTest.php)
- Modify: [app/Filament/Admin/Pages/Settings.php](app/Filament/Admin/Pages/Settings.php) — delegates field construction to `SettingsFieldFactory` (single source with Revenue page)

- [x] Tests: non-finance cannot access; finance can; embed path — save requires governance reason (Livewire `assertHasErrors`).

- [ ] Commit.

---

### Task 11: Targets storage (minimal) — only if no existing model fits

**Covers:** REQ-TGT-001.

**Pre-step:** Search codebase for `revenue_target`, `Target`, KPI tables. If exists, **extend** instead of new table.

**If new table required:**

- Create migration under `database/migrations/tenant/` (follow tenant conventions used in repo)
- Create model `RevenueTarget` with policy class
- Filament page or section on `TargetsAndForecastsPage` for CRUD restricted by workspace

- [x] **Implemented:** No existing revenue-target store — added `revenue_targets` (unique `period_month` + `stream_code`), `RevenueTarget` + `RevenueTargetPolicy`, Filament **`RevenueTargetResource`** with navigation label **Targets & forecasts** (covers plan “TargetsAndForecastsPage” surface without a second empty shell page). Writes audited via `RevenueTargetAudit` + `AdminActionGovernance`.

- [x] Tests: finance access + `Gate` create; support blocked (`RevenueTargetResourceTest`); tests migrate tenant migration path when table missing.

- [ ] Commit.

---

### Task 12: Alerts v1 (scheduler stub + notification)

**Covers:** REQ-ALR-001.

**Files:**

- [app/Domain/Analytics/Services/RevenueAnomalyScanner.php](../../../app/Domain/Analytics/Services/RevenueAnomalyScanner.php) — shared read-only scan; finance notifications use **central** `User` query only when tenancy is initialized (tenant DB context).
- [app/Console/Commands/RevenueAnomalyScan.php](../../../app/Console/Commands/RevenueAnomalyScan.php) — single-DB / diagnostics entrypoint.
- [app/Console/Commands/RevenueAnomalyScanForTenants.php](../../../app/Console/Commands/RevenueAnomalyScanForTenants.php) — production: `tenancy()->initialize` per landlord tenant; `--tenant=` for one tenant.
- Schedule **`revenue:scan-anomalies:for-tenants`** in [routes/console.php](../../../routes/console.php).

- [x] No third-party vendor SDK.

- [x] Tests: [tests/Feature/Console/RevenueAnomalyScanCommandTest.php](../../../tests/Feature/Console/RevenueAnomalyScanCommandTest.php), [tests/Feature/Console/RevenueAnomalyScanForTenantsCommandTest.php](../../../tests/Feature/Console/RevenueAnomalyScanForTenantsCommandTest.php) (skipped when `TestCase::canCreateTenantDatabases()` is false; CI runs them in feature batch with MySQL root).

- [ ] Commit.

---

### Task 13: Data mart gate (ADR + optional implementation)

**Covers:** REQ-DATA-010, REQ-PERF-001.

**Files:**

- Create: [docs/ADR/ADR-006-wallet-revenue-mart.md](../../ADR/ADR-006-wallet-revenue-mart.md)

**ADR must answer:**

- Why `TransactionProjection` / existing batch summaries are insufficient.  
- Exact grain (daily? hourly?), retention, backfill strategy, idempotency keys.  
- Query patterns for Filament widgets and indexes.

- [x] ADR outcome **defer mart (Phase A)**; cache-wrapped reads + strict windows are the mandated interim pattern (TTL per widget when implemented).

- [ ] Commit.

---

## Quality gates (every PR touching this work)

Run before merge:

```bash
php artisan test
./vendor/bin/phpstan analyse --memory-limit=1G
```

If project uses Pint:

```bash
./vendor/bin/pint --test
```

---

## Plan self-review (writing-plans)

**Spec coverage:** Each REQ-ID maps in the traceability matrix to at least one task.

**Placeholder scan:** No “TBD” tasks; business decisions live in spec section 11, not in tasks.

**Reuse:** Tasks 1–3 and 10 explicitly reuse workspace + settings infrastructure; Task 13 forces ADR before new tables.

**Consistency:** Workspace strings remain those defined in `BackofficeWorkspaceAccess`; new workspaces require Task 1 extension first.

---

## Execution handoff

Plan complete:

1. **Subagent-driven** — one task per agent, review between tasks.  
2. **Inline** — single session with checkpoints after Tasks 3, 7, 10, 13.

Which approach do you want for execution?
