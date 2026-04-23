# Minor Accounts Phase 10 Tier Lifecycle & Compliance Automation Spec

Date: 2026-04-23

## 1. Executive Summary

Phase 10 delivers lifecycle automation for minor accounts (age-band progression, age-18 transition enforcement, and guardian-lifecycle safety controls) on top of the now-implemented Phase 9A integration safety rails. The timing is now appropriate because MTN-linked minor-family flows already have callback/reconciliation controls, exception artifacting, SLA escalation flags, and operator acknowledgment workflows in place. Business outcomes are reduced manual operations load and faster, safer transition handling; risk outcomes are lower probability of orphaned minor accounts, reduced compliance drift at age thresholds, and stronger auditability for lifecycle decisions with no non-idempotent financial side effects.

## 2. Scope

### In Scope

- Automated age-band lifecycle progression for `minor` accounts (Grow -> Rise progression metadata and guardrail updates).
- Automated age-18 transition controls:
  - transition window generation (T-90, T-60, T-30, T0),
  - KYC readiness checks,
  - automatic freeze when adult takeover requirements are unmet at cutoff.
- Parent/guardian lifecycle enforcement:
  - when the primary guardian account becomes ineligible (freeze/closure), minors move to a safe blocked-review state unless valid co-guardian continuity exists.
- Lifecycle exception queue + SLA model for non-financial lifecycle failures (parallel to current reconciliation exception model).
- Filament operator workflow for lifecycle review, acknowledgment, and resolution.
- Observability and operational metrics for lifecycle jobs, transition outcomes, and exception aging.

### Out of Scope

- New money movement rails or provider integrations.
- Child-funded remittances, international routing, merchant QR flows.
- Re-architecture of existing `MinorAccountAccessService` or trust verification flows.
- Full product redesign of mobile onboarding/UX; this phase is backend and operator-first.
- Broad compliance platform expansion beyond lifecycle-specific controls.

### Deferred Follow-ons

- Automated in-app comms orchestration for each transition milestone (mobile campaign layer).
- Jurisdiction-specific policy branching beyond current Eswatini-first policy assumptions.
- Fully self-serve guardian transfer workflow for contested custody scenarios.

## 3. Preconditions / Dependencies

### Technical

- Phase 9A minor-family integration components are active and green:
  - `MinorFamilyIntegrationService`,
  - `MinorFamilyReconciliationService`,
  - `MinorFamilyReconciliationExceptionQueueService`,
  - `mtn:reconcile-disbursements` and callback/status integration.
- Existing account fields available and reliable for lifecycle decisions:
  - `accounts.type`,
  - `accounts.tier`,
  - `accounts.permission_level`,
  - `accounts.parent_account_id`,
  - owner `users` profile DOB.
- Canonical access checks remain via `MinorAccountAccessService`.

### Operational

- Scheduler reliability for periodic lifecycle commands (same operational standard as existing scheduled reconciliation/SLA commands in `routes/console.php`).
- Ops runbook updates for lifecycle exceptions, including ownership and on-call escalation paths.

### Compliance / Security

- Adult takeover policy at age 18 finalized for required KYC state and freeze behavior.
- Audit retention requirements for lifecycle events confirmed (minimum parity with existing financial audit requirements).

### Data / Observability

- Metrics collector hooks for lifecycle job runs and exception SLA state.
- Dashboard visibility for:
  - transition backlog,
  - blocked transitions,
  - SLA-breached lifecycle exceptions.

## 4. Domain + Data Model Changes

### Entities / Services

- Add `MinorAccountLifecycleTransition` model (new lifecycle control-plane record).
- Add `MinorAccountLifecycleException` model (non-financial lifecycle exception artifact).
- Add `MinorAccountLifecycleService` (orchestration service) with deterministic transition methods.
- Add `MinorAccountLifecyclePolicy` for eligibility and freeze/defer decisions.
- Extend `MinorNotificationService` with lifecycle-specific notification/audit types.

### Event Model

Persisted lifecycle events (past tense, stored):

- `MinorAccountLifecycleTransitionScheduled`
- `MinorAccountLifecycleTransitionBlocked`
- `MinorAccountTierAdvanced`
- `MinorAccountAdultTransitionCompleted`
- `MinorAccountAdultTransitionFrozen`
- `MinorAccountGuardianContinuityBroken`
- `MinorAccountLifecycleExceptionOpened`
- `MinorAccountLifecycleExceptionResolved`

### Migrations / Index Expectations

Add table `minor_account_lifecycle_transitions`:

- keys: `id` (uuid), `tenant_id`, `minor_account_uuid`.
- fields: `transition_type`, `state`, `effective_at`, `executed_at`, `blocked_reason_code`, `metadata`, timestamps.
- indexes:
  - `(tenant_id, minor_account_uuid)`,
  - `(state, effective_at)`,
  - unique `(minor_account_uuid, transition_type, effective_at)` for replay safety.

Add table `minor_account_lifecycle_exceptions`:

- keys: `id` (uuid), `tenant_id`, `minor_account_uuid`, optional `transition_id`.
- fields: `reason_code`, `status`, `source`, `occurrence_count`, `metadata`, `first_seen_at`, `last_seen_at`, `sla_due_at`, `sla_escalated_at`, timestamps.
- indexes:
  - `(status, sla_due_at)`,
  - `(minor_account_uuid, reason_code, status)`,
  - `(tenant_id, status)`.

Optional additive fields (only if absent) on `accounts`:

- `minor_transition_state` (nullable enum-like string),
- `minor_transition_effective_at` (nullable datetime).

### Idempotency / Replay Guarantees

- Lifecycle batch command must be safely rerunnable; no duplicate transition records for the same `(minor_account_uuid, transition_type, effective_at)`.
- Transition execution must be lock-protected (`lockForUpdate` around target account and open transition row).
- Non-financial state transitions are monotonic (`pending -> blocked|completed`; no silent backward jumps).
- Replayed callbacks or rerun cron cycles may increase `occurrence_count` but must not duplicate terminal side effects.

## 5. API Contract Plan

### Add / Change

Add authenticated guardian/operator lifecycle endpoints (read-heavy, controlled mutations):

- `GET /api/accounts/minor/{minorAccountUuid}/lifecycle`
  - returns current tier, transition state, pending milestones, blockers.
- `GET /api/accounts/minor/{minorAccountUuid}/lifecycle/transitions`
  - paginated transition history.
- `POST /api/accounts/minor/{minorAccountUuid}/lifecycle/review-actions`
  - operator or authorized guardian action for approved manual steps (strictly non-financial).

### Response / Status Model

- Standard success envelope with explicit `transition_state`, `blockers`, and `next_actions`.
- Status semantics:
  - `200` read success,
  - `202` accepted asynchronous review action,
  - `409` invalid transition state or stale action,
  - `422` policy rejection.

### Auth / Authorization

- Guardian visibility/edit rules continue to rely on `MinorAccountAccessService`.
- Operator mutation endpoints restricted to admin scopes and audited.
- No unauthenticated lifecycle mutation endpoints.

## 6. Operator Workflow / Filament Blueprint

### Resources / Pages / Actions

- New `MinorAccountLifecycleTransitionResource`
  - list/view transitions, filters by state/type/SLA risk.
- New `MinorAccountLifecycleExceptionResource`
  - list/view exceptions, relation manager for acknowledgments.
- Extend existing minor account resource with lifecycle relation tab.

### Required Actions

- `Acknowledge Lifecycle Exception`
- `Resolve Exception`
- `Mark Manual Verification Complete`
- `Re-run Lifecycle Evaluation` (safe idempotent trigger)

### SLA / Escalation

- Hourly command for lifecycle exception SLA flags (mirroring reconciliation exception pattern).
- Visual SLA state badges (`on_track`, `breached`, `escalated`, `resolved`) in Filament tables.

### Audit Trail

- Every manual operator action creates append-only acknowledgment records.
- Every lifecycle transition decision records actor, source (`scheduler`, `operator`, `api`), and policy reason code.

## 7. Failure Modes + Risk Register

### Financial Integrity Risks

- Indirect financial risk if age-18 freeze logic is inconsistent, allowing spend while non-compliant.
- Guardian continuity failure causing minors to remain active without valid controlling adult.

### Abuse Vectors

- Unauthorized guardian attempting lifecycle mutations outside account membership.
- Manual override misuse in admin UI without reason capture.

### Callback / Provider Race Conditions

- Phase 9A transfer reconciliation and lifecycle freeze could race; freeze must not mutate past financial postings.
- Lifecycle jobs must not alter MTN provider states; separation of concerns required.

### Manual Ops Failure Paths

- Exception acknowledged but not resolved, leading to hidden aging risk.
- Re-run action repeatedly used without fixing root cause.
- Transition remains blocked due to stale user KYC projection.

### Controls

- Strict transition-state machine validations.
- Mandatory operator note on lifecycle exception resolution.
- Exception queue aging alerts and dashboard visibility.

## 8. Verification Strategy

### Test Matrix

- Unit:
  - lifecycle policy decisions,
  - state machine monotonicity,
  - idempotent transition creation.
- Feature/API:
  - lifecycle read endpoints,
  - guardian/operator auth boundaries,
  - invalid transition rejection.
- Console:
  - scheduler command transition generation,
  - lifecycle SLA breach flagging.
- Filament:
  - resource visibility, action gating, acknowledgment history.
- Integration:
  - replay of lifecycle commands,
  - coexistence with MTN reconciliation paths.

### Reconciliation / Replay Cases

- Re-run lifecycle command across identical cutoff window produces no duplicate transitions.
- Phase 9A unresolved exceptions remain visible and unaffected by lifecycle command retries.

### Regression Suites

- Existing minor-family MTN callback and reconciliation tests.
- Existing `MinorAccountAccessService` authorization tests.
- Existing monitoring/inspector tests touching minor-family context.

### Static Analysis Expectations

- `phpstan` clean for new lifecycle services/models/resources.
- No new broad suppressions.

## 9. Implementation Plan (Sequenced)

1. Baseline and guardrails
   - verify Phase 9A safety suites green.
   - add lifecycle policy constants and reason-code catalog.
2. Data model
   - add lifecycle transition/exception tables and indexes.
   - add additive account fields only if required.
3. Domain service + events
   - implement lifecycle orchestration and stored lifecycle events.
4. Scheduler commands
   - `minor-accounts:lifecycle-evaluate` and lifecycle SLA flag command.
5. API contracts
   - add lifecycle read/action endpoints with strict auth.
6. Filament workflows
   - add lifecycle transition/exception resources + acknowledgment trail.
7. Observability
   - metrics and inspector enrichment for lifecycle state and exception aging.
8. Final hardening
   - full test matrix, static analysis, operational runbook update.

### Stop/Go Gates

- Gate A: migration + policy tests pass.
- Gate B: transition command idempotency proven in tests.
- Gate C: auth and Filament action restrictions proven.
- Gate D: regression suites (Phase 9A + MTN reconciliation) remain green.

### Definition of Done

- All lifecycle transitions are auditable, idempotent, and operator-observable.
- No non-idempotent financial side effects introduced.
- Lifecycle exceptions are SLA-tracked with acknowledgment history.
- CI test matrix and static analysis pass with no unresolved critical findings.

## 10. Open Questions

1. What exact KYC status is required to avoid age-18 freeze (`approved` only, or configurable allowlist)?
2. Should co-guardian continuity automatically unblock lifecycle freezes, or require explicit operator review?
3. Are guardian freeze and guardian closure treated identically for minor continuity policy?
4. What is the exact default SLA for lifecycle exceptions (reuse 24h or define stricter threshold)?
5. Do product/compliance require immutable notification to both guardian and child on every lifecycle-blocked state?

