# Admin home dashboard — persona matrix

This document is the **single source of truth** for which Filament dashboard widgets apply to which back office persona. Implementation: `App\Filament\Admin\Support\AdminDashboardWidgets` and `App\Support\Backoffice\BackofficeWorkspaceAccess::activeWorkspaces()`.

## Workspaces (from `BackofficeWorkspaceAccess`)

- `platform_administration` — `super-admin` role  
- `finance` — `super-admin` **or** `approve-adjustments` permission (e.g. `finance-lead`)  
- `compliance` — `super-admin` **or** `compliance-manager`  
- `support` — `super-admin` **or** `view-users` permission  

`activeWorkspaces()` returns the subset the user has, in stable order.

## Surfaces

### Finance / treasury surface

**When:** user has `finance` **or** `platform_administration` in `activeWorkspaces()`.

**Widgets (order):** `PrimaryBasketWidget`, `MultiBankDistributionWidget`, `BankAllocationWidget`, `AccountStatsOverview`, `RecentTransactionsChart`, `AccountBalanceChart`, `SystemHealthWidget`.

**Defence in depth:** `PrimaryBasketWidget`, `BankAllocationWidget`, and `MultiBankDistributionWidget` implement `canView()` via `VisibleOnlyOnFinanceAdminSurface` so they do not render if policy drifts.

### Operations surface

**When:** user has at least one workspace but **not** finance or platform administration (e.g. `support-l1`, `compliance-manager` without super-admin).

**Widgets:** `OperationsStatsOverview`, `FailedMomoTransactionsWidget`.

**Rationale:** avoids aggregate **platform-wide balances** and basket composition for roles that are not cleared for treasury intelligence (see `support-l1` permission set in `RolesAndPermissionsSeeder`).

### No workspace

**When:** user has no role-derived workspace (empty `activeWorkspaces()`).

**Widgets:** none (empty dashboard).

## Revenue & Performance (Filament)

- Navigation group **`Revenue & Performance`** is registered in `AdminPanelProvider` (after **Transactions**).
- Shell page **`RevenuePerformanceOverview`**: `canAccess()` requires `finance` or `platform_administration` workspace (same policy as treasury dashboard widgets). Implemented in `app/Filament/Admin/Pages/RevenuePerformanceOverview.php`.

## REQ traceability

- REQ-DASH-001, REQ-DASH-002 — [2026-04-24-maphapay-wallet-revenue-admin-spec.md](../superpowers/plans/2026-04-24-maphapay-wallet-revenue-admin-spec.md)

## Revision

- **2026-04-24:** Initial matrix aligned with Tasks 1–4 of the wallet revenue admin implementation plan.
- **2026-04-24:** Documented `Revenue & Performance` nav + `RevenuePerformanceOverview` access (Tasks 5–6).
