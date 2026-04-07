# Provider Orchestration, Settlement, And Reconciliation Design

Date: 2026-04-07

## Summary

This design turns the existing connector, callback, settlement, and reconciliation pieces into one explicit orchestration model.

The goal is not to rebuild every provider integration. The goal is to unify how the platform represents:

- provider capabilities,
- provider-side lifecycle state,
- callback events,
- settlement state,
- reconciliation evidence,
- and linkage to the ledger-core posting model.

## Current State

The current backend already provides:

- `CustodianRegistry` for connector registration and lookup
- `CustodianWebhook` as persisted inbound custodian callback state
- provider-specific webhook signature verification
- provider-specific webhook payload parsing and domain event emission
- `SettlementService` for realtime, batch, and net settlement
- daily reconciliation logic
- bank abstraction over custodian connectors
- bank capability and routing logic
- MTN collection settlement and MTN reconciliation command paths
- Filament reconciliation reporting

The current backend does **not** yet clearly define:

- one provider-neutral transaction lifecycle,
- one provider capability model used for orchestration decisions,
- one normalized finality model,
- one callback inbox/dedupe model,
- or one operator evidence view joining provider event, internal state, posting state, settlement state, and reconciliation state.

## Target State

The target architecture introduces a provider orchestration layer with these properties:

- provider integrations remain adapter-based,
- provider capabilities are normalized and queryable,
- provider callbacks are normalized into canonical event types,
- provider finality is distinguished from customer-visible success and from settlement completion,
- settlement and reconciliation attach to orchestration records,
- orchestration records link to ledger postings from section 1,
- and operators can inspect one movement across all relevant states.

## Core Decisions

### 1. Orchestration backbone

Use the existing custodian/bank connector stack as the backbone for the first orchestration slice.

Reason:

- it already has registries,
- settlement services,
- reconciliation services,
- callback verification and processing,
- and admin reporting.

MTN should be adapted into the same orchestration vocabulary rather than becoming the control-plane baseline.

### 2. Canonical orchestration record

Introduce an explicit orchestration record for externally mediated movements.

Minimum responsibilities:

- hold internal reference and provider reference(s),
- identify provider and provider family,
- record requested operation type,
- record normalized lifecycle state,
- record normalized finality classification,
- record settlement state,
- link to ledger posting when applicable,
- link to reconciliation evidence when applicable.

For the first slice, this record is introduced for externally mediated movements that are already represented in custodian/bank/MTN flows. It is not required for every provider family in the repository.

### 3. Capability model

Introduce a normalized provider capability model by adapting, not replacing, the existing banking capability/routing concepts in the first slice.

Minimum capability dimensions:

- push payout support
- pull / request-to-pay support
- balance query support
- webhook support
- polling-only fallback
- realtime settlement support
- batch/net settlement support
- reversal/refund support
- account validation support

This capability model becomes the basis for routing, fallback, and operator expectation-setting.

First-slice reuse decision:

- `BankCapabilities` remains the source capability shape for bank-style providers
- `BankRoutingService` remains the source scoring/routing implementation for bank-style providers
- `ProviderCapabilityProfile` is an orchestration-facing normalization layer that wraps existing banking capability data and extends it for MTN/custodian alignment

This avoids introducing a second independent routing engine for the same bank-style providers.

### 4. Callback normalization

Provider webhooks and status callbacks must normalize into canonical internal event types such as:

- payment_submitted
- payment_processing
- payment_succeeded
- payment_failed
- balance_changed
- settlement_completed
- settlement_failed

Provider-specific payloads remain stored for evidence, but orchestration logic must consume canonical meanings.

First-slice inbox decision:

- `CustodianWebhook` is the inbox record for custodian/bank-style providers in this phase
- `ProviderEventInbox` is not introduced as a parallel table for the first slice
- instead, `CustodianWebhook` is extended with normalized-event metadata and replay/dedupe metadata where needed

Current mapping examples for the first slice:

- `payment.completed` -> `payment_succeeded`
- `transfer.completed` -> `payment_succeeded`
- `payment.failed` -> `payment_failed`
- `transfer.rejected` -> `payment_failed`
- `account.balance_changed` and `balance.updated` -> `balance_changed`

This keeps one persisted inbound callback record instead of two.

### 5. Finality separation

This section must track at least:

- provider processing state
- normalized finality state
- settlement state
- reconciliation state

These must remain distinct from workflow state and posting state defined in section 1.

### 6. Ledger linkage

Provider-orchestrated movements that affect customer funds must link to `LedgerPosting` once section 1 is implemented.

Required rule:

- provider callback success is not by itself financial truth,
- provider success may trigger posting, settlement tracking, or reconciliation updates depending on the rail,
- operator tools must show the distinction.

Interim contract before section-1 implementation exists in code:

- `ProviderOperation` stores nullable `ledger_posting_reference`
- until ledger-core is implemented, the authoritative movement reference remains the current subsystem record such as transaction reference, settlement id, or provider reference
- implementation in this section must not block on `LedgerPosting` tables existing
- once ledger-core lands, provider success paths attach `ledger_posting_reference` during the posting step

### 7. Callback ingestion posture

Adopt an inbox-style callback lifecycle for this section’s target state.

Minimum requirements:

- store raw callback
- verify signature
- dedupe by provider event identity when available
- record processing status
- record normalized event type
- preserve replay visibility

This is layered over `CustodianWebhook` for the first slice rather than replacing current webhook persistence wholesale.

## Public Interface And Data Model Changes

### New backend concepts

- `ProviderOperation`
- `ProviderCapabilityProfile`
- `ProviderEventInbox`
- `ProviderFinalityStatus` enum
- `ProviderSettlementStatus` enum
- `ProviderReconciliationStatus` enum

### Existing concepts that remain

- `CustodianRegistry`
- `CustodianWebhook`
- `WebhookVerificationService`
- `WebhookProcessorService`
- `SettlementService`
- `DailyReconciliationService`
- MTN reconciliation command and settler logic
- `BankCapabilities`
- `BankRoutingService`

### Existing concepts whose semantics change

- reconciliation reports should gradually link to orchestration and posting references
- settlement runs should report against normalized orchestration state, not subsystem-local status only
- `CustodianWebhook` should carry normalized callback results, not only raw provider event names
- bank capability/routing logic should be treated as the first provider-capability substrate instead of an isolated banking-only concern

## Flow Design

### Provider-mediated transfer flow

Required flow:

1. Provider is selected using capability-aware orchestration rules.
2. Internal orchestration record is created.
3. Provider request is initiated and internal/provider references are linked.
4. Callback and polling updates normalize provider lifecycle events.
5. When finality criteria are met, financial posting occurs or is confirmed.
6. Settlement state is tracked separately where required.
7. Reconciliation attaches evidence and discrepancy state.

### Callback flow

Required flow:

1. Raw callback is received.
2. Signature is verified.
3. Callback is stored as inbox evidence.
4. Duplicate or replayed callback is detected safely.
5. Provider payload is normalized into canonical orchestration event type.
6. Downstream orchestration/settlement/reconciliation updates run from normalized meaning.

First-slice implementation detail:

- steps 1-4 reuse `CustodianWebhook`
- webhook uniqueness should key on `(custodian_name, event_id)` when `event_id` is present
- when provider payload lacks stable event identity, dedupe falls back to provider reference plus event type plus payload hash

## Failure Modes

The design must explicitly handle:

- provider accepted request but no callback arrived
- callback arrived twice
- callback payload verified but references no known internal movement
- provider reports success while settlement remains pending
- provider reports failure after apparent customer success
- reconciliation mismatch after provider success
- provider integration available for initiation but not for webhook-driven completion

## Testing And Acceptance

The implementation is not complete unless these scenarios are covered:

- provider capability resolution for bank-style providers reuses `BankCapabilities`/`BankRoutingService` data and does not create a separate contradictory score
- one `(provider_family, provider_reference)` resolves to one `ProviderOperation`
- one `(custodian_name, event_id)` callback resolves to one `CustodianWebhook` processing outcome when `event_id` exists
- duplicate callback delivery does not create duplicate downstream effects and reprocessing returns the same orchestration record
- callback normalization preserves raw payload while exposing canonical event type on the stored webhook/inbox record
- provider success can be represented without implying settlement completion
- reconciliation output can resolve provider reference, internal orchestration reference, and nullable `ledger_posting_reference`
- the first operator surface is the existing `ReconciliationReportResource`, enriched with normalized orchestration references rather than a brand-new dashboard in this phase

## Assumptions

- First slice uses custodian/bank-style integrations as the orchestration backbone.
- MTN alignment into the normalized model is included, but not every provider family will be fully migrated in the first implementation pass.
- Existing provider-specific services remain during migration.
- Ledger-core posting from section 1 is the financial truth anchor for this section.

## Footnote

[^fineract-docs]: Apache Fineract documentation: <https://fineract.apache.org/docs/current/>
