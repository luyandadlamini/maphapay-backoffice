# Mifos / Fineract Plan Validation

Date: 2026-05-04
Source plan reviewed: `/Users/Lihle/Downloads/mapha_pay_mifos_first_architecture_document-2.md`
Scope reviewed against:

- live `maphapay-backoffice` codebase
- live `maphapayrn` companion repo assumptions already mirrored into backend docs
- current Apache Fineract docs/repo
- current Mifos Payment Hub EE docs/repo

## Executive verdict

The draft is directionally useful, but it is not yet a legitimate implementation plan for this codebase.

Its main problem is not ambition. Its main problem is that it describes a target state as if the current backend were only a thin product/session layer already. That is false.

Today `maphapay-backoffice` is already a production-style financial platform with its own:

- event-sourced account lifecycle
- asset transaction ledgering
- ledger posting service
- money-movement verification and idempotency
- MTN MoMo integration surface
- transaction monitoring / AML workflow surface
- mobile compatibility API surface

Because of that, this cannot be executed as a simple "replace custom backend with Fineract + Payment Hub EE" program. It has to be executed as a controlled migration / coexistence program.

## What the draft gets right

### 1. Thin-client principle is right

The mobile app should continue talking only to MaphaPay APIs, not directly to Fineract, Payment Hub EE, or external providers.

That matches both product reality and the current route surface:

- `/api/send-money/store`
- `/api/request-money/*`
- `/api/mtn/*`
- `/api/dashboard`
- `/api/transactions`
- social-money, rewards, pockets, scheduled-send, minor-account endpoints

Evidence:

- [routes/api-compat.php](/Users/Lihle/Development/Coding/maphapay-backoffice/routes/api-compat.php:112)
- [routes/api-compat.php](/Users/Lihle/Development/Coding/maphapay-backoffice/routes/api-compat.php:166)
- [routes/api-compat.php](/Users/Lihle/Development/Coding/maphapay-backoffice/routes/api-compat.php:183)
- [routes/api-compat.php](/Users/Lihle/Development/Coding/maphapay-backoffice/routes/api-compat.php:203)

### 2. Hybrid product ownership is right

The draft's personal-vs-organization split is compatible with the current backend direction and existing architecture note.

Evidence:

- [docs/hybrid-finance-scope-architecture.md](/Users/Lihle/Development/Coding/maphapay-backoffice/docs/hybrid-finance-scope-architecture.md:5)
- [docs/hybrid-finance-scope-architecture.md](/Users/Lihle/Development/Coding/maphapay-backoffice/docs/hybrid-finance-scope-architecture.md:42)
- [docs/hybrid-finance-scope-architecture.md](/Users/Lihle/Development/Coding/maphapay-backoffice/docs/hybrid-finance-scope-architecture.md:92)

### 3. External payment orchestration as a separate concern is right

Treating payment-rail orchestration as distinct from product UX and session/auth concerns is a sound target.

Payment Hub EE is intended as a gateway/orchestration layer, not as a customer-facing product backend.

External evidence:

- Mifos Payment Hub EE business overview: [mifos.gitbook.io/docs/payment-hub-ee/business-overview](https://mifos.gitbook.io/docs/payment-hub-ee/business-overview)
- Mifos Payment Hub EE technical overview: [mifos.gitbook.io/docs/payment-hub-ee/overview](https://mifos.gitbook.io/docs/payment-hub-ee/overview)

## What is materially wrong or unsafe

### 1. The draft treats the current backend as if it does not already contain a financial core

This is the largest flaw.

The plan says:

- "MaphaPay must not build its own custom banking ledger"
- "Balances live in Fineract"
- "Transactions live in Fineract"
- "Existing financial logic should be treated as temporary until replaced by Fineract and Payment Hub EE"

But the live codebase already contains a custom financial core:

- event-sourced `LedgerAggregate`
- event-sourced `AssetTransactionAggregate`
- explicit `LedgerPostingService`
- idempotent authorized transaction orchestration
- transaction projections and balance updates

Evidence:

- source plan claim: `/Users/Lihle/Downloads/mapha_pay_mifos_first_architecture_document-2.md:88-101`
- source plan Phase 0 claim: `/Users/Lihle/Downloads/mapha_pay_mifos_first_architecture_document-2.md:1508-1512`
- [docs/02-ARCHITECTURE/ARCHITECTURE.md](/Users/Lihle/Development/Coding/maphapay-backoffice/docs/02-ARCHITECTURE/ARCHITECTURE.md:26)
- [docs/02-ARCHITECTURE/ARCHITECTURE.md](/Users/Lihle/Development/Coding/maphapay-backoffice/docs/02-ARCHITECTURE/ARCHITECTURE.md:77)
- [app/Domain/Account/Aggregates/LedgerAggregate.php](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Account/Aggregates/LedgerAggregate.php:16)
- [app/Domain/Asset/Aggregates/AssetTransactionAggregate.php](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Asset/Aggregates/AssetTransactionAggregate.php:14)
- [app/Domain/Ledger/Services/LedgerPostingService.php](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Ledger/Services/LedgerPostingService.php:26)
- [app/Domain/AuthorizedTransaction/Services/AuthorizedTransactionManager.php](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/AuthorizedTransaction/Services/AuthorizedTransactionManager.php:28)

Implication:

This is not a greenfield architecture selection. It is a ledger migration problem. The plan must say that explicitly.

### 2. Payment Hub EE is being treated as if Eswatini connectors already exist or are near-free

That is not supported by the Mifos sources.

The current Mifos material presents Payment Hub EE as:

- a gateway/orchestration layer
- strongly Mojaloop-oriented in its reference deployment
- shipping with Mojaloop + AMS connector concepts
- extensible via additional connectors

It does **not** prove that MTN MoMo Eswatini, e-Mali, ShareSha, ePocket, EPS, or local-bank connectors are already available.

External evidence:

- Payment Hub EE business overview says the reference payment system connector is Mojaloop and the AMS connector is for Mifos/Fineract: [mifos.gitbook.io/docs/payment-hub-ee/business-overview](https://mifos.gitbook.io/docs/payment-hub-ee/business-overview)
- Payment Hub EE technical overview shows internal structure around Mojaloop connector, channel connector, AMS connector, audit/operations components: [mifos.gitbook.io/docs/payment-hub-ee/overview](https://mifos.gitbook.io/docs/payment-hub-ee/overview)
- repo entrypoint: [github.com/openMF/ph-ee-start-here](https://github.com/openMF/ph-ee-start-here)

Implication:

The plan cannot list local-rail connectors as if they are a platform choice already validated. They are a separate connector-development program with unknown effort and operational requirements.

### 3. Fineract is being treated as if it is a drop-in wallet core for this product model

That is not yet proven.

What is proven from official Fineract sources:

- Fineract is an API-first core banking platform
- it supports deposit/savings/accounting flows
- it is extensible via plugin JARs

What is **not** yet proven for this exact MaphaPay product:

- guardian/minor-account financial lifecycle fit
- pocket model fit
- consumer wallet semantics fit
- required transaction/reference mapping fit
- maker-checker + mobile UX semantics fit
- migration path for existing ledger state

External evidence:

- Fineract docs: [fineract.apache.org/docs/current/](https://fineract.apache.org/docs/current/)
- Fineract repo/Swagger note: [github.com/apache/fineract](https://github.com/apache/fineract)

Implication:

Phase 2 cannot be a generic "Fineract proof of concept". It must be a product-fit proof of concept with explicit pass/fail criteria tied to MaphaPay semantics.

### 4. The repo strategy is over-eager and not grounded in the current working repos

The draft says current repos are `maphapay-mobile` and `maphapay-backoffice`, then recommends splitting into:

- `maphapay-api`
- `maphapay-platform`
- `maphapay-fineract-config`
- `maphapay-payment-connectors`
- `maphapay-docs`

Problems:

- the actual mobile repo in active use is `maphapayrn`, not `maphapay-mobile`
- no justification is given for why the current code should be split before interface boundaries are proven
- early repo-splitting will make the migration harder, not easier

Source plan evidence:

- `/Users/Lihle/Downloads/mapha_pay_mifos_first_architecture_document-2.md:859-889`

Implication:

Do not start with repo proliferation. Start with interface boundaries and reference mappings inside the current repos. Split repos only after those seams are stable.

### 5. "Freeze custom financial-core expansion" is too blunt for the actual system state

This backend still has live roadmap pressure in money movement, minor accounts, MTN flows, pockets, rewards, and social money.

A blanket freeze would be counterproductive unless reworded.

Correct version:

- freeze **new net-new ledger abstractions**
- allow **stabilization, auditability, reconciliation, and compatibility work** on the existing core
- require every new financial feature to declare whether it is:
  - legacy-core stabilization
  - migration bridge
  - Fineract-bound future state

## What the live codebase actually is today

This backend is not just "auth + session + product UI state."

It is already a composite platform with at least these responsibilities:

### Financial core

- event-sourced account lifecycle
- event-sourced asset transactions
- posting service and reversal/adjustment mechanics
- balance read models and transaction projections

Evidence:

- [docs/02-ARCHITECTURE/ARCHITECTURE.md](/Users/Lihle/Development/Coding/maphapay-backoffice/docs/02-ARCHITECTURE/ARCHITECTURE.md:63)
- [docs/02-ARCHITECTURE/ARCHITECTURE.md](/Users/Lihle/Development/Coding/maphapay-backoffice/docs/02-ARCHITECTURE/ARCHITECTURE.md:116)
- [app/Domain/Ledger/Services/LedgerPostingService.php](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Ledger/Services/LedgerPostingService.php:32)

### Money-movement control plane

- two-step authorization
- OTP / PIN / biometric verification
- trust-policy enforcement
- idempotency guardrails

Evidence:

- [app/Domain/AuthorizedTransaction/Services/AuthorizedTransactionManager.php](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/AuthorizedTransaction/Services/AuthorizedTransactionManager.php:28)

### Payment-rail integration surface

- MTN MoMo request-to-pay
- MTN disbursement
- status polling
- callback handling

Evidence:

- [app/Domain/MtnMomo/Services/MtnMomoClient.php](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/MtnMomo/Services/MtnMomoClient.php:12)
- [routes/api-compat.php](/Users/Lihle/Development/Coding/maphapay-backoffice/routes/api-compat.php:166)

### Risk / compliance surface

- transaction monitoring aggregate/service
- SAR creation
- suspicious activity events

Evidence:

- [app/Domain/Compliance/Services/TransactionMonitoringService.php](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Compliance/Services/TransactionMonitoringService.php:16)

### Product / mobile contract layer

- dashboard
- transactions
- send money
- request money
- pockets
- social money
- rewards
- minor-account support

Evidence:

- [routes/api-compat.php](/Users/Lihle/Development/Coding/maphapay-backoffice/routes/api-compat.php:112)
- [routes/api-compat.php](/Users/Lihle/Development/Coding/maphapay-backoffice/routes/api-compat.php:183)
- [routes/api-compat.php](/Users/Lihle/Development/Coding/maphapay-backoffice/routes/api-compat.php:203)

## Corrected planning stance

The legitimate plan is:

1. treat the current backend as the incumbent ledger and orchestration system
2. prove Fineract and Payment Hub EE against real MaphaPay semantics
3. introduce adapters and reference mapping first
4. migrate one bounded flow at a time
5. retire incumbent ledger ownership only after reconciliation-grade parity exists

## Corrected phase order

### Phase A: Migration inventory and invariants

Before any repo split or Fineract-first rewiring, document:

- canonical account types in current backend
- canonical balance sources
- canonical transaction identifiers
- current idempotency and retry model
- current mobile contract dependencies
- current organization-vs-personal scoping rules
- current minor-account invariants

Deliverable:

- one migration inventory doc in `docs/`
- one source-of-truth mapping table for:
  - MaphaPay user
  - MaphaPay account UUID
  - future Fineract client ID / external ID
  - future Fineract account ID / external ID
  - MaphaPay transaction reference
  - future Payment Hub reference
  - future Fineract transaction reference

### Phase B: Product-fit POC for Fineract

Do not ask "can Fineract do banking?"

Ask:

- can Fineract model MaphaPay wallet + pockets + merchant + minor accounts without violating product rules?
- what requires plugin/customization vs config?
- what cannot fit cleanly?

Pass/fail matrix must include:

- personal wallet
- pocket variants
- merchant account
- minor/guardian relationship handling
- transaction history extraction
- external-id strategy
- accounting/reporting extraction

### Phase C: Orchestration-fit POC for Payment Hub EE

Do not ask "can Payment Hub EE orchestrate payments?"

Ask:

- does it reduce work for the first real MaphaPay rail we care about?
- what is the actual connector effort for MTN MoMo Eswatini and EPS?
- what operational stack does it impose?
- do we need it before direct-provider integration stops being maintainable?

This phase should compare:

- direct MaphaPay adapter for first rail
- Payment Hub EE mediated adapter for first rail

If Payment Hub EE is not clearly net-positive early, defer it instead of forcing it in for ideological purity.

### Phase D: Adapter seam inside current backend

Inside `maphapay-backoffice`, create interface seams first:

- `CoreLedgerInterface`
- `CoreAccountStoreInterface`
- `ExternalRailOrchestratorInterface`
- `RiskDecisionProviderInterface`

Back them initially with:

- current ledger implementation
- current MTN implementation
- current risk implementation

Only after that add:

- `Fineract*Adapter`
- `PaymentHubEe*Adapter`

### Phase E: One-flow strangler migration

Migrate exactly one end-to-end financial flow first.

Best candidates:

1. wallet top-up
2. cash-out
3. wallet-to-wallet transfer

Worst first candidate:

- minor-account financial lifecycle

Minor accounts are too policy-heavy to use as the first migration target.

### Phase F: Reconciliation and dual-write/dual-read decision

Before declaring Fineract system-of-record for any flow, prove:

- idempotent replay behavior
- reference traceability
- reversal behavior
- reconciliation reports
- operational support workflow
- failure/timeout compensation

## Recommended changes to the draft

1. Replace "MaphaPay must not build its own custom banking ledger" with:
   "MaphaPay already contains an incumbent financial core; the program goal is to evaluate and, where justified, migrate core financial truth to Fineract in bounded phases."

2. Replace "Payment Hub EE owns local Eswatini connectors" with:
   "Payment Hub EE is a candidate orchestration platform; each local rail connector remains an explicit implementation workstream until proven available and production-suitable."

3. Replace repo-creation Phase 1 with interface-boundary Phase 1.

4. Add a dedicated migration-inventory phase before any Fineract rewiring.

5. Add explicit go/no-go gates for:
   - Fineract product fit
   - Payment Hub EE connector effort
   - operational support burden
   - reference reconciliation model

6. Keep existing backend financial stabilization work allowed until parity exists.

## Bottom line

The draft is usable as a target-state narrative.

It is not yet usable as an execution plan.

The corrected execution plan must start from one hard truth:

`maphapay-backoffice` is already a financial platform, not just a thin API facade.

That means the real job is not "wire up Fineract and Payment Hub."

The real job is "design a migration from an existing event-sourced financial platform to a new core/orchestration stack without breaking product contracts, auditability, or money movement integrity."

## External references used

- Apache Fineract docs: [https://fineract.apache.org/docs/current/](https://fineract.apache.org/docs/current/)
- Apache Fineract repo: [https://github.com/apache/fineract](https://github.com/apache/fineract)
- Mifos X overview: [https://mifos.org/mifos-x/](https://mifos.org/mifos-x/)
- Payment Hub EE business overview: [https://mifos.gitbook.io/docs/payment-hub-ee/business-overview](https://mifos.gitbook.io/docs/payment-hub-ee/business-overview)
- Payment Hub EE technical overview: [https://mifos.gitbook.io/docs/payment-hub-ee/overview](https://mifos.gitbook.io/docs/payment-hub-ee/overview)
- Payment Hub EE repo entrypoint: [https://github.com/openMF/ph-ee-start-here](https://github.com/openMF/ph-ee-start-here)
