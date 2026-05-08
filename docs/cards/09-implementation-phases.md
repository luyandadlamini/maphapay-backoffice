# 09 — Backend Implementation Phases

Ordered phases for the backend. Each phase ends with Pest tests as the acceptance gate. A phase is not "done" until tests pass on the dev environment.

Mobile phases (in `maphapayrn/docs/cards/05-implementation-phases.md`) lag backend phases by one — the mobile team consumes endpoints already deployed.

---

## Phase 0 — Demolition

Pre-prod: there are no users, no data to preserve. Delete legacy mobile-facing virtual-card endpoints.

Files to delete:
- `app/Http/Controllers/Api/VirtualCard/` (or wherever `/api/virtual-card/*` controllers live — search for `Route::post('virtual-card/...` to locate)
- The route registration in `routes/api.php` or `api-compat.php`
- Any service that exists only to serve those endpoints (e.g. a `VirtualCardLegacyService`)

Keep: `app/Domain/CardIssuance/` — that's the new contract surface.

```bash
grep -rn "virtual-card" routes/ app/Http/Controllers/
```

Remove every match. Run the existing test suite to confirm no test depends on legacy endpoints. If a test does, delete it.

**Acceptance:** `php artisan route:list | grep virtual-card` returns nothing. `vendor/bin/pest` passes.

---

## Phase 1 — Schema and seed

Create migrations from [`03-database-schema.md`](./03-database-schema.md). Run them.

```bash
php artisan migrate --path=database/migrations/2026_05_08_000001_alter_cards_add_monetisation_fields.php --force
php artisan migrate --path=database/migrations/2026_05_08_000002_create_card_plans_table.php --force
php artisan tenants:migrate --path=database/migrations/tenant/2026_05_08_000003_create_card_subscriptions_table.php --force
# ... rest from §12 of 03-database-schema.md ...
php artisan db:seed --class=Database\\Seeders\\CardPlanSeeder --force
```

**Pest tests:**

```
tests/Feature/Cards/Schema/CardPlansSeededTest.php
    -- asserts all 6 plans exist with values matching docs/cards/01-product-config.md §1 verbatim
tests/Feature/Cards/Schema/CardsTableHasMonetisationColumnsTest.php
    -- asserts ALTER columns exist
tests/Feature/Cards/Schema/AuditLogAppendOnlyTest.php
    -- attempts UPDATE/DELETE on card_audit_logs and asserts policy denial OR DB-level rejection
```

**Acceptance:** all schema tests pass; `SELECT COUNT(*) FROM card_plans` = 6.

---

## Phase 2 — Domain skeleton (no controllers, no routes)

Create `app/Domain/CardSubscriptions/` per [`02-domain-architecture.md`](./02-domain-architecture.md):

- `module.json`
- `Models/` — Eloquent classes for every table, with `HasUuids` and (where tenant-scoped) `UsesTenantConnection`.
- `Enums/` — every enum from `CONTRACT.md` as PHP `BackedEnum`.
- `ValueObjects/` — Money-related helpers, input DTOs.
- `Services/` — class skeletons with method signatures from [`05-services-and-rules.md`](./05-services-and-rules.md). Method bodies throw `LogicException("not implemented")` for now.
- `Events/` — every event from [`07-jobs-and-events.md`](./07-jobs-and-events.md) §1.
- `Providers/CardSubscriptionsServiceProvider.php` — registered in `bootstrap/providers.php`.

**Pest tests:**

```
tests/Feature/Cards/DomainBootstrapTest.php
    -- asserts service container resolves every service
    -- asserts every event class can be instantiated with sample data
    -- asserts every model can be created via factory (factories needed for each model)
```

**Acceptance:** dependency-injection wiring works; tests pass without touching DB write paths.

---

## Phase 3 — CardEntitlementService and CardFeeService

Implement the methods from [`05-services-and-rules.md`](./05-services-and-rules.md) §1 and §4. These are pure logic — no controllers yet.

**Pest tests:**

```
tests/Feature/Cards/Services/CardEntitlementServiceTest.php
    -- canSubscribeToPlan: cover EVERY decline rule (account inactive, KYC pending, high risk, plan inactive, minor on adult plan, adult on minor plan, duplicate sub, insufficient funds)
    -- canCreateVirtualCard: limits at boundary (cards = max - 1 allowed; cards = max denied)
    -- canRequestPhysicalCard: similar
    -- canAuthorize: every decline reason from CONTRACT.md §8

tests/Feature/Cards/Services/CardFeeServiceTest.php
    -- calculateFxFee: SZL → 0; ZAR → 0; USD on each plan → expected value (table from 01-product-config.md §3)
    -- calculateAtmFee: each plan, multiple withdrawal amounts (table from §4)
    -- chargeVirtualReplacementFee: free allowance respected, then charged
    -- previewTransaction: full breakdown matches expected
```

Use real `CardPlan` records (seeded in phase 1). Run tests with `RefreshDatabase` so the seeder is re-run.

**Acceptance:** every entitlement and fee rule in the docs has a test that exercises it.

---

## Phase 4 — CardSubscriptionService and CardBillingService

Implement [`05-services-and-rules.md`](./05-services-and-rules.md) §2 and §3.

**Pest tests:**

```
tests/Feature/Cards/Services/CardSubscriptionServiceTest.php
    -- subscribe (adult, happy path) — wallet debited, sub active, audit + event
    -- subscribe (insufficient funds) — sub not created, no debit
    -- subscribe (duplicate) — second call rejected
    -- subscribe (minor without approved request) — rejected
    -- subscribe (minor with approved request, guardian payer) — sub active, guardian wallet debited
    -- upgrade (proration calculated, charge via wallet)
    -- downgrade (excess cards: force=false rejects; force=true freezes excess)
    -- cancel (sub cancelled, cards stay active until current_period_end)

tests/Feature/Cards/Services/CardBillingServiceTest.php
    -- billRenewal happy path → wallet debited, fee row charged, sub period rolled
    -- billRenewal insufficient funds → past_due, fee row failed, no debit
    -- handleFailedPayment → past_due → suspended after grace
    -- restore on payment after suspension → cards reactivate (except admin-frozen)
    -- after 14 days suspended → terminate (cards cancelled)

tests/Feature/Cards/Services/CardBillingIdempotencyTest.php
    -- billRenewal called twice with same subscription/billing date → only one ledger posting
```

**Acceptance:** every state transition in the billing FSM ([`01-product-config.md`](./01-product-config.md) §6) has a test that demonstrates it.

---

## Phase 5 — Routes, controllers, requests, resources

Implement HTTP layer per [`04-api-contract.md`](./04-api-contract.md).

- Routes file: `app/Domain/CardSubscriptions/Routes/api.php` with all 27 endpoints from §1.
- Form requests: validation rules per request body shape.
- Controllers: thin wrappers calling services.
- API resources: response shaping per the JSON examples.
- Translate `EntitlementDeniedException` to error envelope (`status: error`, `data.code = $exception->code->value`).

**Pest tests:**

```
tests/Feature/Cards/Http/CardPlanControllerTest.php
    -- adult sees adult plans; minor sees only MINOR_KHULA_CARD; FREE_WALLET excluded from list (auto-assigned, not a choice)

tests/Feature/Cards/Http/CardSubscriptionControllerTest.php
    -- subscribe: idempotency-key reuse on retry returns same subscription
    -- subscribe: missing idempotency-key → 400
    -- subscribe: PLAN_NOT_AVAILABLE returned when plan inactive
    -- get current returns null shape when no sub

tests/Feature/Cards/Http/VirtualCardControllerTest.php
    -- create card: full payload, fields match CONTRACT
    -- create card: limits exceeding plan rejected with proper data.code
    -- list: only own cards returned

tests/Feature/Cards/Http/CardRevealControllerTest.php
    -- step-up required → 422 STEP_UP_AUTH_REQUIRED if mobile-trust missing
    -- on success: returns reveal_url, expires_at; URL points at issuer origin
    -- ALWAYS writes audit log BEFORE returning response

tests/Feature/Cards/Http/PhysicalCardOrderControllerTest.php
    -- request: charges issuance fee
    -- request: rejected if plan disallows physical
    -- activate: requires step-up; transitions card to active

tests/Feature/Cards/Http/CardFeePreviewControllerTest.php
    -- preview matches CardFeeService output (regression guard)

tests/Feature/Cards/Http/MinorCardRequestControllerTest.php
    -- approve by guardian: triggers underlying action
    -- approve by non-guardian: 403
    -- deny: requires reason, persisted

tests/Feature/Cards/Http/RateLimitTest.php
    -- per-card reveal limiter blocks 6th request in 1 minute
```

**Acceptance:** every endpoint in `04-api-contract.md` §1 has a corresponding HTTP test.

---

## Phase 6 — Webhooks and processor adapters

Implement [`08-processor-gateway.md`](./08-processor-gateway.md) §3, §4, §5, §6.

- Demo adapter `generateRevealUrl()` + reveal Blade view.
- Rain adapter stub (returns `ProcessorUnavailable` until real creds).
- Webhook controller with signature verification + idempotency + audit-before-mutate.
- Authorisation/clearing/reversal/refund jobs.

**Pest tests:**

```
tests/Feature/Cards/Webhooks/AuthorisationWebhookTest.php
    -- valid signature, fresh event_id → approves authorisation, persists transaction, places hold
    -- invalid signature → 401
    -- duplicate event_id → 200 with no state change
    -- unknown card_token → 200 + audit row + ops alert dispatched
    -- declines per CONTRACT.md §8

tests/Feature/Cards/Webhooks/ClearingWebhookTest.php
    -- matches authorisation; settlement amount differs → wallet adjusted; user notified
    -- orphan settlement (no auth) → settled with alert

tests/Feature/Cards/Adapters/DemoCardIssuerAdapterTest.php
    -- generateRevealUrl: produces URL with valid HMAC, expiry within ttlSeconds
    -- expired token rejected by reveal Blade view (integration test on /demo-cards/reveal)

tests/Feature/Cards/Adapters/RainCardIssuerAdapterTest.php (mocked HTTP)
    -- createVirtualCard: maps fixture response correctly
    -- generateRevealUrl: forwards expiry from fixture
    -- verifyWebhookSignature uses hash_equals
```

**Acceptance:** webhook smoke test on dev environment using the demo adapter passes; the entire mobile-test flow can run end-to-end with the demo processor.

---

## Phase 7 — Filament admin

Implement [`06-filament-admin.md`](./06-filament-admin.md). Resources, policies, action governance.

**Pest tests:**

```
tests/Feature/Filament/Cards/CardSubscriptionResourceTest.php
    -- admin_manager sees the resource; support_agent has view-only
    -- Suspend action requires reason, succeeds, writes audit log

tests/Feature/Filament/Cards/CardSubscriptionAdminActionsTest.php
    -- Force cancel cancels cards immediately
    -- Waive next month creates a card_fee row with status=waived

tests/Feature/Filament/Cards/CardAuditLogResourceTest.php
    -- read-only: no create, no edit, no delete; bulk export requires reason
    -- compliance_officer can view; support_agent cannot

tests/Feature/Filament/Cards/MinorCardSubscriptionAdminOverrideTest.php
    -- override-approve requires super_admin
    -- override-approve creates audit row with action minor_request.admin_override_approved
```

**Acceptance:** admin role-gating verified for every action; audit trail visible end-to-end.

---

## Phase 8 — Jobs and events

Implement [`07-jobs-and-events.md`](./07-jobs-and-events.md). Schedule registration, listeners, push notifications.

**Pest tests:**

```
tests/Feature/Cards/Jobs/BillCardSubscriptionsJobTest.php
    -- enqueues per-subscription jobs only for subs with next_billing_date <= now AND status=active

tests/Feature/Cards/Jobs/ProcessSingleSubscriptionRenewalJobTest.php
    -- happy path
    -- insufficient funds → past_due
    -- duplicate run for same sub on same date → no double-charge

tests/Feature/Cards/Jobs/SuspendPastDueSubscriptionsJobTest.php
    -- only suspends subs whose grace_period_ends_at <= now

tests/Feature/Cards/Jobs/CancelLongPastDueSubscriptionsJobTest.php
    -- only cancels subs suspended for >= 11 days

tests/Feature/Cards/Listeners/NotifyCardSubscriptionLifecycleTest.php
    -- on CardSubscriptionPastDue, push notification sent to payer (and subscriber if different)

tests/Feature/Cards/Listeners/ApplyRiskFreezeOnCriticalEventTest.php
    -- on critical risk event, all user's active cards transition to suspended
```

**Acceptance:** scheduled commands appear in `php artisan schedule:list`; manual run of each job reaches the assertion.

---

## Phase 9 — Risk service

Implement [`05-services-and-rules.md`](./05-services-and-rules.md) §6 fully (entitlement skeletons in phase 3 referenced this; now wire it up).

**Pest tests:**

```
tests/Feature/Cards/Services/CardRiskServiceTest.php
    -- 6 declines in 10 min on same card → high event + freeze (via listener)
    -- 11 declines in 24h → high event
    -- 3 different merchants declining in 30 min → high
    -- ATM attempt on virtual-only plan → medium event, decline
    -- blocked MCC → medium event, decline
    -- replacements > 2 in 30 days → medium event, blocks new replacement
    -- disputes > 2 in 60 days → medium event
```

**Acceptance:** every threshold in `01-product-config.md` §9 has a test.

---

## Phase 10 — End-to-end smoke test

Single Pest test that walks the entire flow:

```
tests/Feature/Cards/EndToEndSmokeTest.php

it('a user can subscribe, create a card, transact, and cancel', function () {
    // 1. Seed plans, create user, top up wallet.
    // 2. POST /v1/card-subscriptions { plan_code: VIRTUAL_PLUS } → active sub.
    // 3. POST /v1/cards/virtual { ... } → card active.
    // 4. Simulate authorisation webhook → approved, wallet held.
    // 5. Simulate clearing webhook → wallet debited.
    // 6. GET /v1/cards/{id}/transactions → tx visible.
    // 7. GET /v1/cards/{id}/reveal (with step-up trx) → reveal URL.
    // 8. POST /v1/card-subscriptions/cancel → status=cancelled, cards still active.
    // 9. Run CloseCardsOnSubscriptionEndJob with time travel → cards cancelled.
    // 10. Audit log contains: subscription.created, card.created, processor.webhook_received (×2), card.reveal_requested, subscription.cancelled, card.admin_cancelled.
});

it('a guardian can subscribe a Khula minor through the approval flow', function () {
    // 1. Seed plans, create guardian + Khula-tier minor (13–17).
    // 2. As minor: POST /v1/minor-card-requests/subscribe → request created.
    // 3. As guardian: POST /v1/minor-card-requests/{id}/approve → subscription active, guardian wallet debited.
    // 4. As minor: GET /v1/cards → empty (card creation needs another request).
    // 5. As minor: POST /v1/minor-card-requests/card → request created.
    // 6. As guardian: approve → card active for minor.
    // 7. Simulate authorisation enforcing minor_card_limits → declined when above limit.
});
```

**Acceptance:** both end-to-end tests pass on a fresh DB.

---

## Phase 11 — Pre-launch security audit

Run the security checks from [`08-processor-gateway.md`](./08-processor-gateway.md) §11. Verify the checklist in §14.

Manual verification:
- [ ] Try to insert a 16-digit string into `card_audit_logs.metadata.notes` via the service. Service rejects.
- [ ] Try to call the reveal endpoint with a card belonging to another user. 404.
- [ ] Try to replay a webhook with a tampered body. 401.
- [ ] Try to call a Filament admin action without the required role. 403.
- [ ] Pull the latest mobile build, sign in, and confirm the reveal flow loads only the configured issuer origin.

**Acceptance:** all checks pass; a security review has been requested via the `requesting-code-review` skill or the team's review process.

---

## Phase 12 — Launch staging rollout

Order of feature-flag flips:

1. `cards_monetisation_enabled = true` for staff tenants only.
2. After 1 week, `card_subscriptions_enabled = true` (allows subscribe API to be reached).
3. After staff confirm: `virtual_card_lite_enabled`, `virtual_card_plus_enabled`.
4. `physical_card_enabled = true` once first physical pilot is greenlit.
5. `minor_khula_card_enabled = true` once both legal review (parental consent UX) and product greenlight on the Khula (minor) flow.
6. `premium_card_enabled = true` once support has staffing for higher-touch tier.

`card_admin_risk_controls_enabled` defaults to `true` even before launch (admin needs to be able to inspect/freeze even pre-launch test cards).

---

## Definition of done (per phase)

A phase is done when:

1. All listed migrations / files / classes exist.
2. All listed Pest tests exist and pass.
3. `php artisan route:list | grep cards` matches the routes in `04-api-contract.md` §1 exactly (no extras, no missing).
4. `php artisan schedule:list` shows every scheduled job with the cadence from `07-jobs-and-events.md` §3.
5. CI runs the new tests; they pass.
6. Security CI grep ([`08-processor-gateway.md`](./08-processor-gateway.md) §11) passes.

If a phase ships with deferred items, they go in `docs/cards/phase-N-followups.md` (per repo) — never carried implicitly into the next phase.
