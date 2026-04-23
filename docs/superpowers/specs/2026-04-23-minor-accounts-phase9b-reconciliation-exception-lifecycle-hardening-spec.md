# Minor Accounts Phase 9B Spec — Reconciliation Exception Lifecycle Hardening

Date: 2026-04-23

## 1. Executive Summary

Phase 9A introduced minor-family MTN callback/status/reconciliation convergence and an exception queue for unresolved cases. The remaining safety gap is lifecycle control for reconciliation exceptions after convergence or manual review. Phase 9B hardens this by adding deterministic exception lifecycle transitions (open -> resolved -> reopened), explicit operator workflow controls, and stronger reconciliation closure behavior so unresolved artifacts do not silently accumulate or mask active financial risk.

Why now:
- Phase 9A already has callback/status/reconcile plumbing and SLA breach flagging.
- Open exception artifacts can remain stale after successful reconciliation.
- Operators can acknowledge but cannot explicitly resolve/reopen with consistent lifecycle semantics.

Expected outcomes:
- lower false-positive operational noise in exception queues,
- faster triage for true unresolved risks,
- stronger auditability for exception handling decisions.

## 2. Scope

### In Scope
- Reconciliation exception lifecycle automation for minor-family MTN contexts:
  - auto-resolve open exceptions when transaction convergence reaches reconciled terminal state.
  - preserve metadata trail for automated resolution source.
- Filament operator lifecycle controls:
  - acknowledge/manual-review stays append-only.
  - add explicit resolve and reopen actions for exception records.
- SLA visibility continuity:
  - keep existing `sla_due_at` and `sla_escalated_at` behavior intact.
  - ensure escalated state remains queryable while lifecycle changes happen.
- Verification updates for callback/status/reconcile convergence with exception lifecycle outcomes.

### Out of Scope
- Any new wallet debit/credit/refund behavior.
- New provider integrations, MTN API contract changes, or route shape changes.
- Replacing the current callback token model with shared webhook middleware.
- Broad observability platform redesign.

### Deferred Follow-ons
- Automated paging/alert routing from escalated exceptions.
- Rich assignment/ownership workflow for ops queues.
- Exception dashboard aggregates per tenant/team with trend analytics.

## 3. Preconditions / Dependencies

### Technical
- Existing Phase 9A models/services are present:
  - `MinorFamilyReconciliationService`
  - `MinorFamilyReconciliationExceptionQueueService`
  - `MinorFamilyReconciliationExceptionResource`
- Existing migration baseline includes exception table + SLA columns + acknowledgments.

### Operational
- `minor-family:reconciliation-exceptions-flag-sla-breaches` remains scheduled.
- Operators retain `view-transactions` permission model in Filament.

### Compliance / Security
- No bypass of `MinorAccountAccessService`.
- No changes that introduce non-idempotent money movement side effects.
- Exception lifecycle actions must remain auditable (metadata + acknowledgment trail).

### Observability / Data
- Existing logs and exception metadata keys are preserved.
- New lifecycle metadata keys remain backward-compatible (additive only).

## 4. Domain + Data Model Changes

### Domain / Service Updates
- `MinorFamilyReconciliationExceptionQueueService`
  - add method to resolve open exceptions for a transaction with source metadata.
- `MinorFamilyReconciliationService`
  - invoke resolve-on-reconciled flow for funding-attempt and support-transfer paths.

### Model / Schema
- Add nullable `resolved_at` timestamp to `minor_family_reconciliation_exceptions` for lifecycle audit and operational filtering.
- Keep `status` as canonical lifecycle flag (`open`, `resolved`).
- Keep existing acknowledgments as append-only audit artifacts.

### Idempotency / Replay Guarantees
- Repeated terminal callback/status/reconcile invocations must not duplicate resolution side effects.
- Reopening an exception never mutates financial records; it only updates lifecycle metadata.
- Auto-resolution is lock-safe and transaction-scoped to exception rows.

## 5. API Contract Plan

No external API endpoint contract changes in Phase 9B.

Internal contract updates:
- reconciliation service now guarantees best-effort exception lifecycle closure when outcome is reconciled.
- Filament resource actions include explicit lifecycle operations:
  - `acknowledge_manual_review` (existing),
  - `resolve_exception` (new),
  - `reopen_exception` (new).

AuthZ expectations:
- same `view-transactions` gate as existing resource.
- actions available only for appropriate record status (open vs resolved).

## 6. Operator Workflow + Filament Blueprint

### User Flows
1. Ops sees open exception with SLA state (on_track/breached/escalated).
2. Ops records manual review note (append-only acknowledgment).
3. Ops resolves exception when evidence confirms closure.
4. Ops can reopen resolved exception if new evidence appears.
5. Reconciliation service auto-resolves stale open exceptions when state converges to reconciled.

### Primitives
- Resource: `MinorFamilyReconciliationExceptionResource`
- Pages: existing list/view pages remain.
- Relation manager: `AcknowledgmentsRelationManager` remains append-only.
- Actions:
  - `acknowledge_manual_review` (existing),
  - `resolve_exception` (new, requires note),
  - `reopen_exception` (new, requires reason/note).

### State Transitions
- `open -> resolved`:
  - by reconciliation auto-closure, or
  - by operator resolve action.
- `resolved -> open`:
  - by operator reopen action.
- `open -> open`:
  - repeated unresolved events increment `occurrence_count`.

## 7. Failure Modes + Risk Register

1. **False auto-resolution**
   - Risk: resolving while still unreconciled.
   - Mitigation: only resolve when reconciliation outcome is explicitly `RECONCILED`.

2. **Duplicate lifecycle mutations under races**
   - Risk: callback/status/reconcile command concurrent updates.
   - Mitigation: transactional row locks on exception updates; idempotent state checks.

3. **Operator action without audit detail**
   - Risk: non-attributable lifecycle changes.
   - Mitigation: require note fields and persist metadata + acknowledgment records.

4. **SLA visibility regression**
   - Risk: lifecycle changes hide escalated state context.
   - Mitigation: retain SLA fields and keep additive metadata.

5. **Unintended financial mutation coupling**
   - Risk: lifecycle actions affect wallet/provider flows.
   - Mitigation: strictly isolate Phase 9B changes to exception artifacts and reconciliation metadata.

## 8. Verification Strategy

### Unit / Service
- Extend exception queue/reconciliation tests for:
  - auto-resolution on reconciled outcome,
  - no resolution on unresolved outcome,
  - source metadata correctness.

### Feature / Console
- Keep reconciliation command tests for:
  - unreconciled failure behavior,
  - convergence behavior with exception lifecycle closure.

### Filament
- Verify:
  - resolve action transitions status and stamps resolution metadata,
  - reopen action restores open state and appends audit note,
  - acknowledge action remains non-destructive.

### Regression-sensitive
- MTN callback integration tests.
- MTN reconciliation command tests.
- Reconciliation exception SLA command tests.

### Static Analysis
- Run focused PHPStan on changed account services, console command (if modified), and Filament resource files.

## 9. Implementation Sequence With Stop/Go Checks

1. Add schema support for lifecycle timestamp (`resolved_at`).
   - **Stop/Go:** migration succeeds and existing tests still bootstrap.
2. Add queue service lifecycle methods (resolve/reopen metadata helpers).
   - **Stop/Go:** unit tests confirm idempotent transition behavior.
3. Wire reconciliation service to auto-resolve exceptions on reconciled outcomes.
   - **Stop/Go:** callback/status/reconcile tests show converged rows and closed exceptions.
4. Add Filament resolve/reopen actions with required notes.
   - **Stop/Go:** Filament tests verify action visibility and lifecycle transitions.
5. Run focused suites + static analysis.
   - **Stop/Go:** all targeted tests green and phpstan clean for changed files.

## 10. Open Questions

1. Should operator resolve action also set a distinct `resolved_by_user_uuid` column in this phase, or is metadata + acknowledgments sufficient for current audit requirements?
2. Should SLA escalations auto-clear on resolution (new field) or remain immutable historical markers only?
