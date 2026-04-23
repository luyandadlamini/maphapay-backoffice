# Minor Accounts Next-Phase Planning + Spec Prompt

## Your Job

You are planning, not implementing.

Produce the **next shippable minor-accounts phase plan and spec** after Phase 9A integrations, using the original initiative as strategic reference and the current backend reality as the source of truth.

Do not write production code in this task. Your deliverable is high-quality planning/spec documentation that another implementation agent can execute with low ambiguity.

---

## Goal

Define the next phase in a way that is:
- operationally safe for fintech money movement,
- consistent with what is already built in this repo,
- explicit about scope, exclusions, dependencies, risks, and acceptance gates.

---

## Read First (in order)

1. `docs/superpowers/plans/2026-04-16-minor-accounts-feature-ages-6-17.md` (original master plan)
2. `docs/superpowers/specs/2026-04-22-minor-accounts-phase9-integrations-spec.md` (Phase 9 framing/spec)
3. `docs/superpowers/plans/2026-04-23-minor-accounts-phase9a-integrations-implementation-plan.md` (what was executed for 9A)
4. `docs/14-TECHNICAL/ADMIN_DASHBOARD.md` (ops/admin constraints)
5. `docs/10-OPERATIONS/MONITORING/monitoring.md` (observability standards)

Then inspect current implementation state for minor-family integrations:
- `app/Domain/Account/Services/MinorFamilyIntegrationService.php`
- `app/Domain/Account/Services/MinorFamilyReconciliationService.php`
- `app/Domain/Account/Services/MinorFamilyReconciliationExceptionQueueService.php`
- `app/Console/Commands/ReconcileMtnMomoTransactions.php`
- `app/Console/Commands/FlagMinorFamilyReconciliationExceptionSlaBreaches.php`
- `app/Http/Controllers/Api/Compatibility/Mtn/CallbackController.php`
- `app/Http/Controllers/Api/Compatibility/Mtn/TransactionStatusController.php`
- Filament resources under `app/Filament/Admin/Resources/MinorFamily*`
- Tests under `tests/Feature/Http/Controllers/Api/*Minor*` and `tests/Feature/Console/*Minor*`

---

## Required Deliverables

Create **two docs**:

1. A plan doc:
   - `docs/superpowers/plans/<DATE>-minor-accounts-phase10-<slug>-implementation-plan.md`
2. A spec doc:
   - `docs/superpowers/specs/<DATE>-minor-accounts-phase10-<slug>-spec.md`

Use today's date in filenames.

---

## What “Next Phase” Means

Use the original phase sequence as guide, but ground the result in current repo state.

You must explicitly decide and justify one of:
- **Phase 10 from original roadmap** (tier automation / lifecycle / compliance automation), or
- a **Phase 9B hardening slice** if there is a safety-critical gap that must be closed first.

If you choose 9B, include a short section titled: **“Why Phase 10 is deferred”** with evidence.

---

## Spec Quality Bar

Your spec must include:

1. **Executive Summary**
   - one-paragraph phase objective
   - why now
   - expected business + risk outcomes

2. **Scope**
   - in-scope capabilities
   - explicit out-of-scope list
   - deferred follow-ons

3. **Preconditions / Dependencies**
   - technical
   - operational
   - compliance/security
   - data/observability

4. **Domain + Data Model Changes**
   - entities/services/events to add or modify
   - migrations and index expectations
   - idempotency and replay guarantees

5. **API Contract Plan**
   - endpoints to add/change/deprecate
   - payload and status models
   - auth/authorization expectations

6. **Operator Workflow / Filament Blueprint**
   - resources/pages/actions needed
   - SLA and escalation handling
   - audit trail requirements

7. **Failure Modes + Risk Register**
   - financial integrity risks
   - abuse vectors
   - callback/provider race conditions
   - manual operations failure paths

8. **Verification Strategy**
   - test matrix (unit/feature/console/integration)
   - reconciliation and callback replay cases
   - regression suites
   - static analysis expectations

9. **Implementation Plan**
   - step-by-step sequencing
   - stop/go checks between steps
   - objective definition of done

10. **Open Questions**
   - only unresolved items that block safe execution

---

## Constraints

- Do not propose broad “future architecture” not needed for this phase.
- Do not bypass existing canonical access control (`MinorAccountAccessService`) or trust workflows.
- Do not introduce financial side effects that are not idempotent and auditable.
- Keep naming consistent with existing minor-family records and MTN transaction linkage.
- Prefer incremental, testable rollout slices over big-bang delivery.

---

## Output Style

When done, provide:
- the two file paths created,
- a concise rationale for chosen next phase (10 vs 9B),
- top 5 execution risks,
- top 5 implementation milestones.

No code changes outside the two planning/spec docs.

