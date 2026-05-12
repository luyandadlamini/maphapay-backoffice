# MaphaPay Cards — Backend Docs

This folder contains the backend (Laravel + Filament) implementation plan for MaphaPay's card monetisation product. It works in tandem with the mobile plan at `maphapayrn/docs/cards/`.

## Reading order

1. [`CONTRACT.md`](./CONTRACT.md) — shared vocabulary. Canonical, byte-identical with mobile.
2. [`01-product-config.md`](./01-product-config.md) — plan matrix, fees, formulas. Canonical.
3. [`02-domain-architecture.md`](./02-domain-architecture.md) — new `app/Domain/CardSubscriptions/` layout and how it depends on the existing `CardIssuance` domain.
4. [`03-database-schema.md`](./03-database-schema.md) — every migration with column-by-column spec.
5. [`04-api-contract.md`](./04-api-contract.md) — every endpoint shape. Canonical.
6. [`05-services-and-rules.md`](./05-services-and-rules.md) — entitlements, billing, fees, risk, audit, lifecycle, minor-card flow.
7. [`06-filament-admin.md`](./06-filament-admin.md) — admin resources and action governance.
8. [`07-jobs-and-events.md`](./07-jobs-and-events.md) — Spatie events, scheduled jobs, push notifications.
9. [`08-processor-gateway.md`](./08-processor-gateway.md) — PCI scope, Demo/Rain adapters, webhook signing.
10. [`09-implementation-phases.md`](./09-implementation-phases.md) — phased plan with Pest test gates.
11. [`10-non-negotiables.md`](./10-non-negotiables.md) — hard rules.

## Relationship to existing CardIssuance domain

The backend already has a complete card-issuance domain at `app/Domain/CardIssuance/` with:

- `Card` and `Cardholder` Eloquent models (UUID + tenant-scoped)
- `CardIssuerInterface` + Demo / Rain / Marqeta adapters
- `CardProvisioningService`, `JitFundingService`, `CardTransactionSyncService`
- Webhook routes for authorisation / clearing / settlement
- Spatie events for `CardProvisioned`, `AuthorizationApproved`, `AuthorizationDeclined`

**Card monetisation does NOT replace this domain.** It introduces a new domain `app/Domain/CardSubscriptions/` that:

- Owns plans, subscriptions, billing, fees, audit, risk, disputes, physical orders, minor flow.
- Calls into `CardIssuance` for actual card creation, freezing, cancellation, and authorisation decisions.
- Wraps `CardProvisioningService` with entitlement checks before any processor call.
- Wraps `JitFundingService` with subscription/limit/fee logic during authorisation.

See [`02-domain-architecture.md`](./02-domain-architecture.md) for the full dependency graph.

## What changes in CardIssuance

Minimal but real:

- Migration: ALTER `cards` to add `tier`, `kind`, `lifecycle`, `lifecycle_config`, per-category limit columns, `is_default` flag.
- `CardProvisioningService::createVirtualCard()` accepts a `CardSubscription` argument and uses its plan's limits as the upper bound for the requested controls.
- `JitFundingService::evaluate()` calls `CardEntitlementService` before checking funds.
- `CardSubscriptions` is the sole owner of the mobile-facing `/v1/cards*`, `/v1/card-subscriptions*`, `/v1/card-fees*`, `/v1/card-transactions*`, `/v1/minor-card-requests*`, and `/webhooks/cards*` contract paths. `CardIssuance` keeps processor/provisioning internals only; it must not register overlapping mobile routes.

## What does NOT change

- The `CardIssuerInterface` contract.
- Demo / Rain adapter signatures.
- Webhook signature verification.
- Existing `cardholders` table.
- Existing minor-card request flow (`minor_card_requests`, `minor_card_limits`).

## Mobile repo links

Mobile plan: `/Users/Lihle/Development/Coding/maphapayrn/docs/cards/`. The mobile-side equivalent of each doc:

| This (backend) | Mobile equivalent |
|---|---|
| `04-api-contract.md` | `03-api-contract.md` (byte-identical) |
| `01-product-config.md` | `01-product-config.md` (byte-identical) |
| `CONTRACT.md` | `CONTRACT.md` (byte-identical) |
| `02-domain-architecture.md` | `02-architecture.md` (different perspective on the same boundary) |
| `09-implementation-phases.md` | `05-implementation-phases.md` (mobile phases lag backend by one) |
