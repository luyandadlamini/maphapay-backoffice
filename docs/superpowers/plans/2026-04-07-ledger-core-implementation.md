# Ledger Core Implementation Plan

Date: 2026-04-07

## Summary

Implement an explicit posting authority over the current event-sourced money-movement system without replacing the existing compatibility flows wholesale.

The plan assumes the current foundations remain in place:

- `AuthorizedTransaction` continues to manage initiation and verification state
- aggregate-backed transfer execution continues to produce domain events
- `AccountBalance` and `TransactionProjection` remain read models
- operator tools such as the Money Movement Inspector are extended rather than rebuilt from scratch

This section applies only to:

- send money
- request-money acceptance

Request-money creation remains workflow-only and must not create a financial posting in this phase.

## Phase 1: Inventory And Invariant Definition

- Enumerate every financially material mutation path currently used by compat money movement:
  - send money
  - request money acceptance
  - reversals
- Define the canonical financial state vocabulary:
  - workflow state
  - posting state
- Mark current source-of-truth points and places where projections are treated as authoritative by accident.
- Add characterization tests for current send-money and request-money flows so later refactors preserve behavior intentionally.
- Reserve explicit fields for future posting-date / value-date semantics so the posting model can grow toward core-banking-grade date discipline without another schema break, following the kind of explicit business-date treatment seen in Apache Fineract.[^fineract-docs]

Done when:

- every in-scope money-movement path is listed,
- current invariants are documented,
- and the test suite can detect unintentional behavioral regressions.

## Phase 2: Introduce Posting Model

- Add explicit accounting models and enums:
  - `LedgerPosting`
  - `LedgerEntry`
  - posting type/status/reason enums
- Define balanced-entry rules for the first slice of internal money movement.
- Add a posting service responsible for:
  - creating authoritative postings,
  - validating balance of entry sets,
  - linking postings to existing references such as `trx` and transfer `reference`.
- Wire posting creation into `AuthorizedTransactionManager::finalizeAtomically()` so handler execution, posting creation, and authorized-transaction result persistence live in one database transaction.
- Keep write-path integration internal first; do not expose public API changes yet unless needed.

Done when:

- internal movements can be represented as balanced posting sets,
- and posting creation can fail independently from workflow state.

## Phase 3: Integrate Send Money And Request Money

- Update the canonical internal transfer path so financially final success requires successful posting creation.
- Preserve current initiation, verification, and replay handling.
- Ensure `AuthorizedTransaction` finalization links to posting references.
- Ensure transfer references remain stable so existing clients and operators can still resolve movements.
- Keep request-money creation out of this path; only acceptance gets a posting.

Done when:

- send money and request-money acceptance produce one authoritative posting each,
- and replayed requests do not create duplicate postings.

## Phase 4: Re-anchor Projections

- Update balance and transaction projection logic so account-facing projections derive from posted state.
- Preserve existing output shape where possible.
- Document explicitly that `AccountBalance` and `TransactionProjection` are projections, not source-of-truth records.
- Add projector health checks for posting-to-projection lag.
- Use forward-only cutover semantics: postings are required for new traffic after release, while pre-cutover movements may legitimately have no posting row.

Done when:

- posted financial truth can exist even if projections lag,
- and new-cutover projections can be rebuilt from posting-linked events deterministically.

## Phase 5: Reversal And Adjustment Model

- Add linked reversal/adjustment posting support.
- Define allowed transition rules for:
  - cancellation before posting
  - compensating reversal
  - reconciliation adjustment
  - operator manual adjustment
- Extend audit metadata requirements for operator-initiated adjustments.

Done when:

- reversals no longer behave as generic inverse mutations only,
- and each reversal/adjustment class is distinguishable in finance and ops tooling.

## Phase 6: Reconciliation Integration

- Extend reconciliation services so discrepancies can be tied to posting references when a posted movement exists.
- Keep current internal-versus-external balance checks, but add posting-aware discrepancy metadata.
- Define the first version of reconciliation-close outputs for in-scope internal P2P flows.

Done when:

- reconciliation can identify the authoritative posting tied to a mismatch,
- and discrepancy reports stop depending only on balance snapshots.

## Phase 7: Operator Tooling

- Extend the Money Movement Inspector to show:
  - authorization/workflow state
  - posting state
  - projection state
  - reconciliation warnings when available
- Add warnings for:
  - posted-without-projection,
  - projection-without-posting,
  - reversed or adjusted movements,
  - reconciliation exceptions,
  - legacy pre-cutover movement without posting data.

Done when:

- an operator can diagnose a money movement without inferring financial truth from one model alone.

## Test Plan

- Unit tests that entry sums net to zero per asset for each posting
- Unit tests that one `trx` and one idempotency replay path produce one posting reference only
- Feature tests for send-money finalization with posting creation and posting reference persistence
- Feature tests for request-money acceptance with posting creation and posting reference persistence
- Feature tests proving request-money creation does not create a posting
- Failure tests where workflow execution succeeds but posting creation fails and the outer transaction is not treated as a completed financial success
- Reversal tests for each supported reversal class
- Reconciliation tests that tie discrepancies to posting references when the movement is post-cutover
- Inspector tests covering:
  - post-cutover movement with posting + projection
  - projection lag after posting
  - legacy pre-cutover movement with no posting row

## Assumptions

- First slice targets internal money movement before broader provider orchestration.
- Existing compatibility response shapes should remain stable unless a safety-critical fix requires adjustment.
- Legacy event streams and projections are preserved during migration.
- Historical backfill into postings is explicitly out of scope for this first slice.
- No destructive rewrite of current aggregates is part of this section.

## Footnote

[^fineract-docs]: Apache Fineract documentation: <https://fineract.apache.org/docs/current/>
