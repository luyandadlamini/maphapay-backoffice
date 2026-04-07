# Provider Orchestration, Settlement, And Reconciliation Implementation Plan

Date: 2026-04-07

## Summary

Implement a normalized orchestration layer over the existing provider-facing infrastructure without discarding the current connectors, webhook handlers, settlement services, or reconciliation flows.

The plan assumes:

- existing provider-specific integrations remain in place during migration,
- the custodian/bank abstraction stack is the first orchestration backbone,
- and ledger-core posting from section 1 is the financial truth anchor where customer funds are affected.

## Phase 1: Inventory And Canonical Model

- Inventory all current provider-facing subsystems in scope:
  - custodian connectors
  - bank connector adapters
  - MTN compatibility and reconciliation paths
  - webhook verification and processing paths
  - settlement and reconciliation jobs
- Define the canonical orchestration vocabulary:
  - provider family
  - operation type
  - provider processing state
  - normalized finality state
  - settlement state
  - reconciliation state
- Define the minimum provider capability matrix fields.

Done when:

- every in-scope provider path is mapped to the canonical model,
- and the orchestration vocabulary is fixed for this section.

## Phase 2: Introduce Orchestration Records

- Add explicit orchestration models and enums:
  - `ProviderOperation`
  - `ProviderFinalityStatus`
  - `ProviderSettlementStatus`
  - `ProviderReconciliationStatus`
- Add internal/provider reference linkage fields.
- Add nullable `ledger_posting_reference` field as the section-1 integration contract.
- Add `ProviderCapabilityProfile` only as an orchestration-facing normalization wrapper over existing capability sources, not as a second independent capability engine.

Done when:

- externally mediated movements can be represented by one normalized internal record,
- and that record can hold both provider and internal references.

## Phase 3: Callback Inbox And Normalization

- Reuse `CustodianWebhook` as the inbox table for the first slice.
- Extend it to persist raw payload, signature-verification result, provider identity, normalized event type, payload hash where needed, and processing outcome.
- Introduce canonical callback event mapping over current provider-specific webhook handlers.
- Ensure duplicate callback delivery does not create duplicate downstream effects.

Implementation rule:

- if `event_id` exists, dedupe on `(custodian_name, event_id)`
- otherwise dedupe on provider reference plus event type plus payload hash

Done when:

- callbacks are replay-safe,
- raw evidence is preserved,
- and normalized callback semantics exist for in-scope providers.

## Phase 4: Settlement Normalization

- Map current settlement service outputs into normalized settlement states.
- Ensure realtime, batch, and net settlement outcomes can be attached to orchestration records.
- Separate provider-success semantics from settlement-complete semantics.

Done when:

- a movement can be customer-successful while settlement is still pending,
- and settlement state is visible independently.

## Phase 5: Reconciliation Linkage

- Extend reconciliation outputs so discrepancies resolve against:
  - provider reference
  - internal orchestration reference
  - nullable `ledger_posting_reference`
- Preserve current daily reconciliation behavior while enriching its evidence model.
- Add MTN reconciliation outputs into the same normalized evidence vocabulary where feasible.

Done when:

- reconciliation reports stop being subsystem-local only,
- and discrepancy records can point back to normalized orchestration state.

## Phase 6: Operator Surfaces

- Extend admin/operator tooling so in-scope movements can be inspected across:
  - provider references
  - normalized provider state
  - settlement state
  - reconciliation state
  - nullable `ledger_posting_reference`
- Keep existing `ReconciliationReportResource` as the first operator surface, and enrich its report details/actions instead of replacing it immediately.

Done when:

- operators can inspect provider-mediated movement state without manually stitching together multiple subsystem views.

## Test Plan

- Unit tests proving bank-style capability resolution reuses existing `BankCapabilities` / `BankRoutingService` inputs
- Unit tests for callback normalization mapping
- Unit tests for callback dedupe/replay behavior using `(custodian_name, event_id)` and fallback payload-hash strategy
- Feature tests for signed webhook acceptance and invalid-signature rejection
- Feature tests for provider operation creation and `(provider_family, provider_reference)` linkage uniqueness
- Feature tests for settlement-state normalization
- Feature tests for reconciliation records linking provider/internal/nullable-posting references
- Admin/resource tests for enriched `ReconciliationReportResource` visibility and drill-down fields

## Assumptions

- Custodian/bank-style providers are the first orchestration slice.
- MTN is aligned into the normalized vocabulary incrementally.
- Existing provider-specific services remain during migration.
- This section does not yet attempt to unify every provider family in the repository.
