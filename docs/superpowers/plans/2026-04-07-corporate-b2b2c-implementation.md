# Corporate / B2B2C Domain Model And Controls Implementation Plan

Date: 2026-04-07

## Summary

Implement the corporate domain as an overlay on the existing team-and-tenant model.

The plan assumes:

- `Team` remains the current context anchor
- existing merchant, approval, and spend-control models are reused where they are already useful
- and the first slice prioritizes persistence and control boundaries over broad feature expansion

## Phase 1: Domain Inventory And Relationship Map

- inventory all business-context-bearing models and flows:
  - `Team`
  - `TeamUserRole`
  - tenancy middleware/resolver
  - `Merchant`
  - merchant onboarding mutations/services
  - multi-sig approval models
  - X402 spending-limit and payment models
- produce a relationship map showing which current flows represent:
  - company identity
  - company membership
  - merchant relationship
  - approval execution
  - spend control

Done when:

- the current business-domain fragments are mapped explicitly,
- and each one has a stated future role in the corporate model or is marked transitional.

## Phase 2: Corporate Profile Overlay

- add a `CorporateProfile` linked to business teams
- store legal/business identity, KYB state, operating status, and contract/pricing references
- define how personal teams differ from business teams at the data-model level

Done when:

- every business team can resolve a first-class corporate profile,
- and core business identity no longer depends on ad hoc team metadata alone.

## Phase 3: Membership And Capability Model

- introduce a new persisted corporate capability model over current team-role assignments
- add an enforcement layer that actually reads and applies those capabilities in business flows
- define a migration path from current role strings and transitional team-role records into explicit capabilities and approval thresholds
- preserve existing team-member management flows while moving authorization toward capability checks

Done when:

- role labels are no longer the only authorization artifact,
- capability grants are persisted and enforced rather than only documented,
- and at least treasury, payouts, member administration, and API-control capabilities are modeled explicitly.

## Phase 4: Persistent Business Onboarding

- introduce `BusinessOnboardingCase`
- move merchant/business approval state into persistent case records
- attach evidence, review history, risk outcomes, and activation prerequisites
- replace the current demo-style merchant onboarding shim with one canonical persisted onboarding identity and DB-backed lifecycle state
- adapt merchant submission/approval entry points so they create or advance onboarding cases rather than splitting state between an in-memory service and the `merchants` table

Done when:

- merchant/business onboarding is no longer dependent on in-memory-only service state,
- submit/approve/suspend/activate flows operate on one canonical persisted onboarding identity,
- and activation decisions are derived from persistent case status.

## Phase 5: Corporate Approval Policy

- define a shared approval-policy resolver for corporate actions
- connect current approval mechanisms:
  - maker-checker style flows
  - direct elevated approvals
  - multi-sig execution where appropriate
- apply the policy first to:
  - treasury-affecting company actions
  - company membership or capability changes
  - API key / webhook ownership changes

Done when:

- governed corporate actions resolve through one policy model,
- and approval metadata persists consistently across action classes.

## Phase 6: Treasury / Spend Boundary Hardening

- define company treasury account vs spend-account boundaries
- map existing team-scoped spend controls to those boundaries
- identify which later features will use:
  - corporate treasury
  - departmental spend account
  - employee/delegated spend allowance

Done when:

- spend controls are anchored to a documented corporate account model,
- and later card/payroll/expense work no longer needs to invent the account topology.

## Phase 7: Batch Payout Foundation

- define `CorporatePayoutBatch` and line-item lifecycle
- add validation hooks for beneficiary validation, duplicates, cut-off handling, and exceptions
- connect the batch object to the shared approval-policy model

Done when:

- business batch payouts have a defined lifecycle before execution logic expands,
- and payroll/mass-payout work has a governed foundation.

## Test Plan

- tenant/context-isolation tests for business teams and Filament/admin tenant switching
- membership tests for capability-based authorization over current team roles
- onboarding-case persistence tests for merchant/business onboarding
- approval-policy tests covering treasury, membership/capability, and API-control actions
- spend-boundary tests proving team-scoped spending controls resolve to explicit corporate account context
- batch-payout tests for validation, duplicates, cut-off metadata, and approval gating

## Assumptions

- first slice is architecture-hardening, not full payroll or expense-management delivery
- current team/member flows remain operational during migration
- ledger and provider sections remain authoritative for money-state and provider-state behavior
