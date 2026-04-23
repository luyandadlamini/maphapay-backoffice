# Minor Accounts Phase 9B Implementation Plan — Reconciliation Exception Lifecycle Hardening

Date: 2026-04-23

## Goal

Harden Phase 9A reconciliation safety by introducing explicit exception lifecycle closure/reopen controls and deterministic auto-resolution when callback/status/reconciliation convergence is successful.

## Scope Guard

In scope:
- exception lifecycle hardening only,
- operator workflow hardening for exception handling only,
- tests + static analysis for touched paths.

Out of scope:
- money movement logic changes,
- route/API contract expansions,
- new provider integrations.

## Execution Steps

### 1) Add lifecycle schema support

Files:
- `database/migrations/<timestamp>_add_resolved_at_to_minor_family_reconciliation_exceptions_table.php`

Tasks:
- add nullable `resolved_at` timestamp.
- add index to support open/resolved queue filtering.

Stop/Go:
- migrations run successfully.
- no existing migration-dependent tests regress.

---

### 2) Extend exception queue service lifecycle methods

Files:
- `app/Domain/Account/Services/MinorFamilyReconciliationExceptionQueueService.php`
- (optional) `app/Domain/Account/Models/MinorFamilyReconciliationException.php` casts/docblock update for `resolved_at`

Tasks:
- add `resolveOpenExceptionsForTransaction(...)` method:
  - set status `resolved`,
  - stamp `resolved_at`,
  - write additive metadata (`resolution` block with source/timestamp/reason).
- add `reopenException(...)` method for operator workflow:
  - set status back to `open`,
  - clear `resolved_at`,
  - append additive metadata (`reopened` block).

Stop/Go:
- lifecycle transitions are idempotent and transaction-safe.

---

### 3) Wire reconciliation auto-resolution

Files:
- `app/Domain/Account/Services/MinorFamilyReconciliationService.php`

Tasks:
- when reconcile result for funding attempt or support transfer is terminal reconciled:
  - call queue service resolve method with source (`callback`, `status_poll`, `reconcile_command`, `filament_retry_settlement`).
- preserve existing unresolved/open exception behavior.

Stop/Go:
- no changes to wallet deposit/refund orchestration.
- existing terminal convergence semantics remain unchanged.

---

### 4) Harden Filament operator lifecycle controls

Files:
- `app/Filament/Admin/Resources/MinorFamilyReconciliationExceptionResource.php`

Tasks:
- keep existing acknowledge action append-only.
- add `resolve_exception` table action:
  - visible for open exceptions,
  - requires note,
  - writes acknowledgment,
  - sets resolved lifecycle state via queue service or direct transactional update helper.
- add `reopen_exception` table action:
  - visible for resolved exceptions,
  - requires reason/note,
  - writes acknowledgment,
  - returns status to open.

Stop/Go:
- no destructive or financial actions introduced.
- action availability strictly follows status.

---

### 5) Add/adjust focused tests

Files likely touched:
- `tests/Unit/Domain/Account/Services/MinorFamilyReconciliationServiceExceptionQueueTest.php`
- `tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MinorFamilyMtnCallbackIntegrationTest.php`
- `tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsMinorFamilyTest.php`
- `tests/Feature/Filament/MinorFamilyReconciliationExceptionResourceTest.php`
- optional new test file for queue service lifecycle transitions.

Coverage targets:
- open -> resolved on reconciled outcomes,
- unresolved paths stay open,
- resolve/reopen operator actions work and remain auditable,
- acknowledgment relation remains append-only,
- SLA command remains compatible.

Stop/Go:
- all targeted suites pass.

---

### 6) Verification and safety gate

Run:
- targeted test suites for callback/status/reconcile + console + Filament exception workflows.
- focused PHPStan on changed files.

Definition of Done:
- Phase 9B docs exist and match implementation.
- exception lifecycle transitions are deterministic and auditable.
- no regressions in Phase 9A money movement safety behavior.
- targeted tests and static analysis are green.
