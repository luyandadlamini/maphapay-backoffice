# MaphaPay Mifos / Fineract Adoption Plan

Date: 2026-05-04
Status: validated replacement for `/Users/Lihle/Downloads/mapha_pay_mifos_first_architecture_document-2.md`

## Objective

Adopt Mifos X / Apache Fineract as the target financial core for MaphaPay while preserving:

- current mobile contracts
- current product UX
- money-movement integrity
- auditability
- operational traceability

This plan assumes the target direction is still:

- MaphaPay mobile app
- MaphaPay API / product layer
- risk / compliance layer
- payment orchestration layer
- Mifos X / Apache Fineract as target financial system of record

What changes is the execution model.

This is a migration plan, not a greenfield architecture plan.

## Non-negotiable truths

### 1. We are migrating from an existing financial platform

`maphapay-backoffice` already contains:

- account and asset event sourcing
- ledger posting
- authorized transaction orchestration
- MTN MoMo flows
- transaction history and dashboard contracts
- compliance / monitoring workflows

So the plan must preserve the current system until each migrated flow is proven safe.

### 2. Fineract is the target core, not an assumed drop-in fit

We are choosing Fineract as target direction.

But each required product behavior still needs to be validated against:

- Fineract configuration
- Fineract extension points
- plugin/customization needs
- operational support model

### 3. Payment Hub EE is a candidate orchestration layer, not a free connector bundle

We should plan to use Payment Hub EE where it adds real value.

But Eswatini rail support must be validated as separate work:

- MTN MoMo
- e-Mali
- EPS
- bank rails

## Target architecture

```text
MaphaPay Mobile App
        ↓
MaphaPay API / Product Layer
        ├── Auth / Session / Device / Trust
        ├── Mobile API contracts
        ├── Product state (minor accounts, rewards, social money, notifications)
        ├── Fineract adapters
        ├── Payment orchestration adapters
        └── Risk / compliance adapters
                ↓
Risk / Compliance Layer
                ↓
Payment Orchestration Layer
        ├── Direct provider adapters initially where needed
        └── Payment Hub EE where validated and useful
                ↓
Mifos X / Apache Fineract
        ├── clients
        ├── deposit / wallet accounts
        ├── savings / pocket accounts
        ├── transactions
        ├── charges / fees
        ├── accounting
        └── audit / reporting
```

## Ownership model

### MaphaPay API owns

- auth
- sessions
- device trust
- API contracts
- account-context selection
- product UX state
- social money
- rewards
- minor-account non-financial product logic
- orchestration between subsystems

### Fineract owns after migration of a flow

- financial balances for that flow
- deposits / withdrawals / transfers for that flow
- accounting entries for that flow
- financial reporting for that flow

### Risk layer owns

- KYC / KYB decisions
- sanctions / PEP / screening
- fraud / monitoring decisions
- case / review signals

### Payment orchestration layer owns

- provider workflow state
- callbacks
- retries
- asynchronous rail coordination
- provider reconciliation state

## Guardrails

1. The mobile app never talks directly to Fineract or Payment Hub EE.
2. Existing API contracts are preserved unless a contract migration is explicitly planned.
3. No whole-system ledger cutover.
4. Migrate one bounded money flow at a time.
5. Every migrated flow must have reference mapping across:
   - MaphaPay request/intent ID
   - provider/orchestration transaction ID
   - Fineract transaction ID
6. No retirement of incumbent ledger ownership until reconciliation passes.

## Phase 0: Migration inventory

Goal: define exactly what exists today and what must map into Fineract.

Deliverables:

- account type inventory
- transaction type inventory
- balance source inventory
- mobile contract dependency inventory
- identifier mapping design
- migration risk register

Required mapping tables:

- `maphapay_user_uuid -> fineract_client_external_id`
- `maphapay_account_uuid -> fineract_account_external_id`
- `maphapay_transaction_ref -> orchestration_ref -> fineract_transaction_ref`

Exit criteria:

- every current money-moving route has a documented source of truth
- every current balance surface has a documented source of truth
- every current financial write path is classified

## Phase 1: Fineract fit validation

Goal: prove Fineract can support the MaphaPay model you want.

Validate these explicitly:

### Personal finance

- personal wallet account
- pocket / savings account
- top-up posting
- cash-out posting
- wallet-to-wallet transfer
- transaction history retrieval

### Merchant / organization finance

- merchant wallet
- business balances
- settlement reporting
- team / org ownership mapping

### Minor accounts

- adult/guardian client model
- minor client model
- account ownership / visibility rules
- financial authorization boundaries

### Operational capabilities

- fee/charge configuration
- accounting/journal visibility
- external IDs
- audit/report extraction

Exit criteria:

- pass/fail matrix for each required behavior
- list of config-only items
- list of plugin/customization items
- list of non-fitting items

## Phase 2: Payment orchestration decision

Goal: decide where Payment Hub EE is worth using first.

Workstreams:

### Option A: direct adapter benchmark

Benchmark direct MaphaPay integration for:

- MTN MoMo collection
- MTN MoMo disbursement
- callback/status lifecycle

### Option B: Payment Hub EE benchmark

Benchmark:

- deployment complexity
- connector availability
- connector implementation cost
- callback / audit / operations behavior
- Fineract handoff path

Decision rule:

- if Payment Hub EE materially reduces long-term orchestration cost, use it for the first rail
- if not, keep direct-provider integration first and defer Payment Hub EE until scale justifies it

Exit criteria:

- written decision per rail
- first rail selected
- exact connector/build work identified

## Phase 3: Adapter seams in current backend

Goal: prepare the existing backend for progressive replacement.

Introduce stable interfaces inside `maphapay-backoffice`:

- `FinancialCoreInterface`
- `FinancialAccountStoreInterface`
- `FinancialTransactionStoreInterface`
- `PaymentRailOrchestratorInterface`
- `RiskDecisionInterface`

Back them initially with current implementations.

Then add:

- `FineractFinancialCoreAdapter`
- `FineractAccountAdapter`
- `FineractTransactionAdapter`
- `PaymentHubEeOrchestratorAdapter` if selected

Exit criteria:

- controllers and product services stop depending on concrete incumbent ledger classes directly
- replacement can happen behind adapters

## Phase 4: First migrated flow

Goal: migrate one narrow financial flow end to end.

Recommended order:

1. top-up
2. cash-out
3. wallet-to-wallet transfer

Do not start with:

- minor-account financial lifecycle
- social money settlement flows
- request-money acceptance flows

For the first migrated flow, implement:

- initiation through MaphaPay
- risk decision
- provider/orchestration execution
- Fineract posting
- status polling/callback handling
- dashboard/history projection compatibility

Exit criteria:

- successful end-to-end write
- transaction appears correctly in MaphaPay API
- balance change is reflected correctly
- references are traceable across all systems

## Phase 5: Reconciliation and cutover gate

Before declaring any migrated flow authoritative in Fineract, prove:

- idempotent retry safety
- duplicate-submit safety
- failure compensation
- provider timeout handling
- reversal handling
- reconciliation reporting
- support/operator traceability

Exit criteria:

- runbook for support
- reconciliation report for migrated flow
- explicit cutover approval for that flow

## Phase 6: Progressive flow expansion

Only after one flow is stable, expand in this order:

1. top-up
2. cash-out
3. wallet-to-wallet transfer
4. wallet-to-pocket / pocket-to-wallet
5. request-money execution
6. merchant settlement
7. minor-account financial flows

Each flow repeats:

- fit validation
- adapter implementation
- contract verification
- reconciliation gate

## Phase 7: Incumbent core reduction

Only after multiple flows are live and reconciled in Fineract:

- stop adding new financial truth to incumbent ledger for migrated flows
- convert incumbent ledger roles to compatibility, reporting bridge, or historical archive responsibilities
- retire duplicated logic per migrated flow

What remains in MaphaPay:

- product APIs
- product state
- orchestration
- integration adapters
- non-core product workflows

## Repo strategy

Do not split repos first.

Use current repos first:

- `maphapay-backoffice`
- `maphapayrn`

Possible later split, only after interfaces stabilize:

- `maphapay-api`
- `maphapay-fineract-config`
- `maphapay-payment-connectors`

Do not create extra repos just to look architecturally clean before the migration seams are real.

## First concrete execution checklist

1. create migration inventory doc
2. define identifier mapping strategy
3. stand up local/dev Fineract
4. validate personal wallet + pocket model
5. validate transaction extraction and external-id model
6. benchmark direct MTN orchestration vs Payment Hub EE
7. add backend adapter interfaces
8. migrate one flow only
9. reconcile and review
10. expand incrementally

## Success criteria

This adoption is successful when:

1. mobile contracts still work
2. financial balances for migrated flows are owned by Fineract
3. provider/orchestration state is traceable
4. support can reconcile every transaction
5. incumbent custom financial core shrinks by migrated flow, not by forced rewrite

## Bottom line

You can still use Mifos/Fineract.

The corrected plan is not "don’t use Mifos."

The corrected plan is:

`use Mifos/Fineract through a controlled strangler migration from the existing backend, with explicit fit validation, adapter seams, and reconciliation gates.`
