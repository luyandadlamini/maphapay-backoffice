# Provider Orchestration, Settlement, And Reconciliation Audit

Date: 2026-04-07

## Summary

This section validates the source audit’s claims about provider orchestration, callback handling, settlement, and reconciliation against the actual codebase.

Main conclusion:

- The codebase already has meaningful provider-facing foundations: connector registries, webhook verification and processing, settlement services, daily reconciliation, MTN reconciliation work, and admin reporting.
- It does **not** yet show one explicit provider-neutral orchestration core with normalized capability modeling, normalized finality semantics, callback inbox discipline, and consistent linkage into the ledger-core posting model.
- The correct recommendation is not “provider orchestration is missing.” The correct recommendation is “promote existing connector/settlement/reconciliation pieces into one explicit orchestration model.”

## Evidence Reviewed

Primary backend evidence:

- [`app/Domain/Custodian/Services/CustodianRegistry.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Custodian/Services/CustodianRegistry.php)
- [`app/Domain/Custodian/Services/WebhookVerificationService.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Custodian/Services/WebhookVerificationService.php)
- [`app/Domain/Custodian/Services/WebhookProcessorService.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Custodian/Services/WebhookProcessorService.php)
- [`app/Domain/Custodian/Services/SettlementService.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Custodian/Services/SettlementService.php)
- [`app/Domain/Custodian/Services/DailyReconciliationService.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Custodian/Services/DailyReconciliationService.php)
- [`app/Domain/Banking/Connectors/BankConnectorAdapter.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Banking/Connectors/BankConnectorAdapter.php)
- [`app/Domain/MtnMomo/Services/MtnMomoCollectionSettler.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/MtnMomo/Services/MtnMomoCollectionSettler.php)
- [`app/Console/Commands/ReconcileMtnMomoTransactions.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Console/Commands/ReconcileMtnMomoTransactions.php)
- [`app/Http/Middleware/ValidateWebhookSignature.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Http/Middleware/ValidateWebhookSignature.php)
- [`app/Filament/Admin/Resources/ReconciliationReportResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/ReconciliationReportResource.php)

Supporting tests and routes:

- [`tests/Feature/Custodian/SettlementServiceTest.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/tests/Feature/Custodian/SettlementServiceTest.php)
- [`tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsTest.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsTest.php)
- [`tests/Feature/Middleware/ValidateWebhookSignatureTest.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/tests/Feature/Middleware/ValidateWebhookSignatureTest.php)
- [`routes/api.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/routes/api.php)
- [`routes/api-compat.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/routes/api-compat.php)

## Claim Validation

### 1. “Provider orchestration is conceptually defined but not fully hardened.”

Verdict: `Confirmed`

What the code shows:

- There are multiple connector and adapter abstractions across custodians, banking, ramp, exchange, and machine-pay rails.
- `CustodianRegistry` and `BankConnectorAdapter` provide reusable integration primitives.
- MTN, webhook, and settlement flows already exist in code, not just in documentation.

What remains missing:

- a single orchestration core that normalizes provider lifecycle, capability, finality, and evidence across these families.

Conclusion:

The source audit is correct on hardening needs, but not if read as “the architecture is still only conceptual.”

### 2. “The platform still reads like an MTN-first system.”

Verdict: `Partial`

What the code shows:

- MTN compatibility routes and reconciliation work are prominent in the mobile-focused paths.
- At the same time, the broader backend already contains multi-provider abstractions well beyond MTN.

Corrected finding:

- The mobile compatibility surface is MTN-heavy.
- The backend foundation is not MTN-only.
- The real issue is that the platform does not yet consolidate these provider abstractions behind one explicit orchestration vocabulary.

### 3. “Settlement and reconciliation must become first-class.”

Verdict: `Partial`

What the code shows:

- `SettlementService` already models realtime, batch, and net settlement.
- `DailyReconciliationService` already compares internal and external balances and reports discrepancies.
- Reconciliation reports already exist in Filament.

What remains open:

- settlement and reconciliation do not yet appear tied to one provider-neutral orchestration state model,
- and they are not yet linked cleanly to the ledger-core posting authority defined in section 1.

### 4. “Provider callback handling needs stronger replay and normalization controls.”

Verdict: `Confirmed`

What the code shows:

- signature verification exists,
- provider-specific webhook parsing exists,
- callback processing emits domain events in transactions.

What remains missing:

- one inbox-like callback ingestion model with dedupe, ordering, replay visibility, and normalized provider-event semantics across providers.

This is one of the strongest claims in the source audit.

### 5. “Capability matrix and routing engine are not first-class.”

Verdict: `Confirmed`

What the code shows:

- there are capability and routing concepts in the banking domain already,
- but no single provider capability matrix governs orchestration decisions across custodian, bank, MTN, ramp, or machine-pay flows.

Corrected finding:

- there are reusable connector abstractions and bank-level capability/routing logic,
- but there is not yet one explicit routing/control-plane model.

### 6. “Operational visibility into reconciliation and provider state is missing.”

Verdict: `Incorrect`

What the code shows:

- reconciliation reporting exists in Filament,
- webhook infrastructure exists,
- settlement and reconciliation commands exist,
- and multiple tests confirm these are active subsystems.

Corrected finding:

- operator visibility exists,
- but it is fragmented by subsystem and not yet unified into one orchestration workbench.

## Corrected Findings

### What already exists

- provider/custodian registry patterns
- bank abstraction over custodian connectors
- provider-specific webhook verification
- provider-specific webhook processing
- settlement modes: realtime, batch, net
- daily reconciliation execution
- MTN reconciliation and settlement-related logic
- admin reconciliation reporting

### What is materially missing

- a unified provider orchestration domain model,
- normalized capability metadata used for routing and fallback,
- normalized provider finality semantics,
- inbox-style callback lifecycle with dedupe and replay visibility,
- orchestration state linked to ledger postings and reconciliation evidence,
- and a unified operator surface for provider event, settlement, and reconciliation state.

### What the source audit overstates

- provider integration foundations are not absent,
- settlement is not absent,
- reconciliation is not absent,
- callback security is not absent.

Those systems exist, but they are not yet unified into one explicit orchestration control plane.

## Recommendation

Promote the existing custodian/bank/provider infrastructure into an explicit orchestration layer built around:

- provider identity,
- provider capabilities,
- provider event normalization,
- provider finality classification,
- settlement lifecycle,
- reconciliation linkage,
- and ledger-posting linkage.

The best backbone for this section is the existing custodian/bank abstraction stack, not the MTN compatibility controllers.

## External Standards Check

The source audit’s emphasis on explicit settlement discipline, reliable event handling, and separation between provider callback state and financial truth is consistent with current platform guidance. Apache Fineract is useful here as a benchmark because it treats reliable event handling, idempotency, and business-date-sensitive operations as explicit architectural concerns, not accidental byproducts.[^fineract-docs]

## Final Verdict

The right rewrite for this section is:

“MaphaPay already has real provider-facing primitives. The unresolved problem is not lack of integrations; it is the lack of a single orchestration layer that normalizes provider capability, finality, callback handling, settlement state, and reconciliation evidence across those integrations.”

## Footnote

[^fineract-docs]: Apache Fineract documentation: <https://fineract.apache.org/docs/current/>
