# MaphaPay Product-Layer Reset Plan

Date: 2026-05-04
Decision status: approved architectural direction

## Decision

MaphaPay will stop treating its custom Laravel backend as the long-term financial core.

Target architecture:

- `MaphaPay mobile app` = customer experience
- `MaphaPay backend` = product layer only
- `Risk / compliance stack` = compliance and risk decisions
- `Payment Hub EE` = payment orchestration
- `Mifos X / Apache Fineract` = banking core and financial system of record

This means:

- no new custom ledger strategy
- no new custom core-banking truth
- no dependency on vibe-coded financial correctness
- product/backend code exists to serve mobile UX, product rules, identity, context, and integration orchestration

## Hard position

The current backend may contain useful product logic, route contracts, and integration knowledge.

It is **not** the thing we are trusting as the long-term banking core.

From this point onward, the program goal is:

`replace the current backend's financial-core role with Fineract + Payment Hub EE, while retaining only the product-layer responsibilities that properly belong to MaphaPay.`

## What MaphaPay backend should own

The backend should own only these categories:

### Identity and trust

- login
- sessions
- token issuance
- device registration
- biometric / OTP / PIN challenge coordination
- active account / user context

### Product experience state

- minor-account UX state that is not banking truth
- rewards UX and offer metadata
- social-money product state and messaging experience
- notifications
- app configuration
- feature flags
- API contract shaping for mobile

### Integration orchestration

- mapping mobile calls to downstream systems
- assembling responses from Fineract + Payment Hub EE + risk providers
- tracking cross-system references
- translating errors into stable mobile-facing contracts

### Policy and access composition

- account selection context
- role / guardian / organization access decisions at product layer
- routing requests to the correct downstream financial owner

## What MaphaPay backend must not own

### Banking truth

- final balances
- final transaction ledger
- financial accounting truth
- core deposit/withdrawal posting truth
- system-of-record financial reporting

### Rail orchestration truth

- provider workflow state as a permanent in-house subsystem
- long-term rail-by-rail payment choreography if Payment Hub EE can own it

## What Fineract should own

- customers/clients
- deposit accounts / wallet accounts
- savings accounts / pockets
- financial postings
- charges and fees
- accounting entries
- core financial audit trail
- financial reports

## What Payment Hub EE should own

- external rail execution workflow
- provider callbacks
- asynchronous payment status progression
- retries and reconciliation state for payment rails
- connector-level orchestration
- handoff into banking-core posting flows

## What the current codebase becomes

The current repo is no longer "the future financial system."

It becomes one of two things:

1. a temporary transition system while the new architecture is built
2. the final product-layer backend after financial responsibilities are stripped out

The correct posture is:

- keep only what belongs in the product layer
- discard or quarantine what tries to act like a banking core

## Program model

This is not a refactor.

This is a pre-production backend reset.

Because MaphaPay is still in development and has not yet been pitched/launched through a banking partner, we do **not** need to optimize for live-user cutover.

The right way to do this is:

- design the target architecture explicitly
- preserve only the product knowledge worth keeping
- rebuild fast around the correct system boundaries
- discard financial-core code that does not belong in the final architecture

## Non-negotiable safety principles

1. No silent dual sources of financial truth.
2. Every money flow must have one declared system of record.
3. Every test/demo payment must be traceable across all systems.
4. Product-layer code must not recreate hidden banking logic "for convenience."
5. If a capability cannot yet be owned cleanly by Fineract or Payment Hub EE, it stays gated until it can.
6. Pre-production speed is allowed; architectural sloppiness is not.

## Reset execution plan

## Phase 1: Freeze the architecture decision

Write down the exact responsibilities of each layer and treat them as binding.

Deliverables:

- this reset plan
- system-boundary doc
- responsibility matrix for every current endpoint and workflow

Key rule:

- every current route must be classified as:
  - `keep as product layer`
  - `rebuild on Fineract`
  - `rebuild on Payment Hub EE`
  - `retire`

## Phase 2: Inventory the current app and backend as product knowledge, not as core truth

Goal:

extract what the app needs without inheriting the old backend's financial-core design.

Inventory:

- mobile endpoint contracts
- dashboard response shape
- transaction-history response shape
- send-money UX flow
- request-money UX flow
- pockets UX flow
- minor-account UX flow
- organization/merchant UX flow
- notifications/rewards/social UX dependencies

For each workflow, capture:

- what the mobile app expects
- which data is true banking data
- which data is product metadata
- which downstream target should own it in the new architecture

Exit criteria:

- we know what the app needs from a product-layer API
- we stop treating existing backend internals as sacred

## Phase 3: Stand up the target banking core

Goal:

prove Fineract can be the actual banking core for MaphaPay.

Validate:

- client/customer model
- personal wallet accounts
- savings/pocket accounts
- fees and charges
- transaction extraction APIs
- accounting visibility
- external reference mapping
- operational reporting

Pass/fail matrix must cover:

- personal wallet
- pocket/savings
- merchant/business account model
- guardian/minor relationship support model
- transaction history retrieval
- balance retrieval
- posting lifecycle

Important:

If Fineract does not natively fit one of these, record whether the answer is:

- configuration
- extension/plugin
- product-layer workaround
- unsupported

## Phase 4: Stand up payment orchestration

Goal:

prove Payment Hub EE can be the payment orchestrator you want.

Validate:

- deployment model
- connector model
- callback flow
- status progression
- audit/reconciliation capabilities
- handoff into Fineract

For MaphaPay specifically, validate first:

- MTN MoMo
- one bank-linked or switch-linked flow

Output:

- exact first-rail implementation choice
- exact connector work required
- exact operational responsibilities

## Phase 5: Design the final product-layer backend

Goal:

design the backend you actually want, not the one that accumulated organically.

Recommended modules:

- `Auth`
- `Identity`
- `DeviceTrust`
- `Profile`
- `AccountContext`
- `ProductConfig`
- `Notifications`
- `Rewards`
- `SocialMoney`
- `MinorAccountsProduct`
- `FineractIntegration`
- `PaymentOrchestrationIntegration`
- `RiskIntegration`
- `ResponseComposition`

What should not exist as first-class in the final product backend:

- custom ledger domain
- custom financial posting domain
- custom financial balance source of truth
- custom payment-rail orchestration core

## Phase 6: Build the integration contract layer

Before rebuilding all endpoints, define canonical internal contracts for:

- user-to-Fineract client mapping
- account-context-to-Fineract account mapping
- product request to payment-orchestrator request mapping
- payment-orchestrator result to mobile response mapping
- Fineract transaction to mobile transaction display mapping

Canonical references must include:

- `maphapay_request_id`
- `maphapay_user_uuid`
- `maphapay_account_uuid`
- `payment_orchestration_reference`
- `fineract_client_external_id`
- `fineract_account_external_id`
- `fineract_transaction_reference`

## Phase 7: Rebuild bounded API surfaces

Rebuild in this order:

1. auth/session/device trust
2. dashboard + balance + transaction read APIs
3. wallet top-up
4. cash-out
5. wallet-to-wallet send money
6. pockets
7. request-money
8. merchant/business flows
9. minor-account financial flows
10. rewards/social integrations that depend on finance events

Reason:

- reads first
- then simplest financial writes
- then more policy-heavy flows
- then minor-account flows last because they are the most product-specific

## Phase 8: End-to-end verification in the target stack

Because this is pre-production, we can verify directly on the target architecture instead of building elaborate transition mechanics.

For each rebuilt capability:

- verify end-to-end behavior against Fineract and Payment Hub EE
- verify reference traceability
- verify idempotency behavior
- verify timeout/retry behavior
- verify operator/admin visibility

Nothing is considered done until:

- the system of record is explicit
- the downstream owner is correct
- the mobile contract is satisfied

## Phase 9: Remove the old financial-core role

Once the target capability exists on the new architecture:

- disable old ledger-driven write ownership
- remove dead custom posting paths
- remove dead financial-core code that no longer belongs
- keep only product-layer modules

At that point the backend becomes what you actually want:

- a product layer
- not a banking core

## Repo strategy

You have two valid options.

### Option A: Rebuild within the current repo first

Use `maphapay-backoffice` as the working area for the product-layer rebuild, but aggressively retire/quarantine financial-core code as the new model lands.

Best when:

- you want continuity
- you want to preserve working auth/product contracts
- you want to move fast without repo sprawl

### Option B: Start a clean product-layer repo

Create a fresh backend repo whose job is only:

- auth
- product APIs
- context
- orchestration
- downstream integrations

Best when:

- you want strong architectural discipline
- you do not want legacy financial-core code in the new service boundary

My recommendation:

- if you want maximum architectural cleanliness, start a clean product-layer repo after the inventory phase
- keep this repo as the source of product knowledge and temporary reference only

That decision should be driven by:

- how much existing auth/session/product code is genuinely reusable
- how contaminated the current repo is by financial-core assumptions
- whether a clean repo will help enforce discipline faster

## Immediate execution checklist

1. lock the architecture decision
2. classify every current finance-related endpoint as keep/rebuild/retire
3. define the product-layer-only module map
4. stand up local/dev Fineract
5. validate wallet/pocket/account model
6. stand up local/dev Payment Hub EE
7. validate first payment rail path
8. define canonical cross-system reference mapping
9. rebuild dashboard/transaction reads against target stack
10. rebuild first money-write flow

## Stop rules

Stop and redesign if any of these happen:

- MaphaPay backend starts re-creating ledger truth
- product-layer services accumulate hidden financial posting logic
- Payment Hub EE is kept in name only while real orchestration drifts back into Laravel
- Fineract fit problems are being papered over by growing custom banking logic in MaphaPay

## Final position

Your instinct is correct.

If the goal is a country-scale financial product, the backend should not be a custom improvised banking core.

The right answer is not to defend the old system.

The right answer is to use the old system only as a source of product knowledge while you replace its financial-core role with:

- Mifos X / Apache Fineract for banking core
- Payment Hub EE for payment orchestration
- MaphaPay backend for product-layer responsibilities only
