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
| Code implementation started | DONE | 2026-04-07 first execution slice completed for workspace inventory plus governed hardening of `Settings` and `BankOperations`, with `Modules` brought under explicit workspace ownership/access alignment. |
| Workspace ownership inventory for first slice surfaces | DONE | Explicit ownership wired in code: `Settings` and `Modules` -> Platform Administration, `BankOperations` -> Finance. Navigation grouping aligned to `Platform` / `Finance & Reconciliation`. |
| Approval-mode enforcement for first governed actions | DONE | `Settings.exportSettings` and `BankOperations.runManualRecon` now persist `direct_elevated` audit metadata; `Settings.resetToDefaults` and `BankOperations.freezeBankSettlement` now persist `request_approve` records instead of executing directly. |
| Visibility and denied-access enforcement tests | DONE | Added page-boundary coverage proving finance users are denied `Settings` and support users are denied `BankOperations`. |
| Governed-action audit persistence tests | DONE | Added targeted coverage for platform export audit persistence plus finance reconciliation audit persistence, and pending approval-request persistence for settings reset and settlement freeze. |
| Relevant Section 3 test suite run | DONE | `./vendor/bin/pest tests/Feature/Backoffice/BackofficeGovernancePagesTest.php` passed: 6 tests, 22 assertions. Syntax check also passed on changed PHP files via `php -l`. |

### Notes

- 2026-04-07: Documentation complete; no code changes started yet.
- 2026-04-07: First implementation slice narrowed to page-based platform and finance controls because they already have clear page/action boundaries and minimal dependency on unrelated resource refactors.
- 2026-04-07: Initial ownership mapping for this slice: `Settings` -> Platform Administration workspace, `Modules` -> Platform Administration workspace, `BankOperations` -> Finance workspace.
- 2026-04-07: First approval-mode matrix for this slice: `Settings.save` = `direct_elevated`, `Settings.exportSettings` = `direct_elevated`, `Settings.resetToDefaults` = `request_approve` unless breakglass, `Modules.verifyModule` = `direct_elevated`, `Modules.enableModule`/`disableModule` = `request_approve` unless breakglass, `BankOperations.runManualRecon` = `direct_elevated`, `BankOperations.freezeBankSettlement` = `request_approve` unless breakglass.
- 2026-04-07: Implemented shared first-slice governance primitives with explicit workspace assignment (`HasBackofficeWorkspace`), `AdminActionGovernance`, and persisted `admin_action_approval_requests`.
- 2026-04-07: `Settings` is now platform-only, grouped under `Platform`, requires evidence on governed direct actions, audits governed exports, and converts reset-to-defaults into a pending approval request.
- 2026-04-07: `BankOperations` is now finance-only, grouped under `Finance & Reconciliation`, audits manual reconciliation triggers, and converts settlement freezes into pending approval requests.
- 2026-04-07: `Modules` was aligned to Platform Administration ownership and access in this slice, but module enable/disable request wiring was deferred to the next slice; the custom module grid needs dedicated evidence-capture UI to avoid widening the phase-1 execution boundary.

## Section 4: Corporate / B2B2C

### Implementation Status

| Item | Status | Notes |
|---|---|---|
| Audit/design/plan approved | DONE | Docs completed and review-approved. |
| Code implementation started | DONE | 2026-04-07 narrow first slice completed: corporate profile overlay, persisted capability grants, persistent business onboarding case, and merchant-flow canonical persistence wiring. |
| Inventory current section 4 code paths | DONE | Confirmed business context lives on `Team` + `TeamUserRole`; merchant submit persists `merchants`, but approve/suspend still route through `MerchantOnboardingService` in-memory state. First slice boundary set to `CorporateProfile`, persisted capability grants, `BusinessOnboardingCase`, and merchant lifecycle rewiring. |
| Implement corporate profile overlay on business teams | DONE | Added `CorporateProfile` persistence linked 1:1 to business `Team`, plus `Team::resolveCorporateProfile()` and business-owner bootstrap in `CreateNewUser`. |
| Implement persisted capability model and enforcement hook | DONE | Added persisted `corporate_capability_grants`, `CorporateCapability` enum, and `CorporateCapabilityGate`; merchant approval/suspension in business context now requires explicit `compliance_review` capability (owner retains seeded full grants). |
| Implement persistent business onboarding case foundation | DONE | Added durable `BusinessOnboardingCase` plus status-history persistence for merchant/business onboarding lifecycle state, evidence/risk metadata, and actor timestamps. |
| Rewire merchant onboarding off split in-memory state | DONE | `MerchantOnboardingService` now persists submit/review/approve/activate/suspend/reactivate/terminate state to DB-backed merchant + onboarding-case records; GraphQL merchant submission/approval/suspension flows now resolve through canonical persisted state. |
| Add/update section 4 tests | DONE | Added feature coverage for business-team/corporate-profile linkage, persisted capability grants with enforcement, DB-backed merchant onboarding persistence, and merchant approval gating; replaced outdated service tests with DB-backed onboarding lifecycle coverage. |
| Run relevant section 4 test suite | DONE | `./vendor/bin/pest tests/Feature/Security/CorporateProfileAndMerchantOnboardingTest.php tests/Unit/Domain/Commerce/Services/MerchantOnboardingServiceTest.php` passed: 11 tests, 39 assertions. Targeted static analysis also passed on changed section 4 files via `XDEBUG_MODE=off vendor/bin/phpstan analyse ... --memory-limit=2G`. |

### Notes

- 2026-04-07: Documentation complete; no code changes started yet.
- 2026-04-07: Execution started from approved section 4 audit/design/plan docs; no redesign of sections 1 or 2 is planned for this slice.
- 2026-04-07: Current code confirms the approved section 4 correction: `SubmitMerchantApplicationMutation` persists a `Merchant` row, while `ApproveMerchantMutation`/`SuspendMerchantMutation` still depend on `MerchantOnboardingService` in-memory state, so there is no canonical persisted onboarding identity yet.
- 2026-04-07: First slice intentionally excludes payroll, full expense management, batch payouts, and broad admin-surface rewrites; the focus is persistence and control boundaries over existing business-team foundations.
- 2026-04-07: Implemented first-class `CorporateProfile` persistence over business teams without replacing the existing `Team` tenant anchor.
- 2026-04-07: Implemented a persisted capability foundation through `corporate_capability_grants`; business owners are seeded with full corporate capabilities, and merchant review actions now enforce explicit capability grants in business context.
- 2026-04-07: Implemented durable `BusinessOnboardingCase` + status-history persistence and rewired merchant onboarding lifecycle state away from `MerchantOnboardingService` in-memory storage.
- 2026-04-07: No section 4 spec adjustment was required; the slice executed the approved correction to move merchant onboarding toward one canonical persisted onboarding identity and DB-backed lifecycle authority.

## Section 5: Mobile Trust Boundaries

### Implementation Status

| Item | Status | Notes |
|---|---|---|
| Audit/design/plan approved | DONE | Docs completed and review-approved. |
| Code implementation started | DONE | 2026-04-08 first section-5 execution slice completed on branch `feature/mobile-trust-boundaries-phase1` with canonical payment-link authority hardening plus persisted trust-evidence and enforcement hooks for mobile commerce payments. |
| Inventory current section 5 code paths | DONE | Confirmed SSL-pin distribution exists in `MobileController::getSslPins`, attestation verification exists in `BiometricJWTService` but is not bound to risky commerce flows, and `MobileCommerceController` still accepted legacy raw QR payload authority. |
| Implement persisted mobile trust evidence foundation | DONE | Added persisted `mobile_attestation_records` plus `MobileAttestationRecord` model and `HighRiskActionTrustPolicy` service to store per-request action trust evidence, decision, reason, and attestation verification metadata. |
| Implement first server trust-policy enforcement hook | DONE | `MobileCommerceController::processPayment` now evaluates `HighRiskActionTrustPolicy` for canonical token-authorized payments and enforces deny/step-up responses using persisted trust-decision records. |
| Implement canonical QR/deep-link authority hardening slice | DONE | `MobileCommerceController::parseQr` now resolves canonical `https://pay.maphapay.com/r/{token}` links through `PaymentLinkService` and marks legacy query payload parsing as compatibility-only authority. |
| Add/update section 5 tests | DONE | Added unit coverage for canonical payment-link QR resolution plus valid/invalid token enforcement in final commerce payment path. |
| Run relevant section 5 test suite | DONE | `./vendor/bin/pest tests/Unit/Http/Controllers/Api/Commerce/MobileCommerceControllerTest.php tests/Unit/Domain/Mobile/Services/HighRiskActionTrustPolicyTest.php` passed: 14 tests, 54 assertions. |
| Extend trust-policy enforcement to send-money flows | DONE | `SendMoneyStoreController` now evaluates `HighRiskActionTrustPolicy` for `VERIFICATION_NONE` path; trust decision embedded in transaction payload; `assertTrustPolicyAllows` added to `AuthorizedTransactionManager` for OTP/PIN paths. |
| Extend trust-policy enforcement to request-money acceptance flows | DONE | `RequestMoneyReceivedStoreController` evaluates trust at initiation; trust decision embedded in transaction payload; `AuthorizedTransactionManager::verifyOtp/verifyPin/verifyBiometric` now check trust via `assertTrustPolicyAllows`. |
| Add/update section 5 trust enforcement tests | DONE | Added `SendMoneyTrustPolicyTest.php` and `RequestMoneyReceivedTrustPolicyTest.php` with coverage for allow/deny/step_up paths and persisted trust-record assertions across both high-risk compatibility flows. |
| Run relevant section 5 test suite | DONE | `./vendor/bin/pest tests/Unit/Http/Controllers/Api/Commerce/MobileCommerceControllerTest.php tests/Unit/Domain/Mobile/Services/HighRiskActionTrustPolicyTest.php tests/Feature/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyTrustPolicyTest.php tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyReceivedTrustPolicyTest.php` passed on 2026-04-08: 21 tests, 86 assertions. |
| Implement RN device/trust-envelope plumbing for high-risk actions | DONE | `maphapayrn` now resolves a stable secure-stored mobile device id, propagates `X-Device-ID` / `X-Mobile-Platform` globally, attaches trust context to send-money and request-money accept requests, and registers the current device with `/api/mobile/devices` after login success. |
| Run relevant RN trust-envelope test suite | DONE | `node --experimental-strip-types --test src/features/security/mobileTrustContext.test.mjs src/features/security/mobileTrustEnvelope.source.test.mjs` passed: 6 tests. `node --test src/app/(modals)/welcome.source.test.mjs` passed: 1 test. `npx tsc --noEmit --pretty false` now also passes after fixing the unrelated `welcome.tsx` style-key regression. |

### Notes

- 2026-04-07: Documentation complete; no code changes started yet.
- 2026-04-08: Section 5 execution started using approved mobile-trust audit/design/implementation docs as source of truth; first slice remains narrow and focused on persistence + enforcement boundaries.
- 2026-04-08: First section-5 slice intentionally focused on canonical payment-link authority in commerce QR/payment flows; broader mobile attestation persistence and cross-action trust-envelope enforcement remain pending for subsequent slice.
- 2026-04-08: Added first durable trust-evidence persistence (`mobile_attestation_records`) and a server-side trust policy service; commerce final payment authorization now records trust decisions and blocks on policy `deny`/`step_up` outcomes.
- 2026-04-08: Starting second slice - extending trust policy enforcement to send-money and request-money acceptance high-risk flows.
- 2026-04-08: Second slice completed: (1) `SendMoneyStoreController` evaluates trust before `finalize()` for `VERIFICATION_NONE` and embeds decision in transaction; (2) `RequestMoneyReceivedStoreController` evaluates trust at initiation and embeds in payload; (3) `AuthorizedTransactionManager` now has `assertTrustPolicyAllows()` checked in `verifyOtp`/`verifyPin`/`verifyBiometric` before `finalizeAtomically()`; (4) New `SendMoneyTrustPolicyTest.php` covers trust enforcement paths; (5) Static analysis passes on all changed files.
- 2026-04-08: Review reconciliation corrected one stale tracker claim: request-money trust feature coverage is present in the worktree but was not reflected in the test row or notes, so the section-5 test status has been reopened until both compatibility trust suites are executed and recorded together.
- 2026-04-08: Next dependency-safe slice chosen from the approved section-5 plan is RN trust-envelope plumbing, because backend trust policy already accepts `device_id` / `device_type` and native requests currently send neither stable device identity nor platform metadata.
- 2026-04-08: RN trust-envelope slice completed in `maphapayrn` without changing approved section design: added secure-stored device identity resolution, shared trust payload/header helpers, global device/platform propagation in `apiClient`, explicit trust context on send-money/request-money accept hooks, and login-time mobile-device registration.
- 2026-04-08: Verification after reconciliation exposed two real residual issues rather than design drift: (1) `RequestMoneyReceivedTrustPolicyTest` passes, but the send-money legacy pending-transaction trust test still fails with 404 and needs its own follow-up slice; (2) RN `tsc` remains blocked by an unrelated pre-existing `welcome.tsx` style key error, not by the trust-envelope changes.
- 2026-04-08: Section-5 backend verification blocker resolved by fixing `SendMoneyTrustPolicyTest` setup to enable the compat verification routes (`maphapay_migration.enable_verification`). The full section-5 trust suite now passes.
- 2026-04-08: RN type-check is now clean again after adding the missing `heroImageContainer` style referenced by the welcome onboarding modal; this was unrelated to the trust-envelope slice but blocked `tsc` verification.

## Cross-Cutting Blockers

| Item | Status | Notes |
|---|---|---|
| Ledger-first dependency for later sections | TODO | Later sections may depend on ledger-core outputs. |

## Spec Adjustments Log

- None yet.
