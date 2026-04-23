# Minor Accounts Phase 9A Integrations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build Phase 9A minor-account integrations for guardian-funded outbound MTN MoMo family support transfers and guardian-created external family funding links, with safe callback/reconciliation handling and operator visibility.

**Architecture:** Extend the existing minor-account, MTN MoMo, payment-link, and money-movement safety primitives instead of creating a second provider or approval architecture. New Phase 9A records own business context and provider linkage, while actual balance movement still flows through the existing wallet and money-movement infrastructure. Every lifecycle state change must remain idempotent, auditable, and inspectable.

**Tech Stack:** Laravel 12, PHP 8.4, Pest, MySQL tenant + central DB, existing MTN MoMo compat controllers/services, existing minor account services, Filament v3

---

## Scope Guard

This plan is intentionally limited to Phase 9A from `docs/superpowers/specs/2026-04-22-minor-accounts-phase9-integrations-spec.md`.

In scope:
- Guardian-funded outbound family support transfers over MTN MoMo
- Guardian-created external family funding links
- Funding-attempt settlement/callback/reconciliation
- Filament operator/admin surfaces for the new Phase 9A records

Out of scope:
- Child-funded remittances
- Multi-provider routing
- Cross-border remittance
- Merchant QR flows
- General provider orchestration refactor across the repo

## File Structure

### New backend files

- `app/Domain/Account/Models/MinorFamilyFundingLink.php`
  Responsibility: tenant-scoped funding-link record and status helpers
- `app/Domain/Account/Models/MinorFamilyFundingAttempt.php`
  Responsibility: tenant-scoped inbound funding attempt linked to provider rows
- `app/Domain/Account/Models/MinorFamilySupportTransfer.php`
  Responsibility: tenant-scoped outbound guardian-funded family support transfer
- `app/Domain/Account/Events/MinorFamilyFundingLinkCreated.php`
- `app/Domain/Account/Events/MinorFamilyFundingLinkExpired.php`
- `app/Domain/Account/Events/MinorFamilyFundingAttemptInitiated.php`
- `app/Domain/Account/Events/MinorFamilyFundingAttemptSucceeded.php`
- `app/Domain/Account/Events/MinorFamilyFundingAttemptFailed.php`
- `app/Domain/Account/Events/MinorFamilyFundingCredited.php`
- `app/Domain/Account/Events/MinorFamilySupportTransferInitiated.php`
- `app/Domain/Account/Events/MinorFamilySupportTransferSucceeded.php`
- `app/Domain/Account/Events/MinorFamilySupportTransferFailed.php`
- `app/Domain/Account/Events/MinorFamilySupportTransferRefunded.php`
  Responsibility: persisted business events for Phase 9A lifecycle state
- `app/Domain/Account/Services/MinorFamilyFundingPolicy.php`
  Responsibility: bounded rules for link creation, expiry, amount caps, and provider options
- `app/Domain/Account/Services/MinorFamilyIntegrationService.php`
  Responsibility: application service coordinating auth, persistence, MTN adapter calls, and audit hooks
- `app/Domain/Account/Services/MinorFamilyReconciliationService.php`
  Responsibility: converge callback/status/reconciliation results into Phase 9A records safely
- `app/Domain/MtnMomo/Services/MtnMomoFamilyFundingAdapter.php`
  Responsibility: wrapper around `MtnMomoClient` for Phase 9A-specific flows
- `app/Http/Controllers/Api/MinorFamilyFundingLinkController.php`
  Responsibility: authenticated guardian APIs for link CRUD/list
- `app/Http/Controllers/Api/PublicMinorFundingLinkController.php`
  Responsibility: public link lookup + public MTN request-to-pay initiation + public attempt status
- `app/Http/Controllers/Api/MinorFamilySupportTransferController.php`
  Responsibility: authenticated guardian APIs for outbound family support transfer create/list
- `app/Filament/Admin/Resources/MinorFamilyFundingLinkResource.php`
- `app/Filament/Admin/Resources/MinorFamilyFundingAttemptResource.php`
- `app/Filament/Admin/Resources/MinorFamilySupportTransferResource.php`
  Responsibility: operator/admin surfaces for Phase 9A
- `tests/Feature/Http/Controllers/Api/MinorFamilyFundingLinkControllerTest.php`
- `tests/Feature/Http/Controllers/Api/PublicMinorFundingLinkControllerTest.php`
- `tests/Feature/Http/Controllers/Api/MinorFamilySupportTransferControllerTest.php`
- `tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MinorFamilyMtnCallbackIntegrationTest.php`
- `tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsMinorFamilyTest.php`
- `tests/Unit/Domain/Account/Services/MinorFamilyFundingPolicyTest.php`
- `tests/Unit/Domain/Account/Services/MinorFamilyIntegrationServiceTest.php`
- `tests/Feature/Filament/MinorFamilyFundingLinkResourceTest.php`
- `tests/Feature/Filament/MinorFamilySupportTransferResourceTest.php`

### Existing backend files to modify

- `database/migrations/2026_03_28_170000_create_mtn_momo_transactions_table.php`
  Reference only; do not edit historical migration
- `database/migrations/*`
  Create new migrations for Phase 9A tables and MTN context columns
- `app/Domain/Account/Routes/api.php`
  Add authenticated Phase 9A routes
- `routes/api-compat.php`
  Add or wire the public Phase 9A routes only if they belong under compat/public API shape
- `app/Domain/Account/Services/MinorNotificationService.php`
  Add new audit action mappings for Phase 9A lifecycle
- `app/Http/Controllers/Api/Compatibility/Mtn/CallbackController.php`
  Route callback outcomes into Phase 9A records without breaking existing MTN flows
- `app/Http/Controllers/Api/Compatibility/Mtn/TransactionStatusController.php`
  Route status polling convergence into Phase 9A records where applicable
- `app/Console/Commands/ReconcileMtnMomoTransactions.php`
  Teach reconciliation to update Phase 9A records linked to MTN rows
- `app/Domain/Monitoring/Services/MoneyMovementTransactionInspector.php`
  Expose Phase 9A context when MTN/provider-linked references are inspected
- `app/Filament/Admin/Resources/MtnMomoTransactionResource.php`
  Link to new Phase 9A resources or add read-only context visibility only; do not add status-flipping actions

---

### Task 1: Add Phase 9A Schema

**Files:**
- Create: `database/migrations/2026_04_23_100000_create_minor_family_funding_links_table.php`
- Create: `database/migrations/2026_04_23_100100_create_minor_family_funding_attempts_table.php`
- Create: `database/migrations/2026_04_23_100200_create_minor_family_support_transfers_table.php`
- Create: `database/migrations/2026_04_23_100300_add_minor_family_context_to_mtn_momo_transactions_table.php`
- Test: `tests/Feature/Database/MinorFamilyPhase9SchemaTest.php`

- [ ] **Step 1: Write the failing schema test**

Add a focused feature test that asserts:
- the new Phase 9A tables exist
- required unique/index constraints exist in behavior
- `mtn_momo_transactions` can store Phase 9A context columns

Example assertions:

```php
expect(Schema::hasTable('minor_family_funding_links'))->toBeTrue();
expect(Schema::hasColumns('minor_family_funding_attempts', [
    'minor_account_uuid',
    'provider_name',
    'provider_reference_id',
    'dedupe_hash',
]))->toBeTrue();
expect(Schema::hasColumns('mtn_momo_transactions', [
    'context_type',
    'context_uuid',
]))->toBeTrue();
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
php artisan test tests/Feature/Database/MinorFamilyPhase9SchemaTest.php
```

Expected: FAIL because the new tables/columns do not exist yet.

- [ ] **Step 3: Create the migrations**

Implement:
- `minor_family_funding_links`
- `minor_family_funding_attempts`
- `minor_family_support_transfers`
- additive context columns on `mtn_momo_transactions`

Required minimum columns:
- funding links: `id`, `tenant_id`, `minor_account_uuid`, `created_by_user_uuid`, `created_by_account_uuid`, `title`, `note`, `token`, `status`, `amount_mode`, `fixed_amount`, `target_amount`, `collected_amount`, `asset_code`, `provider_options`, `expires_at`, `last_funded_at`, timestamps
- funding attempts: `id`, `tenant_id`, `funding_link_uuid`, `minor_account_uuid`, `status`, `sponsor_name`, `sponsor_msisdn`, `amount`, `asset_code`, `provider_name`, `provider_reference_id`, `mtn_momo_transaction_id`, `wallet_credited_at`, `failed_reason`, `dedupe_hash`, timestamps
- support transfers: `id`, `tenant_id`, `minor_account_uuid`, `actor_user_uuid`, `source_account_uuid`, `status`, `provider_name`, `recipient_name`, `recipient_msisdn`, `amount`, `asset_code`, `note`, `provider_reference_id`, `mtn_momo_transaction_id`, `wallet_refunded_at`, `failed_reason`, `idempotency_key`, timestamps

- [ ] **Step 4: Run the schema test to verify it passes**

Run:

```bash
php artisan test tests/Feature/Database/MinorFamilyPhase9SchemaTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations tests/Feature/Database/MinorFamilyPhase9SchemaTest.php
git commit -m "feat(minor): add phase 9a family integration schema"
```

### Task 2: Add Phase 9A Models And Policy

**Files:**
- Create: `app/Domain/Account/Models/MinorFamilyFundingLink.php`
- Create: `app/Domain/Account/Models/MinorFamilyFundingAttempt.php`
- Create: `app/Domain/Account/Models/MinorFamilySupportTransfer.php`
- Create: `app/Domain/Account/Services/MinorFamilyFundingPolicy.php`
- Test: `tests/Unit/Domain/Account/Services/MinorFamilyFundingPolicyTest.php`

- [ ] **Step 1: Write the failing policy test**

Cover:
- guardian/co-guardian can create bounded links if access is valid
- expired links are rejected
- capped links reject amounts above remaining amount
- unsupported providers are rejected
- minor-owned source account is rejected for outbound support transfer in Phase 9A

Example:

```php
$result = $policy->validateFundingAttempt($link, amount: '150.00', provider: 'mtn_momo');
expect($result->allowed)->toBeTrue();
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
php artisan test tests/Unit/Domain/Account/Services/MinorFamilyFundingPolicyTest.php
```

Expected: FAIL because the policy/model classes do not exist yet.

- [ ] **Step 3: Implement the models and policy**

Implement:
- model casts/status helpers
- relation methods between links, attempts, and support transfers
- policy methods for:
  - link creation
  - funding attempt validation
  - outbound support transfer validation

Keep the models focused:
- no provider calls in model methods
- no controller auth logic in models

- [ ] **Step 4: Run the unit test to verify it passes**

Run:

```bash
php artisan test tests/Unit/Domain/Account/Services/MinorFamilyFundingPolicyTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Account/Models app/Domain/Account/Services/MinorFamilyFundingPolicy.php tests/Unit/Domain/Account/Services/MinorFamilyFundingPolicyTest.php
git commit -m "feat(minor): add phase 9a family policy and models"
```

### Task 3: Add Persisted Phase 9A Events And Audit Hooks

**Files:**
- Create: `app/Domain/Account/Events/MinorFamilyFundingLinkCreated.php`
- Create: `app/Domain/Account/Events/MinorFamilyFundingLinkExpired.php`
- Create: `app/Domain/Account/Events/MinorFamilyFundingAttemptInitiated.php`
- Create: `app/Domain/Account/Events/MinorFamilyFundingAttemptSucceeded.php`
- Create: `app/Domain/Account/Events/MinorFamilyFundingAttemptFailed.php`
- Create: `app/Domain/Account/Events/MinorFamilyFundingCredited.php`
- Create: `app/Domain/Account/Events/MinorFamilySupportTransferInitiated.php`
- Create: `app/Domain/Account/Events/MinorFamilySupportTransferSucceeded.php`
- Create: `app/Domain/Account/Events/MinorFamilySupportTransferFailed.php`
- Create: `app/Domain/Account/Events/MinorFamilySupportTransferRefunded.php`
- Modify: `app/Domain/Account/Services/MinorNotificationService.php`
- Test: `tests/Unit/Domain/Account/Events/MinorFamilyEventShapeTest.php`
- Test: `tests/Feature/Account/MinorFamilyAuditLogTest.php`

- [ ] **Step 1: Write failing tests for event shape and audit log action mapping**

Cover:
- events extend `ShouldBeStored`
- events use past-tense business naming
- notification service writes durable `account_audit_logs` rows for new Phase 9A actions

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
php artisan test tests/Unit/Domain/Account/Events/MinorFamilyEventShapeTest.php tests/Feature/Account/MinorFamilyAuditLogTest.php
```

Expected: FAIL because the events/action mappings do not exist yet.

- [ ] **Step 3: Implement the event classes and notification mappings**

Add action mappings such as:
- `minor.family_funding_link.created`
- `minor.family_funding_link.expired`
- `minor.family_funding_attempt.initiated`
- `minor.family_funding_attempt.succeeded`
- `minor.family_funding_attempt.failed`
- `minor.family_support_transfer.initiated`
- `minor.family_support_transfer.succeeded`
- `minor.family_support_transfer.failed`
- `minor.family_support_transfer.refunded`

- [ ] **Step 4: Run the tests to verify they pass**

Run:

```bash
php artisan test tests/Unit/Domain/Account/Events/MinorFamilyEventShapeTest.php tests/Feature/Account/MinorFamilyAuditLogTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Account/Events app/Domain/Account/Services/MinorNotificationService.php tests/Unit/Domain/Account/Events/MinorFamilyEventShapeTest.php tests/Feature/Account/MinorFamilyAuditLogTest.php
git commit -m "feat(minor): add phase 9a events and audit hooks"
```

### Task 4: Add MTN Adapter And Integration Service

**Files:**
- Create: `app/Domain/MtnMomo/Services/MtnMomoFamilyFundingAdapter.php`
- Create: `app/Domain/Account/Services/MinorFamilyIntegrationService.php`
- Test: `tests/Unit/Domain/Account/Services/MinorFamilyIntegrationServiceTest.php`

- [ ] **Step 1: Write the failing integration-service test**

Cover:
- outbound support transfer creates Phase 9A transfer record and MTN request safely
- public funding attempt creates Phase 9A attempt and MTN collection request safely
- duplicate idempotent replay does not create duplicate transfer/attempt
- provider rows get `context_type` and `context_uuid`

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
php artisan test tests/Unit/Domain/Account/Services/MinorFamilyIntegrationServiceTest.php
```

Expected: FAIL because the service/adapter do not exist yet.

- [ ] **Step 3: Implement the MTN adapter and integration service**

Adapter responsibilities:
- wrap `MtnMomoClient`
- normalize MTN request inputs/outputs for Phase 9A
- never call compat controllers

Integration service responsibilities:
- authorize via `MinorAccountAccessService`
- validate via `MinorFamilyFundingPolicy`
- create/update Phase 9A records
- create/update linked `mtn_momo_transactions`
- invoke `MinorNotificationService`
- emit persisted events

- [ ] **Step 4: Run the unit test to verify it passes**

Run:

```bash
php artisan test tests/Unit/Domain/Account/Services/MinorFamilyIntegrationServiceTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Domain/MtnMomo/Services/MtnMomoFamilyFundingAdapter.php app/Domain/Account/Services/MinorFamilyIntegrationService.php tests/Unit/Domain/Account/Services/MinorFamilyIntegrationServiceTest.php
git commit -m "feat(minor): add phase 9a family integration service"
```

### Task 5: Add Authenticated Guardian APIs

**Files:**
- Create: `app/Http/Controllers/Api/MinorFamilyFundingLinkController.php`
- Create: `app/Http/Controllers/Api/MinorFamilySupportTransferController.php`
- Modify: `app/Domain/Account/Routes/api.php`
- Test: `tests/Feature/Http/Controllers/Api/MinorFamilyFundingLinkControllerTest.php`
- Test: `tests/Feature/Http/Controllers/Api/MinorFamilySupportTransferControllerTest.php`

- [ ] **Step 1: Write failing controller tests**

Cover:
- guardian/co-guardian can create/list links
- child cannot create links
- guardian/co-guardian can create/list support transfers
- source account owned by actor is required
- `Idempotency-Key` required for transfer creation

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorFamilyFundingLinkControllerTest.php tests/Feature/Http/Controllers/Api/MinorFamilySupportTransferControllerTest.php
```

Expected: FAIL because routes/controllers do not exist yet.

- [ ] **Step 3: Implement controllers and routes**

Controller methods:
- `MinorFamilyFundingLinkController@index`
- `MinorFamilyFundingLinkController@store`
- `MinorFamilyFundingLinkController@expire`
- `MinorFamilySupportTransferController@index`
- `MinorFamilySupportTransferController@store`

Route shape:
- `GET /api/accounts/minor/{minorAccountUuid}/funding-links`
- `POST /api/accounts/minor/{minorAccountUuid}/funding-links`
- `POST /api/accounts/minor/{minorAccountUuid}/funding-links/{fundingLinkUuid}/expire`
- `GET /api/accounts/minor/{minorAccountUuid}/family-support-transfers`
- `POST /api/accounts/minor/{minorAccountUuid}/family-support-transfers`

- [ ] **Step 4: Run the controller tests to verify they pass**

Run:

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorFamilyFundingLinkControllerTest.php tests/Feature/Http/Controllers/Api/MinorFamilySupportTransferControllerTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MinorFamilyFundingLinkController.php app/Http/Controllers/Api/MinorFamilySupportTransferController.php app/Domain/Account/Routes/api.php tests/Feature/Http/Controllers/Api/MinorFamilyFundingLinkControllerTest.php tests/Feature/Http/Controllers/Api/MinorFamilySupportTransferControllerTest.php
git commit -m "feat(minor): add phase 9a guardian apis"
```

### Task 6: Add Public Funding-Link APIs

**Files:**
- Create: `app/Http/Controllers/Api/PublicMinorFundingLinkController.php`
- Modify: `routes/api-compat.php`
- Test: `tests/Feature/Http/Controllers/Api/PublicMinorFundingLinkControllerTest.php`

- [ ] **Step 1: Write failing public-link tests**

Cover:
- public lookup returns funding metadata for active token
- expired token returns terminal not-found/expired response
- public MTN request-to-pay attempt obeys link bounds
- duplicate attempt dedupes safely
- public attempt status returns sanitized payload only

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
php artisan test tests/Feature/Http/Controllers/Api/PublicMinorFundingLinkControllerTest.php
```

Expected: FAIL because controller/routes do not exist yet.

- [ ] **Step 3: Implement controller and routes**

Controller methods:
- `show(string $token)`
- `requestToPay(string $token, Request $request)`
- `attemptStatus(string $token, string $attemptUuid)`

Public routes:
- `GET /api/minor-support-links/{token}`
- `POST /api/minor-support-links/{token}/mtn/request-to-pay`
- `GET /api/minor-support-links/{token}/attempts/{attemptUuid}`

Do not reuse `PaymentLinkController` directly; reuse `PaymentLinkService` patterns only where appropriate.

- [ ] **Step 4: Run the public-link tests to verify they pass**

Run:

```bash
php artisan test tests/Feature/Http/Controllers/Api/PublicMinorFundingLinkControllerTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PublicMinorFundingLinkController.php routes/api-compat.php tests/Feature/Http/Controllers/Api/PublicMinorFundingLinkControllerTest.php
git commit -m "feat(minor): add phase 9a public funding link apis"
```

### Task 7: Integrate MTN Callback, Status Polling, And Reconciliation

**Files:**
- Create: `app/Domain/Account/Services/MinorFamilyReconciliationService.php`
- Modify: `app/Http/Controllers/Api/Compatibility/Mtn/CallbackController.php`
- Modify: `app/Http/Controllers/Api/Compatibility/Mtn/TransactionStatusController.php`
- Modify: `app/Console/Commands/ReconcileMtnMomoTransactions.php`
- Test: `tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MinorFamilyMtnCallbackIntegrationTest.php`
- Test: `tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsMinorFamilyTest.php`

- [ ] **Step 1: Write failing callback/reconciliation integration tests**

Cover:
- successful public collection callback credits the linked minor account once
- failed outbound support transfer callback refunds the source account once
- duplicate terminal callback is absorbed
- status polling and callback converge to same Phase 9A state
- reconciliation command updates Phase 9A record state for stuck MTN rows

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
php artisan test tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MinorFamilyMtnCallbackIntegrationTest.php tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsMinorFamilyTest.php
```

Expected: FAIL because Phase 9A linkage is not wired yet.

- [ ] **Step 3: Implement the reconciliation service and integrations**

Implementation notes:
- keep existing MTN callback safety behavior intact
- add Phase 9A resolution by `context_type` / `context_uuid` or linked model lookup
- ensure lock-based refund/credit remains one-time
- do not regress existing `MtnMomoControllersTest` or `ReconcileMtnMomoTransactionsTest`

- [ ] **Step 4: Run the new tests and existing MTN suites**

Run:

```bash
php artisan test tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MinorFamilyMtnCallbackIntegrationTest.php tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsMinorFamilyTest.php tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MtnMomoControllersTest.php tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Account/Services/MinorFamilyReconciliationService.php app/Http/Controllers/Api/Compatibility/Mtn/CallbackController.php app/Http/Controllers/Api/Compatibility/Mtn/TransactionStatusController.php app/Console/Commands/ReconcileMtnMomoTransactions.php tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MinorFamilyMtnCallbackIntegrationTest.php tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsMinorFamilyTest.php
git commit -m "feat(minor): integrate phase 9a with mtn callback and reconciliation"
```

### Task 8: Expose Phase 9A Context In Diagnostics

**Files:**
- Modify: `app/Domain/Monitoring/Services/MoneyMovementTransactionInspector.php`
- Test: `tests/Unit/Domain/Monitoring/Services/MoneyMovementTransactionInspectorMinorFamilyTest.php`

- [ ] **Step 1: Write the failing inspector test**

Cover:
- a linked MTN/provider reference exposes the related Phase 9A link/attempt/transfer context
- warnings remain accurate for uncredited/refund-risk cases

- [ ] **Step 2: Run test to verify failure**

Run:

```bash
php artisan test tests/Unit/Domain/Monitoring/Services/MoneyMovementTransactionInspectorMinorFamilyTest.php
```

Expected: FAIL because inspector does not return Phase 9A context yet.

- [ ] **Step 3: Extend the inspector minimally**

Add a `minor_family_context` block or equivalent structured payload; do not refactor unrelated inspector logic.

- [ ] **Step 4: Run new and existing inspector suites**

Run:

```bash
php artisan test tests/Unit/Domain/Monitoring/Services/MoneyMovementTransactionInspectorMinorFamilyTest.php tests/Unit/Domain/Monitoring/Services/MoneyMovementTransactionInspectorTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Monitoring/Services/MoneyMovementTransactionInspector.php tests/Unit/Domain/Monitoring/Services/MoneyMovementTransactionInspectorMinorFamilyTest.php
git commit -m "feat(minor): expose phase 9a context in transaction inspector"
```

### Task 9: Add Filament Operator Surfaces

**Files:**
- Create: `app/Filament/Admin/Resources/MinorFamilyFundingLinkResource.php`
- Create: `app/Filament/Admin/Resources/MinorFamilyFundingAttemptResource.php`
- Create: `app/Filament/Admin/Resources/MinorFamilySupportTransferResource.php`
- Modify: `app/Filament/Admin/Resources/MtnMomoTransactionResource.php`
- Test: `tests/Feature/Filament/MinorFamilyFundingLinkResourceTest.php`
- Test: `tests/Feature/Filament/MinorFamilySupportTransferResourceTest.php`

- [ ] **Step 1: Write failing Filament tests**

Cover:
- list/view pages load for authorized ops users
- dangerous actions do not directly flip provider status
- support transfer and attempt resources surface reconciliation/audit context

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
php artisan test tests/Feature/Filament/MinorFamilyFundingLinkResourceTest.php tests/Feature/Filament/MinorFamilySupportTransferResourceTest.php
```

Expected: FAIL because the resources do not exist yet.

- [ ] **Step 3: Implement the Filament resources**

Requirements:
- list/view first; keep actions conservative
- add navigation grouping under an appropriate operations/transactions area
- if modifying `MtnMomoTransactionResource`, only add linked context visibility or deep-link actions
- do not add row-status mutation as an operator shortcut for financial transitions

- [ ] **Step 4: Run the Filament tests to verify they pass**

Run:

```bash
php artisan test tests/Feature/Filament/MinorFamilyFundingLinkResourceTest.php tests/Feature/Filament/MinorFamilySupportTransferResourceTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Admin/Resources tests/Feature/Filament/MinorFamilyFundingLinkResourceTest.php tests/Feature/Filament/MinorFamilySupportTransferResourceTest.php
git commit -m "feat(minor): add phase 9a filament resources"
```

### Task 10: Final Verification Pass

**Files:**
- Modify: `docs/superpowers/specs/2026-04-22-minor-accounts-phase9-integrations-spec.md` only if implementation-driven contract updates are necessary
- Test: all targeted files from Tasks 1-9

- [ ] **Step 1: Run targeted Phase 9A suites**

Run:

```bash
php artisan test \
  tests/Feature/Database/MinorFamilyPhase9SchemaTest.php \
  tests/Unit/Domain/Account/Services/MinorFamilyFundingPolicyTest.php \
  tests/Unit/Domain/Account/Services/MinorFamilyIntegrationServiceTest.php \
  tests/Unit/Domain/Account/Events/MinorFamilyEventShapeTest.php \
  tests/Feature/Account/MinorFamilyAuditLogTest.php \
  tests/Feature/Http/Controllers/Api/MinorFamilyFundingLinkControllerTest.php \
  tests/Feature/Http/Controllers/Api/MinorFamilySupportTransferControllerTest.php \
  tests/Feature/Http/Controllers/Api/PublicMinorFundingLinkControllerTest.php \
  tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MinorFamilyMtnCallbackIntegrationTest.php \
  tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsMinorFamilyTest.php \
  tests/Unit/Domain/Monitoring/Services/MoneyMovementTransactionInspectorMinorFamilyTest.php \
  tests/Feature/Filament/MinorFamilyFundingLinkResourceTest.php \
  tests/Feature/Filament/MinorFamilySupportTransferResourceTest.php
```

Expected: PASS

- [ ] **Step 2: Re-run regression-sensitive existing suites**

Run:

```bash
php artisan test \
  tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MtnMomoControllersTest.php \
  tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsTest.php \
  tests/Unit/Domain/Monitoring/Services/MoneyMovementTransactionInspectorTest.php
```

Expected: PASS

- [ ] **Step 3: Run focused static analysis on changed PHP files**

Run:

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse \
  app/Domain/Account/Models/MinorFamilyFundingLink.php \
  app/Domain/Account/Models/MinorFamilyFundingAttempt.php \
  app/Domain/Account/Models/MinorFamilySupportTransfer.php \
  app/Domain/Account/Services/MinorFamilyFundingPolicy.php \
  app/Domain/Account/Services/MinorFamilyIntegrationService.php \
  app/Domain/Account/Services/MinorFamilyReconciliationService.php \
  app/Domain/MtnMomo/Services/MtnMomoFamilyFundingAdapter.php \
  app/Http/Controllers/Api/MinorFamilyFundingLinkController.php \
  app/Http/Controllers/Api/PublicMinorFundingLinkController.php \
  app/Http/Controllers/Api/MinorFamilySupportTransferController.php \
  app/Http/Controllers/Api/Compatibility/Mtn/CallbackController.php \
  app/Http/Controllers/Api/Compatibility/Mtn/TransactionStatusController.php \
  app/Console/Commands/ReconcileMtnMomoTransactions.php \
  app/Domain/Monitoring/Services/MoneyMovementTransactionInspector.php \
  --memory-limit=2G
```

Expected: PASS with no errors.

- [ ] **Step 4: Commit final integration pass**

```bash
git add .
git commit -m "feat(minor): complete phase 9a family integrations"
```

---

## Notes For Implementers

- Reuse `MinorAccountAccessService`; do not add another access layer.
- Reuse `PaymentLinkService` patterns, not its `MoneyRequest` model semantics, for Phase 9A public links.
- Reuse `MtnMomoClient`; do not route new domain logic through compat controllers.
- Reuse `MinorNotificationService` and `MoneyMovementTransactionInspector`; do not create a second audit or diagnostics stack.
- Any place where a proposed shortcut would directly mutate a provider row status should be rejected and replaced with a real service transition.
