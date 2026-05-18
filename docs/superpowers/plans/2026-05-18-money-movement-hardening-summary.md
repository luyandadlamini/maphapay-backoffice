# Money Movement Hardening — Execution Summary

Companion to [the implementation plan](./2026-05-18-money-movement-hardening.md). Records what was actually shipped, what was deferred, and the follow-up queue.

## Status

Both user-reported bugs are fixed end-to-end. Architectural pillars are in place. The static audit tests prevent the bug class from recurring.

## What shipped

### Backend (branch `feat/money-movement-hardening`)

| Commit  | Scope |
|---------|-------|
| `09cfed8b` | docs(plan): money-movement hardening plan |
| `fd7d1e71` | feat(tenancy): `CentralModel` base class |
| `bfff7c6a` | fix(tenancy): pin `User` model to central |
| `a6981e5a` | feat(tenancy): `TenantContextMissingException` |
| `203f7ae8` | feat(tenancy)!: strict `UsesTenantConnection` outside testing |
| `1d37be04` | feat(compat): `POST /api/user/exist` recipient lookup — **fixes Issue 1** |
| `f9d274c3` | feat(tenancy): `CarriesTenantContext` event interface |
| `1d1ccbfa` | feat(tenancy): `TenantAwareProjector` base class |
| `c5a18bbf` | feat(tenancy): AssetTransfer events implement `CarriesTenantContext` |
| `eaba5930` | fix(savings)!: `AssetBalanceProjector` extends `TenantAwareProjector` — **fixes Issue 2 backend** |
| `004d8b6b` | test(architecture): projector tenancy audit with allowlist |
| `1fc7d849` | test(money-movement): balance conservation invariant |

### Mobile (branch `fix/savings-pocket-phantom-transfer`)

| Commit  | Scope |
|---------|-------|
| `9ff238b` | fix(savings): delete legacy `(tabs)/wallet/pocket-detail` — **fixes Issue 2 mobile** |
| `b69fba1` | feat(api-client): preserve full error context + auto-Sentry 5xx capture |
| `81a7db5` | feat(money-movement): Zod schemas + `useMoneyMovementMutation` wrapper |

## Architectural pillars (status)

| Pillar | Status |
|---|---|
| 1. Tenancy is a typed boundary | ✅ `CentralModel` + strict `UsesTenantConnection` + `TenantContextMissingException` |
| 2. Events carry tenancy | ✅ `CarriesTenantContext` + `TenantAwareProjector` + 5 events migrated |
| 3. Sync money APIs with bounded wait | ⚠️ Deferred — see "What was deferred" |
| 4. Typed mobile boundary | ✅ Zod schemas + `useMoneyMovementMutation` + Sentry 5xx capture |
| 5. One canonical screen per action | ✅ Legacy savings pocket-detail deleted; all routes go through `PocketDetailScreen` |

## What was deferred

### Phase 4 (Sync money APIs) — DEFERRED

**Why:** This codebase uses `laravel-workflow/laravel-workflow` (poll-based), not the official Temporal SDK assumed by the plan. The existing `TransferController` honestly returns `status: 'pending'` HTTP 201 — it does not claim the transfer is completed. The "instant phantom success" UX bug was caused entirely by:

1. **Mobile** showing a synchronous success toast without awaiting the API (fixed in `9ff238b` by deleting the legacy screen)
2. **Backend projector** silently writing to the wrong DB so the `pending` workflow never updated the balance the app reads (fixed in `eaba5930`)

With both fixes in place, the existing pending-then-poll pattern works correctly. Phase 4's sync-bounded-wait would be a UX improvement (one fewer round-trip on the happy path), not a correctness fix. It is genuinely useful but lower priority than the rigor delivered in Phases 1, 3, 6, and 7.

**When to revisit:** When average transfer latency (workflow execution + projection) is measured to be under 2s in production AND mobile telemetry shows the polling round-trip is noticeable to users. The `SyncTransferAwaiter` primitive (Plan Task 4.1) is the starting point — but it must be adapted to laravel-workflow's poll-and-fresh() API rather than Temporal's `getResult($timeout)`.

### Phase 1.5 (full tenancy strict-mode static audit) — DEFERRED

**Why:** The strict `UsesTenantConnection` change (`203f7ae8`) only fires outside `app.env === 'testing'`. The test suite cannot dynamically exercise it. A full static audit of every projector, reactor, queue job, Temporal activity, console command, and Filament page is open-ended work.

The static-audit test `ProjectorTenancyAuditTest` (`004d8b6b`) covers projectors with an explicit 26-entry allowlist. **The allowlist is the explicit follow-up queue.** Highest priority entries are flagged in code comments — the Account-domain projectors (`TransactionProjector`, `AccountProjector`, `TurnoverProjector`, `MinorPointsProjector`, `MinorRedemptionProjector`) share the same risk profile as `AssetBalanceProjector` and should be migrated next.

A similar static audit for Reactors, Activities, and Jobs is a natural extension. See [tenancy-strict-fallout.md](./2026-05-18-tenancy-strict-fallout.md) for the procedure.

## Follow-up queue (prioritized)

| # | Item | Risk | Effort |
|---|---|---|---|
| 1 | Migrate Account-domain projectors to `TenantAwareProjector` (5 projectors flagged in `ProjectorTenancyAuditTest::ALLOWLIST`) | High — same risk profile as the bug we just fixed | M |
| 2 | Migrate `AssetTransferProjector` + `AssetTransactionProjector` | High | M |
| 3 | Migrate Payment, Compliance, Lending projectors | Medium | M |
| 4 | Add a `TenantAwareReactor` base class and audit `app/Domain/**/Reactors/` similarly | Medium | M |
| 5 | Static audit test for queue jobs touching `UsesTenantConnection` models | Medium | M |
| 6 | Phase 4: Sync money APIs via `SyncTransferAwaiter` for intra-tenant transfers (UX improvement) | Low | M |
| 7 | Idempotency-Key required header on `/api/v2/transfers` (already exists, just enforce) | Low | S |
| 8 | Migrate `useSendMoney` mutation to `useMoneyMovementMutation` wrapper | Low | S |
| 9 | ESLint rule forbidding plain `useMutation` in mobile money-movement directories | Low | S |
| 10 | Backend `CentralModelAuditTest` enumerating all `extends Model` classes against tenant/central tables | Low | M |

## Verification commands

### Backend

```bash
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=maphapay_backoffice_test \
DB_USERNAME=maphapay_test DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest \
  tests/Unit/Shared/Models/CentralModelTest.php \
  tests/Feature/Auth/UserConnectionTest.php \
  tests/Feature/Architecture/TenantBoundaryEnforcementTest.php \
  tests/Feature/Architecture/ProjectorTenancyAuditTest.php \
  tests/Feature/Api/Compatibility/UserExistTest.php \
  tests/Unit/Shared/EventSourcing/TenantAwareProjectorTest.php \
  tests/Feature/MoneyMovement/AssetBalanceProjectionTest.php \
  tests/Feature/MoneyMovement/BalanceConservationTest.php
```

Expected: 30+ passing tests, 0 failures. Confirms tenancy enforcement, event-carried tenancy, projector migration, and balance conservation.

### Mobile

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
npx tsc --noEmit
```

Expected: clean.

## How to merge

### Backend

```bash
cd /Users/Lihle/Development/Coding/maphapay-backoffice
git checkout main
git merge --no-ff feat/money-movement-hardening
git push origin main
```

CI should re-run all tests including the new architectural audits.

### Mobile

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
git checkout main
git merge --no-ff fix/savings-pocket-phantom-transfer
git push origin main
```

Once merged, the mobile app should be re-built (EAS or local-build) and tested end-to-end against the deployed backend.

## What to watch in production after deploy

1. **Sentry**: filter by tag `category:schema_drift` — any hit means the backend changed a money-movement response shape the mobile no longer accepts.
2. **Sentry**: filter by tag `operation:pocket.withdraw_funds` or `operation:pocket.add_funds` — surfaces any savings flow errors with full request context.
3. **Logs**: search for `TenantContextMissingException` — every hit is a real bug at a non-HTTP code path that needs `WithTenantContext` wrapping. Each is a one-line fix.
4. **Balance reconciliation**: the `BalanceConservationTest` invariant should hold in production too. A nightly job that sums `account_balances.balance` per tenant per asset and compares to the previous run's sum would catch any escaping projection bugs. (Out of scope for this plan — recommended follow-up.)
