# Minor Accounts Phase 10 Tier Lifecycle & Compliance Automation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship deterministic, auditable, and idempotent lifecycle automation for minor accounts (tier progression, age-18 transition controls, guardian continuity handling, and lifecycle exception operations) without introducing unsafe financial side effects.

**Architecture:** Build a dedicated lifecycle control-plane (transition + exception records, policy service, scheduler commands, operator workflows) that reuses existing minor access control and monitoring infrastructure. Keep money movement rails unchanged; lifecycle automation coordinates account-state and compliance readiness only, while preserving full audit/event trails.

**Tech Stack:** Laravel 12, PHP 8.4, Pest, MySQL (tenant + central), Filament v3, existing `MinorAccountAccessService`, `MinorNotificationService`, monitoring services.

---

## Scope Guard

In scope:
- Lifecycle transition scheduling and execution for minor accounts.
- Age-18 takeover freeze/unfreeze gating based on KYC readiness policy.
- Guardian continuity safety checks and lifecycle exception artifacting.
- Filament lifecycle operations for exception acknowledgment and resolution.
- Scheduler/metrics/SLA visibility for lifecycle operations.

Out of scope:
- New payment rails, new provider integrations, or callback logic redesign.
- Replacing canonical authorization services.
- Mobile feature implementation beyond API contract additions.

---

## File Structure

### New files

- `app/Domain/Account/Models/MinorAccountLifecycleTransition.php`
  - lifecycle transition record and state helpers.
- `app/Domain/Account/Models/MinorAccountLifecycleException.php`
  - non-financial lifecycle exception artifact.
- `app/Domain/Account/Models/MinorAccountLifecycleExceptionAcknowledgment.php`
  - append-only operator review history.
- `app/Domain/Account/Services/MinorAccountLifecyclePolicy.php`
  - policy decisions for age-band progression and transition blocking.
- `app/Domain/Account/Services/MinorAccountLifecycleService.php`
  - orchestration for scheduling/executing lifecycle transitions.
- `app/Console/Commands/EvaluateMinorAccountLifecycleTransitions.php`
  - periodic lifecycle transition evaluator.
- `app/Console/Commands/FlagMinorAccountLifecycleExceptionSlaBreaches.php`
  - marks overdue lifecycle exceptions for operator escalation.
- `app/Filament/Admin/Resources/MinorAccountLifecycleTransitionResource.php`
- `app/Filament/Admin/Resources/MinorAccountLifecycleExceptionResource.php`
- `app/Filament/Admin/Resources/MinorAccountLifecycleExceptionResource/RelationManagers/AcknowledgmentsRelationManager.php`
- `tests/Unit/Domain/Account/Services/MinorAccountLifecyclePolicyTest.php`
- `tests/Unit/Domain/Account/Services/MinorAccountLifecycleServiceTest.php`
- `tests/Feature/Console/EvaluateMinorAccountLifecycleTransitionsTest.php`
- `tests/Feature/Console/FlagMinorAccountLifecycleExceptionSlaBreachesTest.php`
- `tests/Feature/Filament/MinorAccountLifecycleTransitionResourceTest.php`
- `tests/Feature/Filament/MinorAccountLifecycleExceptionResourceTest.php`
- `tests/Feature/Http/Controllers/Api/MinorAccountLifecycleControllerTest.php`

### Existing files to modify

- `database/migrations/*` (new migrations only, no historical edits).
- `app/Domain/Account/Routes/api.php` (lifecycle read/action endpoints).
- `routes/console.php` (schedule lifecycle commands).
- `app/Domain/Account/Services/MinorNotificationService.php` (new audit/notification types).
- `app/Domain/Monitoring/Services/MetricsCollectorService.php` (lifecycle metrics).
- `app/Domain/Monitoring/Services/MoneyMovementTransactionInspector.php` (lifecycle context visibility where relevant).

---

## Task 1: Introduce Lifecycle Schema

**Files:**
- Create: `database/migrations/2026_04_23_110000_create_minor_account_lifecycle_transitions_table.php`
- Create: `database/migrations/2026_04_23_110100_create_minor_account_lifecycle_exceptions_table.php`
- Create: `database/migrations/2026_04_23_110110_create_minor_account_lifecycle_exception_acknowledgments_table.php`
- Test: `tests/Feature/Database/MinorAccountLifecycleSchemaTest.php`

- [ ] Add failing schema test for required tables, foreign-key assumptions, and uniqueness/index behavior.
- [ ] Run schema test and verify it fails before migration.
- [ ] Implement migrations with deterministic keys, state columns, and SLA fields.
- [ ] Re-run schema test and verify pass.
- [ ] Commit schema changes.

**Stop/Go Check:** unique replay guard (`minor_account_uuid + transition_type + effective_at`) verified.

---

## Task 2: Implement Lifecycle Domain Models and Policy

**Files:**
- Create: `app/Domain/Account/Models/MinorAccountLifecycleTransition.php`
- Create: `app/Domain/Account/Models/MinorAccountLifecycleException.php`
- Create: `app/Domain/Account/Models/MinorAccountLifecycleExceptionAcknowledgment.php`
- Create: `app/Domain/Account/Services/MinorAccountLifecyclePolicy.php`
- Test: `tests/Unit/Domain/Account/Services/MinorAccountLifecyclePolicyTest.php`

- [ ] Write failing policy tests covering:
  - age-band eligibility,
  - age-18 readiness blocking,
  - guardian continuity blocking,
  - deterministic reason codes.
- [ ] Run tests and confirm failure.
- [ ] Implement models and policy with explicit casts and state constants.
- [ ] Re-run tests and confirm pass.
- [ ] Commit model/policy layer.

**Stop/Go Check:** policy decisions are pure and deterministic (same inputs -> same output).

---

## Task 3: Add Lifecycle Service and Stored Events

**Files:**
- Create: `app/Domain/Account/Services/MinorAccountLifecycleService.php`
- Create: `app/Domain/Account/Events/MinorAccountLifecycleTransitionScheduled.php`
- Create: `app/Domain/Account/Events/MinorAccountLifecycleTransitionBlocked.php`
- Create: `app/Domain/Account/Events/MinorAccountTierAdvanced.php`
- Create: `app/Domain/Account/Events/MinorAccountAdultTransitionCompleted.php`
- Create: `app/Domain/Account/Events/MinorAccountAdultTransitionFrozen.php`
- Create: `app/Domain/Account/Events/MinorAccountGuardianContinuityBroken.php`
- Create: `app/Domain/Account/Events/MinorAccountLifecycleExceptionOpened.php`
- Create: `app/Domain/Account/Events/MinorAccountLifecycleExceptionResolved.php`
- Modify: `app/Domain/Account/Services/MinorNotificationService.php`
- Test: `tests/Unit/Domain/Account/Services/MinorAccountLifecycleServiceTest.php`

- [ ] Write failing service tests for idempotent scheduling and monotonic state transitions.
- [ ] Implement service transaction boundaries (`lockForUpdate`) and stored events.
- [ ] Add minor notification/audit mappings for lifecycle event types.
- [ ] Re-run service tests and event-shape assertions.
- [ ] Commit lifecycle service + events.

**Stop/Go Check:** repeated service invocation does not duplicate terminal side effects.

---

## Task 4: Add Lifecycle Scheduler Commands

**Files:**
- Create: `app/Console/Commands/EvaluateMinorAccountLifecycleTransitions.php`
- Create: `app/Console/Commands/FlagMinorAccountLifecycleExceptionSlaBreaches.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/EvaluateMinorAccountLifecycleTransitionsTest.php`
- Test: `tests/Feature/Console/FlagMinorAccountLifecycleExceptionSlaBreachesTest.php`

- [ ] Add failing console tests for:
  - transition generation/execution,
  - blocked path exception creation,
  - SLA escalation behavior.
- [ ] Implement command handlers with chunking and explicit summary outputs.
- [ ] Schedule commands with `withoutOverlapping` and output logs.
- [ ] Re-run console suites and verify pass.
- [ ] Commit command/scheduler changes.

**Stop/Go Check:** commands fail closed (non-zero exit) when unresolved lifecycle exceptions are produced.

---

## Task 5: Add Lifecycle API Contracts

**Files:**
- Create: `app/Http/Controllers/Api/MinorAccountLifecycleController.php`
- Modify: `app/Domain/Account/Routes/api.php`
- Test: `tests/Feature/Http/Controllers/Api/MinorAccountLifecycleControllerTest.php`

- [ ] Write failing API tests for guardian/operator auth boundaries and state rejection (`409/422`).
- [ ] Implement read endpoints and controlled review-action endpoint.
- [ ] Ensure endpoint authorization path reuses `MinorAccountAccessService`.
- [ ] Re-run API tests and verify pass.
- [ ] Commit lifecycle API layer.

**Stop/Go Check:** no endpoint bypasses canonical access control.

---

## Task 6: Build Filament Lifecycle Operations

**Files:**
- Create: `app/Filament/Admin/Resources/MinorAccountLifecycleTransitionResource.php`
- Create: `app/Filament/Admin/Resources/MinorAccountLifecycleExceptionResource.php`
- Create: `app/Filament/Admin/Resources/MinorAccountLifecycleExceptionResource/RelationManagers/AcknowledgmentsRelationManager.php`
- Test: `tests/Feature/Filament/MinorAccountLifecycleTransitionResourceTest.php`
- Test: `tests/Feature/Filament/MinorAccountLifecycleExceptionResourceTest.php`

- [ ] Add failing Filament tests for list/view access and action restrictions.
- [ ] Implement resources and acknowledgment flow with required operator notes.
- [ ] Ensure actions trigger service transitions, not direct status flipping.
- [ ] Re-run Filament tests and verify pass.
- [ ] Commit Filament lifecycle surfaces.

**Stop/Go Check:** lifecycle exception actions are append-only for review history.

---

## Task 7: Metrics and Diagnostics Integration

**Files:**
- Modify: `app/Domain/Monitoring/Services/MetricsCollectorService.php`
- Modify: `app/Domain/Monitoring/Services/MoneyMovementTransactionInspector.php`
- Test: `tests/Unit/Domain/Monitoring/Services/HealthCheckerTest.php` (or lifecycle-specific monitoring tests)

- [ ] Add failing tests/assertions for lifecycle metric emission and inspector payload inclusion.
- [ ] Implement metrics:
  - transitions_scheduled_total,
  - transitions_blocked_total,
  - lifecycle_exceptions_open_total,
  - lifecycle_exceptions_sla_breached_total.
- [ ] Ensure inspector can surface lifecycle linkage without breaking existing output contract.
- [ ] Re-run monitoring suites.
- [ ] Commit observability updates.

**Stop/Go Check:** lifecycle operators can detect blocked transitions without querying raw DB rows.

---

## Task 8: Regression and Final Verification

**Files:**
- Modify documentation only if contract changes require updates in existing phase docs.
- Test all newly added suites plus critical regressions.

- [ ] Run targeted Phase 10 suites.
- [ ] Run critical regressions:
  - `tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MinorFamilyMtnCallbackIntegrationTest.php`
  - `tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsMinorFamilyTest.php`
  - `tests/Feature/Console/FlagMinorFamilyReconciliationExceptionSlaBreachesTest.php`
- [ ] Run static analysis for all new lifecycle classes.
- [ ] Confirm no regression in existing minor-family reconciliation workflows.
- [ ] Commit final hardening pass.

---

## Objective Definition of Done

- Lifecycle automation runs on schedule and is idempotent.
- Age-18 transition blocking and guardian continuity exceptions are deterministic and auditable.
- Filament operators can review, acknowledge, and resolve lifecycle exceptions with history.
- Lifecycle exceptions are SLA-flagged and visible in admin operations.
- Existing Phase 9A money movement safety behavior remains unchanged and green in regression tests.

---

## Top Risks and Mitigations (Execution-Time)

1. **State-machine drift**: transition states mutated ad hoc.
   - Mitigation: centralized service methods + policy result enums + state tests.
2. **Authorization bypass** for lifecycle actions.
   - Mitigation: mandatory `MinorAccountAccessService` checks in all mutation paths.
3. **Command replay duplication**.
   - Mitigation: DB uniqueness + transactional locking + idempotent command design.
4. **Operator blind spots**.
   - Mitigation: lifecycle exception resources + SLA flags + metrics.
5. **Phase 9A regression via shared MTN/context code paths**.
   - Mitigation: mandatory regression suites as final gate before release.

