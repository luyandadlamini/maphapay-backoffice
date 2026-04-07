# Corporate / B2B2C Domain Model And Controls Design

Date: 2026-04-07

## Summary

This design turns the current team-and-tenant foundations into a governed corporate domain.

The goal is not to replace `Team` or the tenancy layer immediately. The goal is to:

- formalize business context as a first-class corporate workspace,
- standardize company membership and delegated administration,
- unify merchant / KYB / partner onboarding into persistent workflows,
- and attach approvals, treasury controls, spend controls, and batch payout operations to that model.

## Current State

The current platform already provides:

- `Team` as the business-organization context seed
- team-scoped tenancy initialization and switching
- business-team member management
- team-scoped role assignment
- merchant lifecycle states
- multi-signature approval infrastructure
- team-scoped spending-limit and payment queries

The platform does **not** yet clearly define:

- one canonical corporate profile over a team
- one unified business-relationship model across merchant, corporate, and API partner accounts
- one corporate approval-policy model
- or one treasury/spend/payroll model tied to company context

## Target State

The target state introduces a coherent corporate layer with these first-class concepts:

- `CorporateProfile`
- `CorporateMembership`
- `CorporateRoleAssignment`
- `CorporateApprovalPolicy`
- `CorporateTreasuryAccount`
- `CorporateSpendAccount`
- `BusinessRelationship`
- `BusinessOnboardingCase`
- `CorporatePayoutBatch`

This direction matches the operating discipline seen in Fineract, Stripe Connect, Modern Treasury, and SDK.finance:

- business context must be explicit and capability-gated[^fineract-docs][^stripe-connect]
- permissions must be role-plus-capability, not label-only[^modern-treasury-roles]
- enterprise operations need separate finance and operational controls[^sdk-backoffice-manual][^sdk-cashdesks]

## Core Decisions

### 1. Corporate profile overlays `Team`

First-slice decision:

- do not replace `Team` as the context/tenant anchor
- add a first-class corporate profile linked 1:1 to a business team

The profile owns:

- legal/business identity
- KYB state
- operating status
- product capabilities
- contract/pricing references
- default treasury policy

### 2. Membership model becomes capability-aware

Current team roles remain as the seed, but the target model must separate:

- membership
- role label
- granted capabilities
- approval thresholds
- scoped visibility

Important current-state constraint:

- the repo does not yet have a working capability substrate beyond role strings and an unused `permissions` array on `TeamUserRole`
- the first implementation slice must therefore add a persisted capability model and an enforcement layer rather than merely "mapping" existing team roles semantically

Minimum capability dimensions:

- treasury operations
- payout initiation
- payout approval
- member administration
- compliance review
- API/webhook administration
- spend-control administration

### 3. Business relationships use one model

Merchant, corporate, and API-partner relationships should not evolve as disconnected silos.

Introduce one `BusinessRelationship` model that captures:

- relationship type: `merchant`, `corporate`, `api_partner`, `agent`
- linked corporate profile or operating entity
- status and lifecycle stage
- contract/pricing reference
- enabled capabilities
- risk tier

`Merchant` remains as a product-specific operational model in the first slice, but it should point back to the governing business relationship rather than acting as the only business entity.

### 4. Onboarding becomes case-driven and persistent

Replace fragmented business onboarding semantics with `BusinessOnboardingCase`.

The onboarding case must persist:

- applicant entity
- relationship type
- KYB/KYC requirements
- evidence/documents
- review decisions
- risk outcomes
- approval timestamps and actors
- activation prerequisites

First-slice rule:

- GraphQL merchant application/approval flows must eventually resolve through this case model, not through in-memory status state.

### 5. Corporate treasury and spend accounts are distinct

The model must separate:

- company-level treasury authority
- departmental or product-specific spend accounts
- employee or delegated spend allocations

The first slice does **not** require full expense management or payroll execution, but it must define the authoritative account boundaries so later features do not mix company cash, employee spend, and merchant settlement semantics.

### 6. Approval policy is shared across corporate actions

Corporate approvals should not be re-invented per product.

The shared approval policy must cover at least:

- company treasury transfers
- high-value payouts
- member-capability changes
- API key / webhook ownership changes
- spend-control overrides

Existing multi-sig approval infrastructure is a valid implementation input, but it should be treated as one approval execution mechanism rather than the entire corporate-policy model.

### 7. Payroll and mass payouts are batch-native

When payroll or business disbursements are introduced, they must be modeled as batches with:

- batch header
- line items
- beneficiary validation result
- duplicate detection result
- cut-off metadata
- approval state
- execution result
- exception handling state

This is specified now so future payroll work does not bypass the corporate policy model.

## Public Interface And Data Model Changes

### New backend concepts

- `CorporateProfile`
- `CorporateMembership`
- `CorporateCapability`
- `BusinessRelationship`
- `BusinessOnboardingCase`
- `CorporateApprovalPolicy`
- `CorporateTreasuryAccount`
- `CorporateSpendAccount`
- `CorporatePayoutBatch`

### Existing concepts that remain

- `Team`
- `TeamUserRole`
- tenancy middleware and tenant resolver
- `Merchant`
- `MultiSigApprovalRequest`
- team-scoped X402 spending models

### Existing concepts whose semantics change

- `Team` becomes the context anchor, not the full corporate domain model
- `Merchant` becomes a product-facing subtype linked to a governing business relationship
- team roles become migration inputs to a richer capability model rather than the capability model itself
- merchant onboarding mutations become workflow entry points, not the source of truth for business onboarding state

## Flow Design

### Corporate context selection flow

1. user authenticates once
2. user selects personal or business context
3. request/session tenant state resolves from the selected business team
4. corporate capabilities are loaded for the selected context
5. all business reads and writes enforce that context explicitly

### Business onboarding flow

1. applicant creates or extends a business relationship
2. onboarding case is opened with relationship type and capability request
3. KYB/evidence/risk review progresses through persistent states
4. approval decisions are recorded with actor and reason
5. the corporate profile and linked merchant/partner capability are activated only after prerequisites are met

### Corporate approval flow

1. a governed corporate action is requested
2. the action resolves the applicable approval policy from company context, capability, amount, and thresholds
3. approval execution occurs through direct elevated approval, maker-checker, or multi-sig as policy requires
4. approved action executes against the relevant treasury/spend/business object
5. audit metadata is persisted on both the approval record and target object

## Failure Modes

The design must explicitly handle:

- personal and business context leakage
- owner-only team controls silently acting as the entire corporate RBAC model
- merchant activation without persistent KYB evidence
- capability changes without approval or audit trail
- payroll or batch disbursement semantics being introduced ad hoc later
- spend controls being enforced in one module but absent in related corporate flows

## Testing And Acceptance

The implementation is not complete unless these scenarios are covered:

- corporate profile exists as a first-class object over business teams
- tenant/context switching tests prove users cannot cross business contexts they do not belong to
- business onboarding persists review state and evidence independently of transient GraphQL mutation flow
- role and capability tests prove membership labels alone do not grant treasury or payout rights
- approval-policy tests cover at least one treasury action, one membership/capability action, and one API-control action
- merchant/business activation is gated on persistent onboarding status rather than in-memory-only service state
- team-scoped spend controls map to an explicit corporate account boundary

## Assumptions

- first slice preserves the current team-and-tenant architecture
- ledger-core remains the authority for money-state semantics
- provider orchestration remains responsible for external-rail execution
- this section defines the business-control layer that sits above those capabilities

## Footnotes

[^fineract-docs]: Apache Fineract documentation: <https://fineract.apache.org/docs/current/>
[^stripe-connect]: Stripe Connect documentation, onboarding and connected accounts: <https://docs.stripe.com/connect>
[^modern-treasury-roles]: Modern Treasury platform docs, permission sets and roles: <https://docs.moderntreasury.com/platform/docs/standard-options-for-permission-sets-and-roles>
[^sdk-backoffice-manual]: SDK.finance backoffice manual: <https://sdk.finance/backofficemanual/>
[^sdk-cashdesks]: SDK.finance cashdesks article: <https://sdk.finance/knowledge-base/cashdesks/>
