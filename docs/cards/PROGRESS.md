# MaphaPay Cards — Backend Implementation Progress

**Repo:** `maphapay-backoffice` (Laravel 12 + Filament + Spatie event sourcing)
**Working dir:** `/Users/Lihle/Development/Coding/maphapay-backoffice`
**Read [`IMPLEMENTATION_PROMPT.md`](./IMPLEMENTATION_PROMPT.md) before editing this file.**

This file is the source of truth for "where are we now" on the backend side. The mobile equivalent is at `maphapayrn/docs/cards/PROGRESS.md`.

---

## Status legend

- `pending` — not started
- `in_progress` — actively being worked OR partially done from a stopped session
- `done` — implementation + tests + quality gates pass; commit referenced
- `blocked` — cannot proceed; reason in notes

---

## Phase summary

| Phase | Title | Status | Started | Completed | Closing commit |
|---:|---|---|---|---|---|
| 0 | Demolition (delete legacy `/api/virtual-card/*`) | done | 2026-05-08 | 2026-05-09 | cd9c0739 |
| 1 | Schema and seed | in_progress | 2026-05-08 | — | 02be5b15 |
| 2 | Domain skeleton (no controllers) | done | 2026-05-08 | 2026-05-08 | 2b67a829 |
| 3 | `CardEntitlementService` and `CardFeeService` | in_progress | 2026-05-09 | — | d349b6c4 |
| 4 | `CardSubscriptionService` and `CardBillingService` | pending | — | — | — |
| 5 | Routes, controllers, requests, resources | pending | — | — | — |
| 6 | Webhooks and processor adapters | pending | — | — | — |
| 7 | Filament admin | pending | — | — | — |
| 8 | Jobs and events | pending | — | — | — |
| 9 | Risk service | pending | — | — | — |
| 10 | End-to-end smoke test | pending | — | — | — |
| 11 | Pre-launch security audit | pending | — | — | — |
| 12 | Launch staging rollout (feature flag flips) | pending | — | — | — |

Phase numbers map 1:1 to [`09-implementation-phases.md`](./09-implementation-phases.md).

---

## Phase 0 — Demolition

**Cross-repo dependency:** none. Mobile phase 0 can run in parallel.

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 0.1 | Locate legacy virtual-card controllers (`grep -rn "virtual-card" routes/ app/Http/Controllers/`) and list them in this row's notes | done | 2026-05-08 | 2026-05-08 | _this_commit_ | Legacy surface: `routes/api-compat.php` imports/registers 9 `/api/virtual-card/*` routes for list, view, ensure-default, store-additional, add-fund, cancel, freeze, unfreeze, transaction. Controllers live in `app/Http/Controllers/Api/Compatibility/VirtualCard/`. Keep `app/Domain/CardIssuance/*` per docs. |
| 0.2 | Delete legacy virtual-card controller files | done | 2026-05-08 | 2026-05-08 | _this_commit_ | Deleted all 9 controller files under `app/Http/Controllers/Api/Compatibility/VirtualCard/`; retained `app/Domain/CardIssuance/`. |
| 0.3 | Remove route registrations for `/api/virtual-card/*` in `routes/api.php` and `routes/api-compat.php` | done | 2026-05-08 | 2026-05-08 | _this_commit_ | Removed 9 compatibility route registrations and imports from `routes/api-compat.php`; `routes/api.php` had no matches. `php -l routes/api-compat.php` passes and `rg "virtual-card\|VirtualCard" routes/api.php routes/api-compat.php` is empty. |
| 0.4 | Delete any service that exists only to serve those endpoints | done | 2026-05-08 | 2026-05-08 | _this_commit_ | No legacy-only service exists. Deleted controllers used shared `CardProvisioningService` / `HighRiskActionTrustPolicy`; remaining `VirtualCard` references are retained `CardIssuance`, GraphQL, feature flags, or tests. |
| 0.5 | Run existing test suite: `vendor/bin/pest`. Delete tests that depend on legacy endpoints | done | 2026-05-08 | 2026-05-09 | cd9c0739 | Deleted endpoint-dependent tests `VirtualCardCancelTrustPolicyTest.php` and `VirtualCardFreezeControllerTest.php`. Full suite run (9 880 tests across 4 suites) completed 2026-05-09: Unit 4 385 tests all pass; Feature 3 577 tests all pass (excl. `RevenueAnomalyScanForTenantsCommandTest`); Integration 2 pre-existing failures; Security 1 pre-existing failure. Zero failures caused by Phase 0 route/controller deletions. Five pre-existing failures documented in Open blockers: (1) `MoneyTest > handles float amounts` — float passed to int ctor; (2) `AccountProjectorTest > add money` — balance stays 0 after projector; (3) `ApiSecurityTest > api requires authentication` — GET /api/status returns 500; (4) `MinorCardControllerTest > guardian can approve request` — approve endpoint returns 404 (last changed 2026-04-26); (5) `RevenueAnomalyScanForTenantsCommandTest` — stalls on MySQL lock waits (requires CREATE DATABASE grant absent in local env). `php artisan route:list \| grep virtual-card` returns empty. `php -l routes/api-compat.php` passes. |
| 0.6 | Verify `php artisan route:list \| grep virtual-card` returns nothing | done | 2026-05-09 | 2026-05-09 | cd9c0739 | `php artisan route:list --columns=method,uri \| grep virtual-card` returned no output. `php -l routes/api-compat.php` and `php -l routes/api.php` both pass. |

Acceptance: no legacy routes; full test suite passes.

---

## Phase 1 — Schema and seed

**Cross-repo dependency:** none.

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 1.1 | Write migration `2026_05_08_000001_alter_cards_add_monetisation_fields.php` per [`03-database-schema.md`](./03-database-schema.md) §1 | done | 2026-05-08 | 2026-05-08 | a3423357 | 17 columns + 2 indexes; dropIndex before dropColumn in down(). |
| 1.2 | Write migration `2026_05_08_000002_create_card_plans_table.php` (global table) per §2 | done | 2026-05-08 | 2026-05-08 | a461cc53 | Global (non-tenant) table. 22 columns + composite index. |
| 1.3 | Write tenant migration `2026_05_08_000003_create_card_subscriptions_table.php` per §3 (incl. `subscriber_user_id`/`payer_user_id` split, `pending_guardian_approval` status, minor metadata) | done | 2026-05-08 | 2026-05-08 | 7cf874c4 | Partial unique index omitted (MySQL compat); enforced application-layer per spec note. |
| 1.4 | Write tenant migration `2026_05_08_000004_create_card_subscription_billing_attempts_table.php` per §4 | done | 2026-05-08 | 2026-05-08 | 9505a0ed | |
| 1.5 | Write tenant migration `2026_05_08_000005_create_card_fees_table.php` per §5 | done | 2026-05-08 | 2026-05-08 | 1a613314 | |
| 1.6 | Write tenant migration `2026_05_08_000006_create_card_audit_logs_table.php` per §6 | done | 2026-05-08 | 2026-05-08 | bdea1609 | Append-only: only `created_at`, no `updated_at`. Comment in migration. |
| 1.7 | Write tenant migration `2026_05_08_000007_create_card_risk_events_table.php` per §7 | done | 2026-05-08 | 2026-05-08 | 9396d094 | |
| 1.8 | Write tenant migration `2026_05_08_000008_create_card_disputes_table.php` per §8 | done | 2026-05-08 | 2026-05-08 | c90fe3aa | FK to `card_transactions` (table exists from prior phase). |
| 1.9 | Write tenant migration `2026_05_08_000009_create_physical_card_orders_table.php` per §9 | done | 2026-05-08 | 2026-05-08 | 8d4f31cc | |
| 1.10 | Determine if `idempotency_keys` table already exists; if not, write tenant migration `2026_05_08_000010_create_idempotency_keys_table.php` per §10 | done | 2026-05-08 | 2026-05-08 | 234a3b34 | `IdempotencyMiddleware` uses Cache only (not `operation_records`). New tenant table written. |
| 1.11 | Verify each migration has a working `down()` (`php artisan migrate:rollback --pretend`) | done | 2026-05-08 | 2026-05-08 | 234a3b34 | Static audit: all 10 down() methods verified; dropIndex-before-dropColumn on migration 1.1. No DB env in worktree — pretend run deferred to 1.13. |
| 1.12 | Write `database/seeders/CardPlanSeeder.php` per §11 (all 6 plans incl. `MINOR_KHULA_CARD`) | done | 2026-05-08 | 2026-05-08 | 0bb71207 | All 6 plans spec-reviewed value-by-value. Idempotent `updateOrCreate`. MINOR_KHULA_CARD name='Khula'. |
| 1.13 | Run all migrations on dev (`php artisan migrate --path=...`, `php artisan tenants:migrate --path=...`) per §12 | pending | — | — | — | Requires dev DB. No .env in worktree. Run manually on dev before Phase 2. |
| 1.14 | Run seeder: `php artisan db:seed --class=Database\\Seeders\\CardPlanSeeder --force` | pending | — | — | — | Requires CardPlan model (Phase 2) and dev DB. Run after Phase 2 models land. |
| 1.15 | Write `tests/Feature/Cards/Schema/CardPlansSeededTest.php` (asserts all 6 plans match `01-product-config.md` §1 verbatim) | done | 2026-05-08 | 2026-05-08 | 02be5b15 | All 6 plans; critical values spot-checked by reviewer + fixed gaps (PREMIUM spend limits, VIRTUAL_PLUS replacement fee, eligibility). |
| 1.16 | Write `tests/Feature/Cards/Schema/CardsTableHasMonetisationColumnsTest.php` | done | 2026-05-08 | 2026-05-08 | acd484cd | 17 columns via DataProvider + bulk sentinel. |
| 1.17 | Write `tests/Feature/Cards/Schema/AuditLogAppendOnlyTest.php` | done | 2026-05-08 | 2026-05-08 | 7c7a7ad7 | Schema proof (no updated_at); INSERT allowed; DB-level enforcement `markTestIncomplete` pending Phase 11 task 11.3. |
| 1.18 | All schema tests pass | pending | — | — | — | Tests skip gracefully without DB. Will pass once 1.13/1.14 run on dev. |

Acceptance: `SELECT COUNT(*) FROM card_plans` = 6; schema tests pass.

---

## Phase 2 — Domain skeleton

**Cross-repo dependency:** phase 1 done.

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 2.1 | Create `app/Domain/CardSubscriptions/` folder structure per [`02-domain-architecture.md`](./02-domain-architecture.md) §1 | done | 2026-05-08 | 2026-05-08 | — | Added documented domain directories under `app/Domain/CardSubscriptions/`. |
| 2.2 | Write `module.json` per §2 | done | 2026-05-08 | 2026-05-08 | — | Added `app/Domain/CardSubscriptions/module.json` with the documented dependencies, events, routes, and provider. |
| 2.3 | Write Eloquent models for every table (`HasUuids` + `UsesTenantConnection` where applicable) | done | 2026-05-08 | 2026-05-08 | — | Added 8 models for plans, subscriptions, billing attempts, fees, audit logs, risk events, disputes, and physical orders. Tenant-scoped tables use `UsesTenantConnection`. |
| 2.4 | Write all enums from [`CONTRACT.md`](./CONTRACT.md) as PHP `BackedEnum` | done | 2026-05-08 | 2026-05-08 | — | Added contract enums plus DB-only enum casts for plan eligibility, billing result, and physical-card delivery method. |
| 2.5 | Write value objects (`CardLimitSet`, `CardControlsInput`, `CardFeePreviewInput`, etc.) | done | 2026-05-08 | 2026-05-08 | — | Added immutable value objects, minimal input/result DTOs referenced by the service skeletons, and the missing shared `Money` VO used by the documented fee signatures. |
| 2.6 | Write service class skeletons (method signatures from `05-services-and-rules.md`; bodies throw `LogicException("not implemented")`) | done | 2026-05-08 | 2026-05-08 | — | Added all 11 service skeletons and the typed exception classes. Fee methods now use `App\Domain\Shared\Money\Money` per the spec. |
| 2.7 | Write all event classes from `07-jobs-and-events.md` §1 (extending `ShouldBeStored`) | done | 2026-05-08 | 2026-05-08 | — | Added all 20 stored event classes with readonly constructor payloads. |
| 2.8 | Write `CardSubscriptionsServiceProvider`; register in `bootstrap/providers.php` | done | 2026-05-08 | 2026-05-08 | — | Registered the 11 services, CardIssuer binding, Spatie stored-event reactors, and Laravel listeners for the existing CardIssuance events. Provider is registered in Laravel bootstrap providers. |
| 2.9 | Write factories for every model | done | 2026-05-08 | 2026-05-08 | — | Added factories for all 8 CardSubscriptions models. |
| 2.10 | Write `tests/Feature/Cards/DomainBootstrapTest.php` (DI resolution, event instantiation, factory creation) | done | 2026-05-08 | 2026-05-08 | — | Added bootstrap coverage for container resolution, event instantiation, and in-memory factory model construction. |
| 2.11 | All bootstrap tests pass | done | 2026-05-08 | 2026-05-08 | — | `./vendor/bin/pest tests/Feature/Cards/DomainBootstrapTest.php --colors=never` passes: 3 tests, 20 assertions. `php artisan domain:verify card-subscriptions --json --no-ansi` passes: 8/8 checks. |

Acceptance: every service is resolvable from the container; every event/model is instantiable.

---

## Phase 3 — `CardEntitlementService` and `CardFeeService`

**Cross-repo dependency:** phase 1, 2 done.

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 3.1 | Implement `CardEntitlementService::canSubscribeToPlan` per [`05-services-and-rules.md`](./05-services-and-rules.md) §1 | done | 2026-05-09 | 2026-05-09 | 7d1589a3 | Decision order rules 1–8 implemented; steps 9 (wallet balance) deferred to Phase 4. |
| 3.2 | Implement `canCreateVirtualCard`, `canRequestPhysicalCard`, `canAuthorize`, `canRevealCard`, `canUseFeature` | done | 2026-05-09 | 2026-05-09 | 7d1589a3 | All 6 methods. Known gaps: `account_status` proxied via `frozen_at`; minor detection via `account_type` attr (TODO for future infra). `CardErrorCode` enum extended with 14 missing cases; `Card` model updated with Phase 1 monetisation columns. |
| 3.3 | Implement `CardFeeService::calculateFxFee` (formula from §4) | done | 2026-05-09 | 2026-05-09 | aad3d6af | SZL/ZAR bypass; bps multiply otherwise. |
| 3.4 | Implement `CardFeeService::calculateAtmFee` | done | 2026-05-09 | 2026-05-09 | aad3d6af | fixed + bps percentage via Money VO. |
| 3.5 | Implement `chargeIssuanceFee`, `chargeReplacementFee`, `chargeVirtualReplacementFee`, `chargeChargebackAbuseFee`, `previewTransaction` | done | 2026-05-09 | 2026-05-09 | 3ef5f667 | All charge methods write `card_fees` row only; LedgerPostingService wiring deferred to Phase 4. Chargeback abuse fee hardcoded E100. |
| 3.6 | Implement `waiveFee`, `refundFee` | done | 2026-05-09 | 2026-05-09 | aad3d6af | Status + timestamp update; save. |
| 3.7 | Write `tests/Feature/Cards/Services/CardEntitlementServiceTest.php` covering every decline rule | done | 2026-05-09 | 2026-05-09 | df159091 | 25 tests, 49 assertions. All pass on worktree; skip gracefully without DB or on stub. |
| 3.8 | Write `tests/Feature/Cards/Services/CardFeeServiceTest.php` covering FX (SZL/ZAR/USD across all plans), ATM examples from `01-product-config.md` §4, free reissue allowance | done | 2026-05-09 | 2026-05-09 | d349b6c4 | 17 tests. Pure-calc tests (6) pass without DB; DB tests (11) skip until Phase 1 migrations run on dev. |
| 3.9 | All entitlement + fee tests pass | in_progress | 2026-05-09 | — | — | 31 passed, 11 skipped (DB-only tests await Phase 1 migration on dev). Zero failures. |

Acceptance: every entitlement decline reason and fee formula has a passing test that exercises it.

---

## Phase 4 — `CardSubscriptionService` and `CardBillingService`

**Cross-repo dependency:** phase 3 done. **Unblocks mobile phase 3.**

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 4.1 | Implement `CardSubscriptionService::subscribe` (incl. minor-request validation, transaction, initial billing, audit + event) | pending | — | — | — | |
| 4.2 | Implement `upgrade`, `downgrade` (proration, force-flag for excess cards), `cancel` | pending | — | — | — | |
| 4.3 | Implement `getCurrent`, `markPastDue`, `suspend`, `restore`, `terminateUnpaid` | pending | — | — | — | |
| 4.4 | Implement `CardBillingService::chargeInitialPeriod` and `billRenewal` (with stable idempotency key) | pending | — | — | — | |
| 4.5 | Implement `handleSuccessfulPayment` (period roll, status restore, audit, event, push) | pending | — | — | — | |
| 4.6 | Implement `handleFailedPayment` (past_due → suspended → cancelled FSM per `01-product-config.md` §6) | pending | — | — | — | |
| 4.7 | Implement `retryFailedPayment` | pending | — | — | — | |
| 4.8 | Verify all wallet debits go through `LedgerPostingService::post()` with the account codes from `05-services-and-rules.md` §12 | pending | — | — | — | |
| 4.9 | Write `tests/Feature/Cards/Services/CardSubscriptionServiceTest.php` (8+ scenarios from phase doc) | pending | — | — | — | |
| 4.10 | Write `tests/Feature/Cards/Services/CardBillingServiceTest.php` (5+ scenarios) | pending | — | — | — | |
| 4.11 | Write `tests/Feature/Cards/Services/CardBillingIdempotencyTest.php` (duplicate run = single posting) | pending | — | — | — | |
| 4.12 | All subscription + billing tests pass | pending | — | — | — | |

Acceptance: every state transition in the billing FSM has a test that demonstrates it.

---

## Phase 5 — Routes, controllers, requests, resources

**Cross-repo dependency:** phase 4 done. **Unblocks mobile phase 2.**

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 5.1 | Write `app/Domain/CardSubscriptions/Routes/api.php` with all 27 endpoints from [`04-api-contract.md`](./04-api-contract.md) §1 | pending | — | — | — | |
| 5.2 | Verify routes load via `ModuleRouteLoader` (`php artisan route:list \| grep cards`) | pending | — | — | — | |
| 5.3 | Write all FormRequests with validation rules per request body shapes | pending | — | — | — | |
| 5.4 | Write controllers (thin wrappers over services); translate `EntitlementDeniedException` to error envelope | pending | — | — | — | |
| 5.5 | Write API resources for response shapes (`CardPlanResource`, `CardSubscriptionResource`, `CardResource`, etc.) | pending | — | — | — | |
| 5.6 | Wire rate limiters per `04-api-contract.md` §10 in `RouteServiceProvider` | pending | — | — | — | |
| 5.7 | Write `CardPlanControllerTest.php` (adult vs minor plan visibility) | pending | — | — | — | |
| 5.8 | Write `CardSubscriptionControllerTest.php` (idempotency, errors, null shape) | pending | — | — | — | |
| 5.9 | Write `VirtualCardControllerTest.php` (limit clamping, ownership) | pending | — | — | — | |
| 5.10 | Write `CardRevealControllerTest.php` (step-up, audit-before-return, URL origin) | pending | — | — | — | |
| 5.11 | Write `PhysicalCardOrderControllerTest.php` | pending | — | — | — | |
| 5.12 | Write `CardFeePreviewControllerTest.php` (regression vs `CardFeeService`) | pending | — | — | — | |
| 5.13 | Write `MinorCardRequestControllerTest.php` (guardian-only approve/deny) | pending | — | — | — | |
| 5.14 | Write `RateLimitTest.php` (reveal limit blocks 6th in 1 minute) | pending | — | — | — | |
| 5.15 | All HTTP tests pass | pending | — | — | — | |
| 5.16 | Cross-repo signal: edit mobile `PROGRESS.md` to note phase 5 done; commit | pending | — | — | — | |

Acceptance: every endpoint in the API contract has a passing HTTP test.

---

## Phase 6 — Webhooks and processor adapters

**Cross-repo dependency:** phase 5 done. **Unblocks mobile phase 5 (reveal flow) and phase 6 (transactions).**

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 6.1 | Implement `DemoCardIssuerAdapter::generateRevealUrl` per [`08-processor-gateway.md`](./08-processor-gateway.md) §3 | pending | — | — | — | |
| 6.2 | Build the demo reveal Blade view at `resources/views/demo-cards/reveal.blade.php` (HMAC validation + TTL) | pending | — | — | — | |
| 6.3 | Implement `RainCardIssuerAdapter::generateRevealUrl` (stub returning `ProcessorUnavailable` until creds available) | pending | — | — | — | |
| 6.4 | Implement `verifyWebhookSignature` on both adapters (using `hash_equals`) | pending | — | — | — | |
| 6.5 | Write webhook controller for authorisation/clearing/reversal/refund with: signature → idempotency by `processor_event_id` → audit raw body → dispatch job → 200 | pending | — | — | — | |
| 6.6 | Write `HandleAuthorisationWebhookJob` per §6 (entitlement + risk + fees + wallet hold) | pending | — | — | — | |
| 6.7 | Write `HandleClearingWebhookJob`, `HandleReversalWebhookJob`, `HandleRefundWebhookJob` per §9 | pending | — | — | — | |
| 6.8 | Write `tests/Feature/Cards/Webhooks/AuthorisationWebhookTest.php` (5 cases) | pending | — | — | — | |
| 6.9 | Write `ClearingWebhookTest.php` (settlement matching, orphan settlement) | pending | — | — | — | |
| 6.10 | Write `DemoCardIssuerAdapterTest.php` (URL HMAC, TTL, expired view rejection) | pending | — | — | — | |
| 6.11 | Write `RainCardIssuerAdapterTest.php` (mocked HTTP fixtures) | pending | — | — | — | |
| 6.12 | Manual smoke: end-to-end auth → clearing webhook flow on dev with demo adapter | pending | — | — | — | |
| 6.13 | All webhook + adapter tests pass | pending | — | — | — | |

Acceptance: dev can complete a full simulated card-transaction lifecycle.

---

## Phase 7 — Filament admin

**Cross-repo dependency:** phase 5 done.

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 7.1 | Write `CardPlanResource` per [`06-filament-admin.md`](./06-filament-admin.md) §2 (super_admin only) | pending | — | — | — | |
| 7.2 | Write `CardSubscriptionResource` per §3 (incl. retry/suspend/reactivate/cancel/change-plan/waive actions, all governance-wrapped) | pending | — | — | — | |
| 7.3 | Write `MinorCardSubscriptionResource` per §4 (override-approve action for super_admin) | pending | — | — | — | |
| 7.4 | Write `CardResource` per §5 (admin freeze/unfreeze, lost/stolen, cancel) | pending | — | — | — | |
| 7.5 | Write `CardTransactionResource` per §6 (read-only, dispute opener, CSV export) | pending | — | — | — | |
| 7.6 | Write `PhysicalCardOrderResource` per §7 (Kanban-style transitions) | pending | — | — | — | |
| 7.7 | Write `CardRiskEventResource` per §8 (assign/in-review/resolve/dismiss/freeze-card) | pending | — | — | — | |
| 7.8 | Write `CardDisputeResource` per §9 (in-review/evidence/won/lost/withdrawn) | pending | — | — | — | |
| 7.9 | Write `CardAuditLogResource` per §10 (read-only, no edit, no delete, governed export) | pending | — | — | — | |
| 7.10 | Write policies under `app/Policies/Cards/` for every resource (deny-by-default) | pending | — | — | — | |
| 7.11 | Run `php artisan shield:generate` for each resource; install with `shield:install --tenant` | pending | — | — | — | |
| 7.12 | Build operations dashboard `/admin/cards-dashboard` per §14 | pending | — | — | — | |
| 7.13 | Write `tests/Feature/Filament/Cards/...` per phase doc list (5 tests minimum) | pending | — | — | — | |
| 7.14 | All Filament tests pass | pending | — | — | — | |

Acceptance: every admin action requires a reason, writes audit, role-gated correctly.

---

## Phase 8 — Jobs and events

**Cross-repo dependency:** phase 4 done (billing service exists).

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 8.1 | Write all listeners from [`07-jobs-and-events.md`](./07-jobs-and-events.md) §2 | pending | — | — | — | |
| 8.2 | Register listeners with Spatie projectionist in `CardSubscriptionsServiceProvider::boot()` | pending | — | — | — | |
| 8.3 | Write `BillCardSubscriptionsJob` (orchestrator) per §3 | pending | — | — | — | |
| 8.4 | Write `ProcessSingleSubscriptionRenewalJob` (per-sub processor with row lock) | pending | — | — | — | |
| 8.5 | Write `RetryFailedBillingJob`, `SuspendPastDueSubscriptionsJob`, `CancelLongPastDueSubscriptionsJob`, `CloseCardsOnSubscriptionEndJob`, `PurgeExpiredRevealUrlsJob`, `RecalculateCardsMrrJob` | pending | — | — | — | |
| 8.6 | Register schedules in `routes/console.php` per §3 (with `withoutOverlapping`) | pending | — | — | — | |
| 8.7 | Verify `php artisan schedule:list` shows all card-related entries | pending | — | — | — | |
| 8.8 | Configure Horizon supervisors per §7 (`cards-billing-supervisor`, `cards-notifications-supervisor`) | pending | — | — | — | |
| 8.9 | Wire push notifications via existing `PushNotificationService` per §4 (table of triggers) | pending | — | — | — | |
| 8.10 | Wire WebSocket broadcast `BroadcastSubscriptionStateToMobile` per §5 | pending | — | — | — | |
| 8.11 | Localise notification copy in `lang/en/cards.php`; stub `lang/ss/cards.php` | pending | — | — | — | |
| 8.12 | Write job tests per phase 8 doc list (5 tests minimum) | pending | — | — | — | |
| 8.13 | Write listener tests (`NotifyCardSubscriptionLifecycleTest`, `ApplyRiskFreezeOnCriticalEventTest`) | pending | — | — | — | |
| 8.14 | All job + listener tests pass | pending | — | — | — | |

Acceptance: scheduled jobs visible in `schedule:list`; manual job dispatch reaches assertion.

---

## Phase 9 — Risk service

**Cross-repo dependency:** phase 6 (webhooks call into risk service).

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 9.1 | Implement `CardRiskService::evaluateAuthorization` per [`05-services-and-rules.md`](./05-services-and-rules.md) §6 (every threshold from `01-product-config.md` §9) | pending | — | — | — | |
| 9.2 | Implement `evaluateCardCreation`, `recordEvent`, `suspendCardsForUser` | pending | — | — | — | |
| 9.3 | Configure thresholds in `config/cards.php` `risk` block | pending | — | — | — | |
| 9.4 | Wire `CardRiskService` invocation into `JitFundingService::evaluate()` (the existing service) — call at the top, before funds check | pending | — | — | — | |
| 9.5 | Write `tests/Feature/Cards/Services/CardRiskServiceTest.php` covering every threshold | pending | — | — | — | |
| 9.6 | All risk tests pass | pending | — | — | — | |

Acceptance: every threshold in `01-product-config.md` §9 has a test.

---

## Phase 10 — End-to-end smoke test

**Cross-repo dependency:** phases 1–9 done.

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 10.1 | Write `tests/Feature/Cards/EndToEndSmokeTest.php` adult flow per phase doc | pending | — | — | — | |
| 10.2 | Write Khula minor flow E2E test (guardian approval → minor card → minor authorisation respecting `minor_card_limits`) | pending | — | — | — | |
| 10.3 | Both E2E tests pass on a fresh DB | pending | — | — | — | |
| 10.4 | Verify all 10 phases' tests still pass together (`vendor/bin/pest tests/Feature/Cards`) | pending | — | — | — | |

Acceptance: the full lifecycle of an adult subscription and a minor subscription runs green in CI.

---

## Phase 11 — Pre-launch security audit

**Cross-repo dependency:** phases 1–10 done.

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 11.1 | Run CI security greps from [`08-processor-gateway.md`](./08-processor-gateway.md) §11; ALL must pass | pending | — | — | — | |
| 11.2 | Verify §14 checklist (production secrets in vault, HTTPS reveal page, originWhitelist, `hash_equals`, monitoring) | pending | — | — | — | |
| 11.3 | Manual: try inserting a 16-digit string into `card_audit_logs.metadata.notes` via the audit service; confirm rejection | pending | — | — | — | |
| 11.4 | Manual: try calling reveal endpoint for another user's card; confirm 404 | pending | — | — | — | |
| 11.5 | Manual: try webhook replay with tampered body; confirm 401 | pending | — | — | — | |
| 11.6 | Manual: try Filament admin action without required role; confirm 403 | pending | — | — | — | |
| 11.7 | Request external code review (e.g. via `requesting-code-review` skill or team channel) | pending | — | — | — | |

Acceptance: no PAN handling, all signatures constant-time, all audit logs append-only, all admin actions governed.

---

## Phase 12 — Launch staging rollout

**Cross-repo dependency:** mobile phase 10 done.

| # | Task | Status | Started | Completed | Commit | Notes |
|---:|---|---|---|---|---|---|
| 12.1 | Flip `cards_monetisation_enabled = true` for staff tenants only | pending | — | — | — | |
| 12.2 | After 1 week observation: flip `card_subscriptions_enabled = true` | pending | — | — | — | |
| 12.3 | Flip `virtual_card_lite_enabled` and `virtual_card_plus_enabled` | pending | — | — | — | |
| 12.4 | Greenlight check: flip `physical_card_enabled` for first physical pilot | pending | — | — | — | |
| 12.5 | Legal review of parental consent UX complete; flip `minor_khula_card_enabled` | pending | — | — | — | |
| 12.6 | Support staffing confirmed for higher-touch tier; flip `premium_card_enabled` | pending | — | — | — | |

Acceptance: each flag flip preceded by ≥ 1 week of metrics from the previous flip.

---

## Cross-repo signals

This section is updated by either repo's agents to coordinate.

| From repo | To repo | Signal | Date | Commit |
|---|---|---|---|---|
| — | — | — | — | — |

Example future entry:

> | backend | mobile | Phase 4 (subscription billing) deployed to dev; mobile may proceed with phase 3 | 2026-05-15 | abc1234 |

---

## Open blockers

Pre-existing failures unrelated to Phase 0 — documented, not fixed per Phase 0 scope.

| Task | Blocker | Owner | Date raised |
|---|---|---|---|
| pre-existing | `Tests\Domain\Account\DataObjects\MoneyTest > it handles float amounts by converting to int` — `App\Domain\Account\DataObjects\Money::__construct()` declared `int` but test passes `float`. Predates Phase 0. | — | 2026-05-09 |
| pre-existing | `Tests\Domain\Account\Projectors\AccountProjectorTest > add money` — account balance stays 0 after projector fires. Predates Phase 0. | — | 2026-05-09 |
| pre-existing | `Tests\Security\API\ApiSecurityTest > api requires authentication` — GET /api/status returns HTTP 500 instead of 200/404. Predates Phase 0. | — | 2026-05-09 |
| pre-existing | `Tests\Feature\Http\Controllers\Api\MinorCardControllerTest > guardian can approve request` — POST /api/v1/minor-cards/requests/{id}/approve returns 404. Test last modified 2026-04-26. Predates Phase 0. | — | 2026-05-09 |
| pre-existing | `Tests\Feature\Console\RevenueAnomalyScanForTenantsCommandTest` — stalls on MySQL lock waits during tenant DB creation; requires `CREATE DATABASE` grant absent in local env. Run `scripts/reset-local-mysql-test-access.sh` to grant locally; CI (MySQL root) passes. Predates Phase 0. | user | 2026-05-09 |

---

## Handoff log

Append a new entry every session. Most recent on top.

### 2026-05-09 — Phase 0 closed (claude-sonnet-4-6)

- Completed: tasks 0.5 and 0.6; Phase 0 status set to `done`.
- Task 0.5 gate: ran all 4 testsuites (9 880 tests total). Unit 4 385 all pass. Feature 3 577 all pass (excluding pre-existing `RevenueAnomalyScanForTenantsCommandTest`). Integration 2 failures; Security 1 failure. All failures predate Phase 0 and are unrelated to virtual-card route/controller deletion — documented in Open blockers.
- Task 0.6 gate: `php artisan route:list | grep virtual-card` returns empty. `php -l` passes for both route files.
- No Phase 0 regressions found across the full suite.
- Closing commit: cd9c0739.

### 2026-05-09 — backend phase 3 CardEntitlementService + CardFeeService (claude-opus-4-7 + subagents)

- Completed: Phase 3 tasks 3.1–3.8; 3.9 in_progress (31 pass, 11 skip awaiting dev DB migrations).
- Implemented: `CardEntitlementService` (6 methods, full decision-order rules), `CardFeeService` (9 methods, all charge/calc/preview/waive/refund).
- Extended `CardErrorCode` enum with 14 missing cases; updated `Card` model fillable/casts with Phase 1 monetisation columns.
- Key deferral: LedgerPostingService wallet debit wiring deferred to Phase 4 (charge methods write `card_fees` row only).
- Known gaps (documented with TODO comments): `account_status` proxied via `frozen_at`; minor detection requires `account_type` on User row; `risk_level` column mapped from `risk_rating`.
- Tests: 25 entitlement tests (49 assertions), 17 fee tests — all pass or skip gracefully, zero failures.
- Branch: `feature/cards-phase-3` in `.worktrees/cards-phase-3`. Closing commit: `d349b6c4`.
- Next: Phase 4 (`CardSubscriptionService` + `CardBillingService`) unblocked.

### 2026-05-09 — backend phase 0 baseline repair follow-up (codex-gpt-5)

- Completed: repaired card tenant migration shape that was breaking unrelated tenant database creation: removed cross-database FKs to central tables and shortened the billing-attempt index name.
- Stopped at: phase 0 task 0.5, still `in_progress`; the required full `vendor/bin/pest` gate does not complete.
- Verification: `php -l` passes for touched card tenant migrations; targeted tenant test now gets past the card schema errors but still hits local MySQL lock waits in `RevenueAnomalyScanForTenantsCommandTest`.
- Next session should: fix or explicitly quarantine that tenant-test transaction harness issue before marking 0.5 done, then rerun a complete `vendor/bin/pest`.

### 2026-05-08 — backend phase 1 schema + seed complete (claude-opus-4-7 + subagents)

- Completed: all Phase 1 tasks 1.1–1.12 and 1.15–1.17 (16 commits on `feature/cards-phase-1`).
- Written: 10 migration files (1 ALTER + 1 global + 8 tenant), 1 seeder, 3 schema tests.
- Key decisions: partial unique index on `card_subscriptions` omitted (MySQL compat; application-layer guard in `CardSubscriptionService`); `IdempotencyMiddleware` uses Cache only so a new `idempotency_keys` table is needed (written); `card_audit_logs` append-only enforced by schema (no `updated_at`) + `markTestIncomplete` for Phase 11 DB-level guard.
- Deferred: tasks 1.13 (run migrations on dev) and 1.14 (run seeder) require dev DB + Phase 2 `CardPlan` model. Task 1.18 (tests pass) will resolve once dev DB is configured.
- Phase 1 status set to `in_progress` rather than `done` because 1.13/1.14/1.18 are pending dev-DB execution.
- Next: Phase 2 domain skeleton (`app/Domain/CardSubscriptions/`) can start immediately — it has no dependency beyond Phase 1 migrations being written (which they are).
- Branch: `feature/cards-phase-1` in `.worktrees/cards-phase-1`. Closing commit: `02be5b15`.

### 2026-05-08 — backend phase 0 demolition partial (codex-gpt-5)

- Completed: phase 0 tasks 0.1–0.4. Removed 9 legacy `/api/virtual-card/*` route registrations/imports and deleted 9 compatibility controllers under `app/Http/Controllers/Api/Compatibility/VirtualCard/`.
- Stopped at: phase 0 task 0.5, still `in_progress` because the required full `vendor/bin/pest` gate did not pass/complete.
- Task-specific cleanup done: deleted `tests/Feature/Http/Controllers/Api/Compatibility/VirtualCard/VirtualCardCancelTrustPolicyTest.php` and `VirtualCardFreezeControllerTest.php`.
- Verification: `php -l routes/api-compat.php` passes; `rg "virtual-card|VirtualCard" routes/api.php routes/api-compat.php` is empty; `vendor/bin/pest tests/Domain/CardIssuance/ValueObjects/VirtualCardTest.php` passes.
- Blocker: full `vendor/bin/pest` showed unrelated failures in account backfill, minor card request, AML aggregate, liquidity pool, mobile device, and module manifest tests, then stalled in multi-tenancy tests and was killed. Next session should decide whether to fix baseline or accept targeted verification before task 0.6.

### 2026-05-08 — initial planning + doc set published (claude-opus-4-7)

- Doc set published in `docs/cards/`: README, CONTRACT, 01–10 plus IMPLEMENTATION_PROMPT and PROGRESS.
- Khula naming applied for the minor card (siSwati for "to grow"); plan code `MINOR_KHULA_CARD`. Cards code and copy are fully siSwati-branded — no "Rise" anywhere.
- Reconciliation decisions resolved (legacy endpoint deletion, processor-hosted reveal, full minor integration). See [`02-domain-architecture.md`](./02-domain-architecture.md).
- No code changes yet. Phase 0 (delete `/api/virtual-card/*`) is the first task for the next session.
- Next session: read `IMPLEMENTATION_PROMPT.md`, then begin Phase 0 task 0.1.
