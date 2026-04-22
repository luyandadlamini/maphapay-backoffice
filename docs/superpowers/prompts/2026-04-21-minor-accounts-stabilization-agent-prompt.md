# Minor Accounts Stabilization Execution Prompt

## Your Job

You are not continuing feature development.

You are repairing the minor-accounts foundation so phase 8 can be rebuilt on something that is structurally sound.

Your task is to begin executing the stabilization work identified by the audit. Do not jump ahead. Do not resume phase 8. Do not preserve bad abstractions for convenience.

---

## Read First

Before writing any code, read these in order:

1. `docs/review/2026-04-21-minor-accounts-phases-1-8-audit.md`
2. `docs/superpowers/plans/2026-04-21-minor-accounts-stabilization-before-phase8.md`
3. `docs/plans/curious-toasting-kitten.md`

Companion repo:
- Mobile: `/Users/Lihle/Development/Coding/maphapayrn`

Primary repo:
- Backend: `/Users/Lihle/Development/Coding/maphapay-backoffice`

---

## Non-Negotiable Constraints

- Do not continue phase 8 implementation.
- Do not merge or reuse the halted phase 8 backend architecture from `claude/beautiful-rubin-f84de0` as a base.
- Do not invent new parallel DTOs, permission models, or reward models.
- Do not trust client intent where backend enforcement should exist.
- Do not patch tests to compensate for broken ownership or authorization semantics.
- Do not leave placeholder mobile flows calling nonexistent endpoints.
- Use TDD. Write the failing test first, confirm failure, then implement.

This is fintech-grade corrective work. Financial correctness and backend enforcement outrank UI completeness.

---

## What Is Broken

The audit already proved these are real problems:

- Minor account ownership and child identity are contradictory.
- Send-money approval creation happens before idempotency and trust checks.
- Points/chore authorization is structurally wrong.
- Rewards/chore mutations are not safely transactional.
- Mobile and backend contracts drift across account, rewards, and redemptions.
- Mobile phases 6-8 contain flows that backend does not actually support.
- The halted phase 8 backend branch forked the reward/redemption model instead of extending it.

You are fixing the foundation in the existing implementation, not decorating around it.

---

## Execution Order

You must follow this order:

1. Canonicalize minor-account identity and access control.
2. Fix send-money approval ordering and idempotency.
3. Make rewards and chores transactional and auditable.
4. Canonicalize backend/mobile account and reward contracts.
5. Disable or correct unsupported mobile flows.
6. Only after the above, define the real phase 8 rebuild path.

If step 1 is not complete, do not start step 2.

---

## Start Here: Task 1 Only

Begin with **Task 1: Canonical Minor-Account Identity Model** from:

- `docs/superpowers/plans/2026-04-21-minor-accounts-stabilization-before-phase8.md`

Target backend files first:

- `app/Http/Controllers/Api/MinorAccountController.php`
- `app/Http/Middleware/ResolveAccountContext.php`
- `app/Policies/AccountPolicy.php`
- `tests/Feature/MinorAccountIntegrationTest.php`
- `tests/Feature/Http/Middleware/ResolveAccountContextTest.php`
- `tests/Feature/Http/Policies/AccountPolicyTest.php`

Your first deliverable is not a broad refactor. It is a proved, passing fix for one canonical child/guardian identity model.

You must:

1. Write or update failing tests that prove the current contradiction.
2. Run only the targeted tests and confirm failure.
3. Implement one coherent ownership/access model.
4. Re-run the targeted tests and make them pass without test-time data mutation.
5. Summarize the chosen model and why it is canonical.

---

## Required Output Style

When you report progress, include:

- what invariant you are fixing
- which files changed
- which tests failed before
- which tests pass after
- what remains blocked for the next task

Do not report vague status like “foundation improved” or “minor accounts fixed.”

---

## Explicit Anti-Patterns To Reject

Reject and remove these patterns if you encounter them:

- tests that rewrite persisted ownership fields after create
- policy mocks standing in for real authorization proof
- controller-local permission checks that bypass a canonical access primitive
- mobile hooks calling speculative endpoints
- duplicate account role enums or reward contract types
- phase-specific side architectures that fork the real model

---

## Definition Of Done For This First Session

This session is successful only if:

- the minor-account identity model is made coherent in backend code
- targeted tests prove it
- no test patches persisted records to fake child ownership
- the result is ready for Task 2 in the stabilization plan

If you cannot complete Task 1 cleanly, stop and report the blocker precisely. Do not paper over it and move on.

