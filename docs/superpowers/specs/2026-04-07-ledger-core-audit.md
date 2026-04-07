# Ledger Core Audit

Date: 2026-04-07

## Summary

This section validates the `Core banking and ledger audit`, `API/webhook/idempotency audit`, and the ledger-adjacent parts of the source master audit against the actual codebase.

Main conclusion:

- MaphaPay/FinAegis already has real money-movement controls, event-sourced aggregates, reconciliation services, and operator inspection tooling.
- It does **not** yet present a clearly explicit, GL-grade accounting layer with formal posting rules, balancing guarantees, effective-dated accounting policy, and reconciliation anchored to authoritative journal records.
- The right recommendation is not “build a ledger from scratch.” The right recommendation is “formalize a governed posting layer over the current event-sourced money-movement base.”

This section is intentionally scoped to internal money movement only:

- send money
- request-money acceptance

Request-money creation remains workflow-only in this slice and does not receive a financial posting.

## Evidence Reviewed

Primary backend evidence:

- [`README.md`](/Users/Lihle/Development/Coding/maphapay-backoffice/README.md)
- [`app/Http/Middleware/IdempotencyMiddleware.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Http/Middleware/IdempotencyMiddleware.php)
- [`app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php)
- [`app/Domain/AuthorizedTransaction/Services/InternalP2pTransferService.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/AuthorizedTransaction/Services/InternalP2pTransferService.php)
- [`app/Domain/Monitoring/Services/MoneyMovementTransactionInspector.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Monitoring/Services/MoneyMovementTransactionInspector.php)
- [`app/Domain/Account/Aggregates/LedgerAggregate.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Account/Aggregates/LedgerAggregate.php)
- [`app/Domain/Account/Aggregates/TransactionAggregate.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Account/Aggregates/TransactionAggregate.php)
- [`app/Domain/Asset/Aggregates/AssetTransactionAggregate.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Asset/Aggregates/AssetTransactionAggregate.php)
- [`app/Domain/Account/Projectors/AccountProjector.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Account/Projectors/AccountProjector.php)
- [`app/Domain/Account/Projectors/TransactionProjector.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Account/Projectors/TransactionProjector.php)
- [`app/Domain/Account/Models/AccountBalance.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Account/Models/AccountBalance.php)
- [`app/Domain/Custodian/Services/DailyReconciliationService.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Custodian/Services/DailyReconciliationService.php)
- [`app/Filament/Admin/Pages/MoneyMovementInspector.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/MoneyMovementInspector.php)

Supporting mobile evidence:

- [`README.md`](/Users/Lihle/Development/Coding/maphapayrn/README.md)
- [`src/features/wallet/hooks/useMtnMomoTransfer.ts`](/Users/Lihle/Development/Coding/maphapayrn/src/features/wallet/hooks/useMtnMomoTransfer.ts)
- [`src/core/storage/secureStorage.ts`](/Users/Lihle/Development/Coding/maphapayrn/src/core/storage/secureStorage.ts)

## Claim Validation

### 1. “Ledger absolutism is not yet proven.”

Verdict: `Confirmed`

What the code shows:

- The current model is event-sourced and aggregate-driven.
- Financially relevant changes are represented through debit/credit-style events and read-model projections.
- `AccountBalance` is a mutable projection updated by projectors.
- `TransactionProjection` provides user-facing transaction views.
- The current `LedgerAggregate` is account-lifecycle oriented, not an explicit general-ledger posting engine.

What is missing or unproven:

- an authoritative journal/entry model with balancing invariants,
- posting rules clearly separated from business workflow completion,
- and a reconciliation model explicitly anchored to posted accounting records.

Benchmark note:

- This gap is consistent with how core-banking systems such as Apache Fineract explicitly document idempotency, business-date handling, reliable event guarantees, and journal-entry processing as first-class platform concerns rather than emergent behavior from projections.[^fineract-docs]

Conclusion:

The source audit is right to treat this as the central gap.

### 2. “Idempotency and replay strategy must be universal.”

Verdict: `Partial`

What the code shows:

- There is real HTTP idempotency middleware with conflict detection, request fingerprint checks, and replay headers.
- Compatibility money-movement controllers also implement domain-level replay reuse using `AuthorizedTransaction` rows and idempotency keys.
- The mobile app already sends idempotency keys for critical flows and documents terminal success more strictly than before.

What remains open:

- There is not yet a documented guarantee that all financially material mutation paths converge on the same replay semantics.
- The source audit’s broader inbox/outbox recommendation remains directionally valid, but it is inaccurate to present idempotency as absent.

Conclusion:

The correct finding is “strong foundations exist, but the guarantee is not yet universal or formalized.”

### 3. “Settlement and reconciliation architecture must become first-class.”

Verdict: `Partial`

What the code shows:

- There is already daily reconciliation logic in the custodian layer.
- The system checks internal versus external balances, stale syncs, and orphaned balance situations.
- Treasury, settlement, and reconciliation concepts already exist across domains and docs.

What remains open:

- Reconciliation currently reads as balance comparison and sync discipline, not as a fully authoritative ledger-close process.
- The source audit is right that settlement and reconciliation need stronger first-class treatment, but wrong if read as “not present.”

### 4. “Back-office operator visibility for money movement is missing.”

Verdict: `Incorrect`

What the code shows:

- There is already a dedicated Filament page for money movement inspection.
- The inspector correlates authorized transaction state, asset transfer state, transaction projections, money request state, telemetry, and a timeline.

Corrected finding:

- Operator visibility exists and is materially useful.
- The actual gap is that operator tooling is not yet anchored to an explicit posting/journal view, so it cannot yet present workflow state, posting state, and settlement state as clearly separate layers.

### 5. “The platform still reads like an MTN-first system.”

Verdict: `Unproven` for ledger-core scope

The repo does contain MTN-specific compatibility work, but ledger-core validation is not the right section to resolve provider-neutral orchestration maturity. This belongs in the next section on provider orchestration, settlement, and reconciliation.

### 6. “Workflow state and money state must be separated.”

Verdict: `Confirmed`

What the code shows:

- Compatibility initiation flows pass through authorization, verification policy resolution, and finalization.
- Internal P2P execution is centralized and aggregate-backed.
- The inspector already has to reconstruct truth by joining `AuthorizedTransaction`, transfer state, projections, and money-request state.

Interpretation:

That join logic is itself evidence that workflow state and financial state are currently distributed across multiple models instead of being expressed through one explicit posting authority.

## Corrected Findings

### What already exists

- Event-sourced aggregate base for account and asset activity
- Compatibility money-movement idempotency protections
- Aggregate-backed internal P2P execution
- Read-model projections for balances and transaction history
- Reconciliation service in the custodian domain
- Treasury and settlement domain concepts
- Operator-facing money movement inspection

### What is materially missing

- explicit GL-grade posting layer,
- journal-entry balancing invariants,
- authoritative posting state distinct from workflow completion,
- formal reversal classes,
- reconciliation and close processes explicitly anchored to posted accounting records,
- and a canonical money-state vocabulary shared by flows, inspectors, and finance operations.

### What is explicitly deferred from this first slice

- provider-neutral routing and settlement orchestration,
- ledger treatment for non-P2P financial products,
- historical backfill of all prior money movements into new postings,
- and effective-dated accounting policy beyond reserving metadata for future rule versioning.

### What the source audit overstates

- idempotency is not missing,
- reconciliation is not missing,
- treasury is not missing,
- operator visibility is not missing.

Those areas are present but not yet formalized to treasury-grade standards.

## Recommendation

Use the existing event-sourced money-movement base as the migration starting point, but introduce a dedicated posting layer as the new financial authority.

That posting layer should:

- own authoritative financial state,
- define debit/credit balancing,
- classify reversals and adjustments,
- become the anchor for reconciliation and finance reporting,
- and feed current projections and operator tools instead of replacing them immediately.

## External Standards Check

The source audit’s emphasis on stronger replay semantics, separation of workflow and money state, and mobile attestation direction is consistent with current industry guidance. In particular:

- Stripe’s current idempotency guidance reinforces operation-keyed replay protection as a baseline pattern.[^stripe-idempotency]
- Apple and Google both maintain first-party attestation mechanisms that support the audit’s mobile trust recommendations.[^apple-app-attest][^google-play-integrity]
- OWASP MASVS still supports the source audit’s direction that mobile hardening should be explicit, not implicit.[^masvs]
- Apache Fineract’s current docs reinforce that idempotency, business date, reliable event guarantees, and journal-entry operations should be explicitly modeled and not left implicit.[^fineract-docs]

These standards support the direction of the audit, but they do not change the repo-specific conclusion: the immediate architectural gap is the lack of an explicit posting authority over the existing event-sourced balance model.

## Final Verdict

The source audit is strongest where it argues for:

- a stricter financial truth model,
- a formal separation between workflow state and money state,
- and stronger reconciliation discipline.

It is weakest where it describes mature-but-incomplete subsystems as if they do not exist.

The right rewrite for the ledger section is:

“MaphaPay already has serious money-movement controls and event-sourced foundations. The unresolved problem is not absence of controls; it is the absence of a clearly explicit, governed posting architecture that elevates those controls into a treasury-grade ledger core.”

## Footnotes

[^masvs]: OWASP Mobile Application Security project, MASVS and MAS checklists: <https://mas.owasp.org/MASVS/> and <https://mas.owasp.org/checklists/MASVS-CODE/>
[^apple-app-attest]: Apple Developer Documentation, App Attest / DeviceCheck server validation guidance: <https://developer.apple.com/documentation/devicecheck/validating-apps-that-connect-to-your-server>
[^google-play-integrity]: Android Developers, Play Integrity API overview: <https://developer.android.com/google/play/integrity/overview>
[^stripe-idempotency]: Stripe API reference, idempotent requests: <https://docs.stripe.com/api/idempotent_requests>
[^fineract-docs]: Apache Fineract documentation: <https://fineract.apache.org/docs/current/>
