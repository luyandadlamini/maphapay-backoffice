# Ledger Core Design

Date: 2026-04-07

## Summary

This design formalizes the first implementation slice identified by the validated audit: converting the current event-sourced money-movement base into a governed ledger core with an explicit posting authority.

The design is intentionally incremental. It preserves current compatibility flows, aggregate-backed transfer execution, projections, and operator tooling where they are useful, while inserting a new accounting authority between business workflow completion and derived balance views.

This first slice is intentionally limited to:

- send money
- request-money acceptance

It does not redesign provider settlement orchestration or treat request creation as a financial movement.

## Current State

The current backend already provides:

- aggregate-backed account and asset money-movement primitives,
- authorization and verification orchestration via `AuthorizedTransaction`,
- replay-aware compatibility APIs,
- transfer execution via `InternalP2pTransferService`,
- projected balances in `account_balances`,
- projected user-facing history in `transaction_projections`,
- and operator inspection via the Money Movement Inspector.

Current financial write path in scope:

1. controller validates and normalizes request,
2. `AuthorizedTransactionManager` creates and finalizes an `AuthorizedTransaction`,
3. the resolved handler executes business logic,
4. `InternalP2pTransferService` persists the internal transfer through `AssetTransferAggregate`,
5. projections and inspector views are derived from the resulting records and events.

The current backend does **not** clearly define:

- a journal/posting record as the single authoritative financial state,
- balancing invariants for every financial mutation,
- a clean separation between workflow completion and financial posting,
- or a finance-grade reversal and adjustment vocabulary.

## Target State

The target architecture introduces a dedicated posting layer with these properties:

- every in-scope financial action resolves to explicit postings,
- postings are the authoritative source for money state,
- balances and transaction projections become derived views from posted financial state,
- workflow status remains useful but is no longer treated as financial truth,
- reconciliation, adjustment, and finance operations anchor to posted records,
- operator tools can show workflow state, posting state, and projection state separately in this phase.

This direction is consistent with Apache Fineract’s current platform posture, which treats idempotency, business date, reliable events, and journal-entry operations as explicit architectural concerns rather than implicit side effects.[^fineract-docs]

## Core Decisions

### 1. Authoritative posting layer

Introduce a new accounting authority for financially material mutations.

Minimum responsibilities:

- create one logical posting record per financial movement,
- create balanced entry records beneath that posting,
- classify posting type and business reason,
- track posting lifecycle independently of workflow lifecycle,
- expose stable references for reconciliation and operator inspection.

This layer is the source of truth for financial state.

The design intent is comparable to the discipline documented by Apache Fineract around journal entries and business-date-sensitive accounting behavior, while remaining adapted to the current Laravel/Event Sourcing codebase rather than copying Fineract’s implementation directly.[^fineract-docs]

### 2. Keep current services and aggregates, but demote their authority

Current Eloquent-backed workflow services and transfer aggregates remain useful for orchestration and event capture. They should continue to drive initiation and execution where stable, but they should no longer be relied upon alone as proof of final financial truth.

Practical rule:

- controllers, `AuthorizedTransactionManager`, and handler classes decide whether a business action should occur,
- posting layer decides what financial truth is recorded,
- projections reflect the posted outcome.

### 3. Separate workflow state from money state

At minimum, the system must track:

- workflow state: initiated, pending, failed, expired, completed
- derived challenge state: `challenge_required`, mapped from a pending authorization plus its verification requirement in the current implementation
- posting state: pending_posting, posted, reversed, adjusted

These must not be collapsed into one status field.

### 4. Reversal taxonomy

The posting layer must support distinct classes:

- cancellation before posting,
- compensating reversal of a posted transaction,
- reconciliation adjustment,
- operator-approved manual adjustment.

Each class must carry:

- reason code,
- initiating actor or system,
- related posting reference,
- audit evidence metadata.

### 5. Reconciliation anchor

Reconciliation and close processes must compare external/provider/custodian reality against authoritative posted records, not against mutable balance projections alone.

The current reconciliation services should be retained but refactored so discrepancies are recorded against posting references where applicable.

Future introduction of posting date and value date semantics should follow the same explicitness that Fineract applies to business date and posting date handling, but full date-policy implementation is deferred from this first slice.[^fineract-docs]

### 6. Operator evidence model

The Money Movement Inspector and related operations surfaces must evolve to show:

- authorization/workflow record,
- posting record and balanced entries,
- projected account-facing transactions,
- settlement/reconciliation state,
- warnings when these layers diverge.

## Public Interface And Data Model Changes

### New backend concepts

Add explicit accounting concepts:

- `LedgerPosting`
- `LedgerEntry`
- `LedgerPostingType` enum
- `LedgerPostingStatus` enum
- `LedgerAdjustmentReason` enum

These are internal financial interfaces, not public API surface.

### Posting ownership decision

`LedgerPosting` creation is owned by `AuthorizedTransactionManager::finalizeAtomically()`.

Required behavior:

1. the handler executes and returns normalized financial outcome data,
2. a posting service creates the authoritative posting and balanced entries inside the same database transaction,
3. the authorized transaction result stores the posting reference,
4. the transaction commits only if both handler execution and posting creation succeed.

For request-money creation, no posting is created because that flow is workflow-only.

### Existing concepts that remain

- `AuthorizedTransaction`
- internal transfer execution service
- `TransactionProjection`
- `AccountBalance`
- Money Movement Inspector

### Existing concepts whose semantics change

- `AccountBalance` becomes explicitly derived and non-authoritative
- `TransactionProjection` becomes an account-facing projection of posted state, not merely of business events
- reconciliation logic should eventually reference posting records where available

## Flow Design

### Send money / request money acceptance

Required flow:

1. Initiation and verification policy are resolved as they are today.
2. `AuthorizedTransactionManager` claims and finalizes the authorization.
3. Handler and transfer service prepare and execute the financial mutation payload.
4. Posting layer writes authoritative posting and balanced entries inside the same transaction as finalization.
5. Derived views update from posted state.
6. Response and operator tools resolve against posting-linked references.

The key change is step 4 becoming mandatory before the system treats the movement as final financial truth.

### Reversal flow

Required flow:

1. Identify original posting.
2. Classify reversal type.
3. Validate whether cancellation, compensating reversal, or adjustment is allowed.
4. Create linked reversal/adjustment postings.
5. Update derived views from posted reversal outcome.
6. Preserve full audit trail.

## Cutover Policy

This slice uses a forward-only cutover for authoritative postings:

- historical money movements are not backfilled into `LedgerPosting` in phase 1,
- new send-money and request-money-acceptance traffic writes postings from the cutover release onward,
- existing `AccountBalance` and `TransactionProjection` rows remain valid legacy projections during migration,
- inspector tooling must tolerate movements that have legacy records but no posting record,
- backfill, if needed, is a later phase with a dedicated verification plan.

This avoids mixing speculative historical reconstruction with the first safety-focused rollout.

## Failure Modes

The design must explicitly handle:

- workflow completed but posting failed,
- posting succeeded but projection lagged,
- replayed initiation after cache loss,
- partial settlement confidence,
- reconciliation mismatch after externally successful movement,
- operator adjustment without sufficient evidence,
- reversal attempted against unposted or already reversed movement.

## Testing And Acceptance

The implementation is not complete unless these scenarios are covered:

- same idempotency key replay for one `trx` yields one posting reference only
- every posting created for this slice has entries whose summed signed amount is zero per asset
- successful send money creates one posting reference linked to the authorization result and stable transfer reference
- successful request-money acceptance creates one posting reference linked to the acceptance authorization result
- request-money creation creates no posting record
- projection lag does not change posted financial truth
- workflow success without posting success is surfaced as a fault, not a silent success
- compensating reversal preserves linked posting history
- inspector response can return workflow plus posting plus projection data, and returns a deterministic empty posting view when movement predates cutover

## Assumptions

- No big-bang rewrite of all money-movement aggregates will be attempted in the first slice.
- Existing compatibility APIs remain stable unless a contract change is required to expose safer state.
- Existing reconciliation and operator tooling will be extended, not discarded.
- The first slice focuses on internal P2P money movement and ledger authority, not full provider-neutral orchestration.
- Effective-dated accounting policy is deferred; this slice only reserves rule-version metadata on postings for future introduction.[^future-policy]

## Footnote

[^future-policy]: Deferred means the first slice does not implement date-versioned posting rules yet; it only avoids blocking that future by keeping posting-type and rule-version metadata explicit.
[^fineract-docs]: Apache Fineract documentation: <https://fineract.apache.org/docs/current/>
