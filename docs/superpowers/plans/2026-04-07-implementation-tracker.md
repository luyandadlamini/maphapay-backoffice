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
| Implement RN attestation provider abstraction for high-risk actions | DONE | 2026-04-08 narrow Phase 2/3 slice completed in `maphapayrn`: added explicit `mobileAttestation` provider boundary, default unsupported/dev-safe fallback behavior, and shared `resolveHighRiskMobileTrustContext()` wiring for send-money and request-money accept. |
| Add/update RN attestation abstraction tests | DONE | Added `src/features/security/mobileAttestation.test.mjs` to cover unsupported fallback, successful provider collection, and provider failure degradation; source-level trust-attachment assertions now require the high-risk attestation-aware helper. |
| Run relevant RN attestation slice test suite | DONE | `node --experimental-strip-types --test src/features/security/mobileAttestation.test.mjs src/features/security/mobileTrustContext.test.mjs src/features/security/mobileTrustEnvelope.source.test.mjs` passed: 9 tests. `npx tsc --noEmit --pretty false` passed on 2026-04-08. |
| Implement RN concrete attestation provider scaffold for high-risk actions | DONE | 2026-04-08 follow-up Phase 2 slice in `maphapayrn`: `mobileAttestation` now defaults to a gated Expo runtime-posture provider that derives a concrete attestation envelope from available Expo/native metadata when explicitly enabled, while preserving null-attestation semantics by default. |
| Add/update RN concrete attestation provider tests | DONE | Expanded `src/features/security/mobileAttestation.test.mjs` to cover the gated default-provider path, including explicit-enable collection of a runtime envelope and explicit-disable preservation of the unsupported/null fallback. |
| Run relevant RN concrete attestation provider test suite | DONE | `node --experimental-strip-types --test src/features/security/mobileAttestation.test.mjs src/features/security/mobileTrustContext.test.mjs src/features/security/mobileTrustEnvelope.source.test.mjs` passed on 2026-04-08: 11 tests. `npx tsc --noEmit --pretty false` also passed on 2026-04-08. |
| Implement RN optional native attestation provider bridge | DONE | 2026-04-08 narrow Phase 2 slice in `maphapayrn`: `mobileAttestation` now attempts an optional `@expo/app-integrity` native bridge on Android when the existing gate is enabled and `expo.extra.mobileAttestation.androidCloudProjectNumber` is configured; otherwise it safely falls back to the runtime-posture envelope. iOS remains on the fallback path in this slice because server-backed App Attest challenge/key lifecycle endpoints are not yet present. |
| Add/update RN optional native attestation bridge tests | DONE | Expanded `src/features/security/mobileAttestation.test.mjs` to cover successful Android Play Integrity collection through an injected optional module bridge and safe fallback to the runtime-posture provider when the native module is unavailable. |
| Run relevant RN optional native attestation bridge test suite | DONE | `node --experimental-strip-types --test src/features/security/mobileAttestation.test.mjs src/features/security/mobileTrustContext.test.mjs src/features/security/mobileTrustEnvelope.source.test.mjs` passed on 2026-04-08: 13 tests. `npx tsc --noEmit --pretty false` also passed on 2026-04-08. |
| Implement RN attestation capability reporting and guardrails | DONE | 2026-04-08 narrow Phase 2/6 follow-up in `maphapayrn`: `collectMobileAttestation()` now returns explicit capability metadata and guarded fallback reasons for disabled config, missing Android cloud project config, missing native module, provider error, and the current iOS backend-prerequisite gap, without changing default rollout semantics or treating iOS as real attestation. |
| Add/update RN attestation capability reporting tests | DONE | Expanded `src/features/security/mobileAttestation.test.mjs` to assert capability metadata for gated-off fallback, custom provider success, provider failure, Android native success, iOS backend-prerequisite fallback, Android native-module fallback, and Android missing-config fallback. |
| Run relevant RN attestation capability test suite | DONE | `node --experimental-strip-types --test src/features/security/mobileAttestation.test.mjs src/features/security/mobileTrustContext.test.mjs src/features/security/mobileTrustEnvelope.source.test.mjs` passed on 2026-04-08: 14 tests. `npx tsc --noEmit --pretty false` also passed on 2026-04-08. |
| Implement backend App Attest prerequisite foundation | DONE | 2026-04-08 narrow backend slice completed: added authenticated App Attest challenge/enroll endpoints under `api/mobile/auth/attestation/app-attest`, canonical `mobile_app_attest_keys` + `mobile_app_attest_challenges` persistence tied to `mobile_devices`, and `AppAttestService` / `AppAttestVerifierInterface` lifecycle primitives without enabling attestation by default or widening trust policy semantics. |
| Add/update backend App Attest prerequisite tests | DONE | Added `MobileAppAttestControllerTest` and `AppAttestServiceTest` covering challenge issuance, key enrollment persistence, replay rejection, and assertion-prerequisite validation against active-key/challenge lifecycle state. |
| Run relevant backend App Attest prerequisite test suite | DONE | `./vendor/bin/phpunit tests/Feature/Api/MobileAppAttestControllerTest.php tests/Unit/Domain/Mobile/Services/AppAttestServiceTest.php` passed on 2026-04-08: 5 tests, 27 assertions. Targeted static analysis also passed via `XDEBUG_MODE=off vendor/bin/phpstan analyse app/Http/Controllers/Api/MobileController.php app/Domain/Mobile/Services/AppAttestService.php app/Domain/Mobile/Services/AppAttestVerifier.php app/Domain/Mobile/Models/MobileAppAttestKey.php app/Domain/Mobile/Models/MobileAppAttestChallenge.php app/Domain/Mobile/DataObjects/AppAttestVerificationResult.php app/Domain/Mobile/DataObjects/AppAttestIssuedChallenge.php app/Domain/Mobile/Exceptions/AppAttestException.php app/Domain/Mobile/Contracts/AppAttestVerifierInterface.php app/Providers/AppServiceProvider.php --memory-limit=2G`. |
| Implement backend App Attest assertion lifecycle | DONE | 2026-04-08 completed a narrow backend follow-up: authenticated iOS clients can now submit App Attest assertions against enrolled keys via canonical `/api/mobile/auth/attestation/app-attest/verify`, with challenge consumption and `last_assertion_at` persistence handled in `AppAttestService` without enabling attestation by default or widening trust-policy semantics. |
| Add/update backend App Attest assertion lifecycle tests | DONE | Expanded `MobileAppAttestControllerTest` and `AppAttestServiceTest` to cover successful assertion verification, challenge consumption, and key `last_assertion_at` updates for enrolled iOS App Attest keys. |
| Run relevant backend App Attest assertion lifecycle test suite | DONE | `./vendor/bin/phpunit tests/Feature/Api/MobileAppAttestControllerTest.php tests/Unit/Domain/Mobile/Services/AppAttestServiceTest.php` passed on 2026-04-08: 7 tests, 41 assertions. Targeted static analysis also passed via `XDEBUG_MODE=off vendor/bin/phpstan analyse app/Http/Controllers/Api/MobileController.php app/Domain/Mobile/Services/AppAttestService.php app/Domain/Mobile/Routes/api.php tests/Feature/Api/MobileAppAttestControllerTest.php tests/Unit/Domain/Mobile/Services/AppAttestServiceTest.php --memory-limit=2G`. |
| Implement RN backend App Attest client seam and capability reconciliation | DONE | 2026-04-08 narrow RN follow-up slice completed in `maphapayrn`: added explicit challenge/enroll/verify API wrappers for `/api/mobile/auth/attestation/app-attest/*` behind the existing security boundary and corrected stale iOS runtime-posture capability semantics now that backend lifecycle endpoints exist, without enabling attestation by default or treating runtime-posture fallback as real attestation. |
| Add/update RN backend App Attest seam tests | DONE | Added `src/features/security/mobileAppAttestApi.test.mjs` for canonical challenge/enroll/verify request shapes and response mapping, and updated `src/features/security/mobileAttestation.test.mjs` to assert the narrowed iOS fallback reason. |
| Run relevant RN backend App Attest seam test suite | DONE | `node --experimental-strip-types --test src/features/security/mobileAppAttestApi.test.mjs src/features/security/mobileAttestation.test.mjs src/features/security/mobileTrustContext.test.mjs src/features/security/mobileTrustEnvelope.source.test.mjs` passed on 2026-04-08: 17 tests. `npx tsc --noEmit --pretty false` also passed on 2026-04-08. |
| Implement RN App Attest provider orchestration seam | DONE | 2026-04-08 narrow RN follow-up slice completed in `maphapayrn`: added an opt-in provider orchestrator that composes device resolution, backend challenge/enroll/verify endpoints, and an injected native App Attest client behind the existing `mobileAttestation` boundary, without enabling rollout by default or wiring high-risk flows yet. |
| Add/update RN App Attest provider orchestration tests | DONE | Added `src/features/security/mobileAppAttestProvider.test.mjs` covering first-run enrollment plus assertion verification and existing-key assertion verification through the backend-owned lifecycle. |
| Run relevant RN App Attest provider orchestration test suite | DONE | `node --experimental-strip-types --test src/features/security/mobileAppAttestProvider.test.mjs src/features/security/mobileAppAttestApi.test.mjs src/features/security/mobileAttestation.test.mjs src/features/security/mobileTrustContext.test.mjs src/features/security/mobileTrustEnvelope.source.test.mjs` passed on 2026-04-08: 19 tests. `npx tsc --noEmit --pretty false` also passed on 2026-04-08. |

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
- 2026-04-08: Tracker/code reconciliation is still accurate for completed slices. Next dependency-safe slice selected from the approved Section 5 plan is RN attestation abstraction and request attachment, because backend trust evaluation already accepts attestation signals while the RN client still lacks an explicit provider boundary and fallback-controlled collection path.
- 2026-04-08: RN attestation abstraction slice completed without spec changes. The new client boundary is intentionally provider-agnostic and ships with safe unsupported/dev fallback so high-risk flows can start routing through one seam now, while native App Attest / Play Integrity collection can be added behind that seam in a later slice.
- 2026-04-08: Reconciled tracker against both repos before starting the next slice. Current entries remain accurate: backend trust policy and RN attestation seam are present, and no existing native App Attest / Play Integrity integration is available in `maphapayrn`.
- 2026-04-08: Next dependency-safe slice is a gated RN concrete attestation provider scaffold, not broad attestation rollout. Backend `HighRiskActionTrustPolicy` currently treats any non-empty attestation as sufficient to avoid degraded posture when server enforcement is disabled, so the RN provider must stay explicitly opt-in and preserve null-attestation by default.
- 2026-04-08: Completed the gated RN concrete attestation provider scaffold without spec changes. `maphapayrn` now exposes an Expo-backed runtime-posture provider behind `expo.extra.mobileAttestation.enabled`, ships with the gate explicitly `false` in `app.json`, and keeps unsupported/dev fallback behavior intact until real native attestation rollout is intentionally enabled.
- 2026-04-08: Reconciled tracker against both repos before starting the next slice. Current completed entries still match code. There is still no installed native attestation module in `maphapayrn`, but Expo SDK 55 now has an official `@expo/app-integrity` alpha path for Android Play Integrity and iOS App Attest, so the next dependency-safe slice is to bridge that optional module behind the existing RN provider seam without enabling it by default or changing backend enforcement posture.
- 2026-04-08: Completed the next dependency-safe RN attestation slice without widening trust semantics. `mobileAttestation` now supports an optional Android `@expo/app-integrity` bridge when the module is installed and `expo.extra.mobileAttestation.androidCloudProjectNumber` is provided, returning a structured `expo-app-integrity:` envelope with the Play Integrity token and request hash. When the module/config is absent, the gate is off, the device is unsupported, or the app is in dev mode, the provider still falls back to the prior runtime-posture or null-attestation behavior. iOS native App Attest remains deferred until the backend can issue and verify the required challenge/key lifecycle.
- 2026-04-08: Reconciled tracker against the current backoffice and RN code before selecting the next slice. The tracker remains accurate for completed work. Backend still has only baseline Apple/Google verifier scaffolding and no App Attest enrollment/assertion challenge endpoints or persistence, so the next dependency-safe slice is explicit RN capability reporting and guardrails around the optional bridge rather than real iOS attestation rollout.
- 2026-04-08: Completed the RN capability-reporting follow-up without changing trust rollout semantics. The attestation seam now returns explicit capability metadata alongside the existing envelope so callers and future diagnostics can distinguish `disabled_by_config`, `native_module_unavailable`, `android_cloud_project_number_missing`, `provider_error`, and `ios_app_attest_backend_prerequisites_missing` instead of treating all fallback paths as equivalent. iOS still remains runtime-posture only until backend App Attest lifecycle support exists.
- 2026-04-08: Reconciled tracker against the live backoffice and RN code before choosing the next slice. Completed rows remain accurate. Backend still lacks App Attest lifecycle primitives: there is no persisted App Attest key record, no App Attest challenge endpoint, and `BiometricJWTService::verifyDeviceAttestation()` still calls the Apple verifier with an empty challenge, so the next dependency-safe slice is a backend prerequisite foundation rather than client rollout.
- 2026-04-08: Current backend App Attest slice is intentionally narrow. Goal: introduce canonical challenge/key lifecycle persistence and verification contracts tied to existing `mobile_devices`, while keeping `mobile.attestation.enabled` default-disabled, avoiding enforcement broadening, and not claiming full end-to-end assertion verification until that lifecycle is actually wired.
- 2026-04-08: Backend App Attest prerequisite foundation landed without spec changes. The new layer is separate from biometric/passkey flows and currently provides (1) authenticated challenge issuance for `enrollment` / `assertion` prerequisites, (2) persisted App Attest key enrollment records per user/device/key id, (3) replay-safe challenge consumption, and (4) a verifier contract seam for later cryptographic assertion wiring.
- 2026-04-08: Rollout posture remains explicitly safe. `mobile.attestation.enabled` stays default-disabled, the new App Attest endpoints return `rollout_enabled` for visibility only, and `HighRiskActionTrustPolicy` semantics were not widened in this slice.
- 2026-04-08: Broader verification beyond the new slice exposed one unrelated existing test-environment issue: `tests/Feature/Api/MobileControllerTest.php::test_can_enable_biometric_authentication` still fails locally because `openssl_pkey_new()` is configured with an invalid minimum private-key length in this runtime. This was not introduced by the App Attest changes, so the slice was verified with the targeted App Attest suite plus targeted phpstan on changed files.
- 2026-04-08: Reconciled tracker against the live backoffice and RN code before starting the next slice. Completed entries remain accurate: backend prerequisite endpoints/persistence exist, RN still reports iOS App Attest backend capability as missing, and there is no canonical backend assertion verification endpoint yet.
- 2026-04-08: Next dependency-safe slice selected is backend App Attest assertion lifecycle wiring, not RN rollout. This narrows the remaining iOS blocker by exposing a server-owned assertion challenge/verify flow on top of existing key/challenge persistence while keeping `mobile.attestation.enabled` default-disabled and avoiding any trust-policy widening.
- 2026-04-08: Backend assertion lifecycle wiring is now present without spec changes. The backend owns assertion challenge issuance, enrolled-key lookup, verifier delegation, replay-safe challenge consumption, and `last_assertion_at` persistence; the verifier still remains a contract seam and does not yet claim full production-grade App Attest cryptographic verification.
- 2026-04-08: RN capability reporting is now stale against backend reality. `maphapayrn/src/features/security/mobileAttestation.ts` still reports `ios_app_attest_backend_prerequisites_missing`, but backend challenge/enroll/verify lifecycle endpoints now exist; the next dependency-safe slice should reconcile the RN seam/request shapes with this backend capability without treating runtime-posture fallback as real attestation.
- 2026-04-08: Reconciled tracker against the current backoffice and RN code before editing. Completed entries still match code, both worktrees were clean, and the next dependency-safe slice is RN-side backend App Attest API seam work plus capability-string correction because backend lifecycle endpoints now exist but RN still has no explicit client wrappers and still reports the old prerequisite-missing reason.
- 2026-04-08: Completed the RN App Attest seam follow-up without spec changes. `maphapayrn` now has explicit backend wrappers for App Attest challenge, enroll, and verify operations, but nothing is enabled by default and no high-risk flow calls them yet; this removes the “missing client seam” blocker while preserving the existing provider boundary for later native iOS collection work.
- 2026-04-08: iOS runtime-posture fallback semantics were narrowed, not broadened. The RN attestation layer no longer claims backend prerequisites are missing; it now reports `ios_app_attest_native_collection_unimplemented`, which reflects the actual remaining blocker that native end-to-end App Attest collection is still not wired through high-risk flows.
- 2026-04-08: Reconciled tracker against both repos before the next slice. The new RN backend App Attest API seam is present and still unused by default; the next dependency-safe blocker is the missing provider orchestration contract that would let a future native iOS module compose backend challenge/enroll/verify without bypassing the existing `mobileAttestation` boundary.
- 2026-04-08: Completed the RN App Attest provider orchestration seam without spec changes. `maphapayrn` now exposes a provider factory that can enroll a new App Attest key on first use, reuse an existing key on later calls, and wrap backend assertion verification into a structured `ios-app-attest:` envelope, but this provider is not installed anywhere by default and no high-risk flow uses it yet.

## Cross-Cutting Blockers

| Item | Status | Notes |
|---|---|---|
| Ledger-first dependency for later sections | TODO | Later sections may depend on ledger-core outputs. |

## Spec Adjustments Log

- None yet.
