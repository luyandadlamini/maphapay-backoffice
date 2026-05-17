# ADR-001: Tenant DB is Canonical for Account and AccountBalance

**Date:** 2026-05-17
**Status:** Accepted
**Branch:** `refactor/account-tenancy-canonicalization`

## Context

`Account` and `AccountBalance` use the `UsesTenantConnection` trait, which sets their Eloquent connection name to `'tenant'`. Stancl Tenancy swaps this connection to point at a per-tenant schema when `Tenancy::initialize($tenant)` is called. Without initialization, the `'tenant'` connection falls back to `DB_DATABASE` (the central DB).

A split-brain existed: the mobile API initialized tenancy via `account.context` middleware; the Filament admin panel did not, causing reads/writes to hit different databases for the same model.

## Decision

**Direction A: Tenant DB is canonical. Admin reads/writes the tenant DB.**

Rationale:
1. The tenant schema is richer (`currency`, `available_balance`, `reserved_balance`, `is_active`, `is_frozen`, `verification_tier`, `aml_status`, `metadata`). The central schema is a legacy stub.
2. 154 sibling domain models already use `UsesTenantConnection`. Inverting two of them would create a special-case island.
3. Mobile already works correctly under tenancy; only admin was broken. Fixing admin (~10 surfaces) has a smaller blast radius than re-pointing the data model.
4. Multi-tenancy is intentional for partner/whitelabel isolation; demoting `Account` would close that door.

## Alternatives Considered

**Direction B: Central DB is canonical. Account drops `UsesTenantConnection`.**

Rejected because:
- The central `accounts` schema is missing 8+ columns the tenant schema has.
- It would require updating 154 other tenant-aware models or creating a special-case for just two of them.
- It would break partner isolation capabilities.

## Consequences

- Filament admin pages that operate on single records must use `WithAccountTenancy` concern.
- Non-HTTP code paths must use `WithTenantContext::withAccountTenancy()`.
- Cross-tenant dashboard aggregates must iterate tenants explicitly (O(tenants) queries).
- Central `accounts`/`account_balances` tables will be renamed to `_legacy_pre_canonicalization` after data migration and eventually dropped.
- A `RequiresTenantContext` guard on both models will throw in production/staging if a write is attempted without tenancy initialized.

## References

- Plan: `docs/superpowers/plans/2026-05-16-account-tenancy-canonicalization.md`
- Sweep command: `app/Console/Commands/SweepOrphanCentralBalancesCommand.php`
- Concern: `app/Filament/Admin/Concerns/WithAccountTenancy.php`
- Helper: `app/Domain/Shared/Concerns/WithTenantContext.php`
- Guard: `app/Domain/Shared/Traits/RequiresTenantContext.php`
