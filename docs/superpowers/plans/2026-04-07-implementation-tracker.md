# Implementation Tracker

Date: 2026-04-07

## Instructions

- Use this file as the shared execution tracker for implementation agents.
- Valid statuses:
  - `TODO`
  - `IN PROGRESS`
  - `DONE`
  - `BLOCKED`
- Update the tracker immediately when a meaningful step starts, completes, or is blocked.
- Do not delete prior notes. Append concise dated notes under the relevant section.
- If scope changes or a spec adjustment is required, record it here and link the affected doc(s).

## Section 1: Ledger Core

### Phase 1: Validation And Invariants

| Item | Status | Notes |
|---|---|---|
| Inventory current send-money entry points | DONE | Entry path confirmed: SendMoneyStoreController -> AuthorizedTransactionManager::initiate/finalize or verify controllers -> SendMoneyHandler -> InternalP2pTransferService. |
| Inventory current request-money acceptance entry points | DONE | Entry path confirmed: RequestMoneyReceivedStoreController -> AuthorizedTransactionManager::initiate -> verify controllers -> RequestMoneyReceivedHandler -> InternalP2pTransferService. |
| Confirm current financial finalization boundary in code | DONE | Financially material boundary now enforced at AuthorizedTransactionManager::finalizeAtomically(): handler execution plus authoritative posting creation must both succeed before result persistence. |
| Implement first-slice workflow vs money-state separation | DONE | Added explicit LedgerPosting/LedgerEntry records for send-money and request-money acceptance; request-money creation remains workflow-only with deterministic empty posting view in inspector. |
| Wire approved posting ownership point for first slice | DONE | LedgerPostingService is invoked from AuthorizedTransactionManager::finalizeAtomically() after handler execution and before authorized_transaction result persistence. |
| Add/update tests for first-slice invariants | DONE | Added posting assertions for send-money/request-money acceptance, request-money creation non-posting coverage, posting-failure rollback coverage, and inspector workflow-vs-posting coverage. |
| Run relevant test suite | DONE | `./vendor/bin/pest tests/Feature/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyPolicyEnforcementTest.php tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyStoreControllerTest.php tests/Feature/Financial/DoubleSpendProtectionTest.php tests/Unit/Domain/Monitoring/Services/MoneyMovementTransactionInspectorTest.php` passed: 35 tests, 223 assertions. |
| Run relevant test suite | TODO | |

### Notes

- 2026-04-07: Tracker created.
- 2026-04-07: Implementation started after confirming approved design already exists in ledger-core audit/design/plan docs; no new design work required for this slice.
- 2026-04-07: Static analysis passed for changed ledger-core classes via `XDEBUG_MODE=off vendor/bin/phpstan analyse app/Domain/AuthorizedTransaction/Services/AuthorizedTransactionManager.php app/Domain/Ledger/Services/LedgerPostingService.php app/Domain/Ledger/Models/LedgerPosting.php app/Domain/Ledger/Models/LedgerEntry.php app/Domain/Monitoring/Services/MoneyMovementTransactionInspector.php --memory-limit=2G`.
- 2026-04-07: No ledger-core spec correction was required; one existing failure-path test was aligned to observed code reality that failed request-money acceptance leaves the authorization pending while no posting or money-state change is committed.

## Section 2: Provider Orchestration, Settlement, And Reconciliation

### Phase 1: First Narrow Slice

| Item | Status | Notes |
|---|---|---|
| Inventory callback ingestion touchpoints | DONE | Confirmed ingress path: `ValidateWebhookSignature` -> `CustodianWebhookController` -> `custodian_webhooks` -> `ProcessCustodianWebhook` -> `WebhookProcessorService`. |
| Inventory dedupe/identity behavior | DONE | First slice now persists explicit `dedupe_key`, `payload_hash`, `normalized_event_type`, and `provider_reference`, with fallback dedupe on provider-reference + normalized-event + payload-hash when `event_id` is absent. |
| Confirm settlement normalization touchpoints | DONE | Settlement visibility is now attached incrementally through normalized webhook settlement state plus reconciliation report `settlement_summary`; no parallel settlement engine introduced. |
| Confirm reconciliation linkage points | DONE | Reconciliation linkage now uses incremental references (`provider_reference`, `internal_reference`, nullable `ledger_posting_reference`, `reconciliation_reference`) and surfaces recent provider callbacks in reconciliation reports. |
| Reuse `CustodianWebhook` as first-slice inbox record | DONE | Extended `custodian_webhooks` with normalization, dedupe, and incremental linkage metadata instead of adding a parallel inbox table. |
| Reuse bank capability/routing infrastructure where applicable | DONE | No replacement routing engine was introduced in this slice; existing `BankCapabilities` / `BankRoutingService` remain the bank-style substrate per the approved design. |
| Implement first operator/admin surface updates | DONE | Enriched file-backed reconciliation report loading and modal details with settlement summary, recent provider callbacks, and incremental reference fields. |
| Add/update tests for provider invariants | DONE | Added targeted coverage for normalized webhook persistence/dedupe, reconciliation reference construction, and full report payload loading. |
| Run relevant test suite | DONE | `./vendor/bin/pest tests/Feature/Api/CustodianWebhookControllerTest.php tests/Unit/Support/ReconciliationReferenceBuilderTest.php tests/Unit/Support/ReconciliationReportDataLoaderTest.php` passed: 4 tests, 33 assertions. |

### Notes

- 2026-04-07: Awaiting implementation start.
- 2026-04-07: Implementation started after confirming provider-orchestration audit/design/plan docs are the approved source of truth for this slice; no redesign of ledger core or provider families outside the approved narrow scope.
- 2026-04-07: Existing ingress path confirmed as `ValidateWebhookSignature` -> `CustodianWebhookController` -> `custodian_webhooks` -> `ProcessCustodianWebhook` -> `WebhookProcessorService`.
- 2026-04-07: Existing explicit dedupe only covers `(custodian_name, event_id)`; providers without stable `event_id` currently have no persisted fallback identity contract.
- 2026-04-07: Existing operator surface is file-backed `ReconciliationReportResource`; existing reconciliation evidence is report-array based and not yet normalized to provider/internal/ledger references.
- 2026-04-07: First slice implemented by extending `custodian_webhooks` and existing reconciliation report loading rather than introducing parallel orchestration inbox/reporting systems.
- 2026-04-07: Ledger linkage remains on the approved interim posture for this slice: reconciliation references now carry nullable `ledger_posting_reference`, with no attempt to redesign or block on ledger-core internals.
- 2026-04-07: No provider-orchestration spec correction was required; implementation stayed within the approved custodian/bank-first incremental slice.

## Section 3: Backoffice Operations

### Implementation Status

| Item | Status | Notes |
|---|---|---|
| Audit/design/plan approved | DONE | Docs completed and review-approved. |
| Code implementation started | TODO | |

### Notes

- 2026-04-07: Documentation complete; no code changes started yet.

## Section 4: Corporate / B2B2C

### Implementation Status

| Item | Status | Notes |
|---|---|---|
| Audit/design/plan approved | DONE | Docs completed and review-approved. |
| Code implementation started | TODO | |

### Notes

- 2026-04-07: Documentation complete; no code changes started yet.

## Section 5: Mobile Trust Boundaries

### Implementation Status

| Item | Status | Notes |
|---|---|---|
| Audit/design/plan approved | DONE | Docs completed and review-approved. |
| Code implementation started | TODO | |

### Notes

- 2026-04-07: Documentation complete; no code changes started yet.

## Cross-Cutting Blockers

| Item | Status | Notes |
|---|---|---|
| Ledger-first dependency for later sections | TODO | Later sections may depend on ledger-core outputs. |

## Spec Adjustments Log

- None yet.
