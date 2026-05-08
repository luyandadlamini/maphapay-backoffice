# MaphaPay Cards — Implementation Prompt

**Audience:** Any AI coding agent (Claude, Cursor, etc.) picking up implementation of card monetisation. Read this *first*, every session, before touching code.

This document is **timeless**: it does not change as work progresses. The mutable state lives in [`PROGRESS.md`](./PROGRESS.md) (this repo) and the equivalent file in the other repo. Read both at every session start.

This file is byte-identical between `maphapayrn/docs/cards/IMPLEMENTATION_PROMPT.md` and `maphapay-backoffice/docs/cards/IMPLEMENTATION_PROMPT.md`. If you find a divergence, the one with the newer commit wins; bring the other into sync.

---

## 1. Mission

Implement MaphaPay's card monetisation product as specified in this folder's design docs. The product introduces a paid card layer (subscriptions + virtual/physical cards) on top of the existing free wallet, plus a Khula minor card for guardian-managed minors aged 13–17.

The implementation spans two repos:

- **Mobile (React Native + Expo SDK 55):** `/Users/Lihle/Development/Coding/maphapayrn`
- **Backend (Laravel 12 + Filament + Spatie event sourcing):** `/Users/Lihle/Development/Coding/maphapay-backoffice`

You must NOT introduce a parallel card domain on the backend. The existing `app/Domain/CardIssuance/` already provides processor adapters (Demo / Rain / Marqeta) and a `JitFundingService`. Monetisation goes into a new sibling domain `app/Domain/CardSubscriptions/` that depends on it. See [`02-domain-architecture.md`](./02-domain-architecture.md).

You must NOT keep the legacy mobile `mcard` module — it is rewritten from scratch as `src/features/cards/` against `/v1/cards/*` and `/v1/card-subscriptions/*`. Pre-production: no data migration is owed. Phase 0 in both repos demolishes the legacy code.

---

## 2. The doc set is the contract

Every decision an implementer might want to make is already made in these files. Do not re-decide them. If you find a real ambiguity, treat it as a doc bug — fix the doc in the same PR as the code, do not silently diverge.

Read order at session start:

1. This file (`IMPLEMENTATION_PROMPT.md`).
2. [`PROGRESS.md`](./PROGRESS.md) — what's done, what's next, what's blocked.
3. [`README.md`](./README.md) — orientation.
4. [`CONTRACT.md`](./CONTRACT.md) — vocabulary (statuses, error codes, response envelopes). Canonical, byte-identical with the other repo.
5. [`01-product-config.md`](./01-product-config.md) — plan matrix, fees, formulas. Canonical.
6. The phase-relevant docs for the task you're picking up. For backend, that's typically `02–08` and `09-implementation-phases.md`. For mobile, `02–04` and `05-implementation-phases.md`.
7. [`10-non-negotiables.md`](./10-non-negotiables.md) (backend) / [`06-non-negotiables.md`](./06-non-negotiables.md) (mobile) — hard rules. Read last so they're top-of-mind during implementation.

**You do not need to read every doc end-to-end every session.** You DO need to read this file, `PROGRESS.md`, `CONTRACT.md`, and the docs for the current phase.

---

## 3. Session protocol

### 3.1 Session start

Run these in order. Do not skip.

```bash
# 1. Where are we?
git status
git log --oneline -20

# 2. What's the current state?
# Read PROGRESS.md in this repo AND the other repo.
```

Find the **first task in `PROGRESS.md` whose status is `pending`** AND whose dependencies are all `done`. That is your starting task.

If a task is `in_progress`, that means a previous session stopped mid-task. Read the task's notes block in `PROGRESS.md` for what was done; verify by inspection (`git log`, file contents); decide:

- **Resume:** continue from where the notes say. Do NOT restart from scratch.
- **Restart:** if the partial work is unrecoverable (rare), revert it (`git revert`, NOT `git reset --hard`) and reset the task to `pending`.

If a task is `blocked`, do NOT touch it. Read the blocker note. Pick the next non-blocked task or stop the session and surface the blocker to the user.

Announce your starting state to the user in one paragraph: "Resuming on Phase X, Task Y. Previous session left it at Z. I will do A, B, C this session."

### 3.2 Working on a task

For each task you start:

1. Mark it `in_progress` in `PROGRESS.md`, set `started_at` to today's UTC date, commit the progress update first (`docs(cards): mark phase-X task-Y in_progress`). Yes, that is a separate commit. It's a 2-line change; commit cost is trivial.
2. Implement per the relevant doc. Do not deviate. If the doc is wrong, update the doc in the same commit as the implementation (the doc is part of the artifact).
3. Run the listed tests (`vendor/bin/pest path/to/test.php` for backend, `node --test path/to/test.mjs` for mobile).
4. If tests pass: write the task as `done` in `PROGRESS.md` with `completed_at`, `commit` (the SHA), and a 1-line summary in `notes`.
5. Commit the implementation + progress update together: `feat(cards): <what> [phase-X task-Y]`.
6. Move to the next task or end the session (see §3.4).

### 3.3 Quality gates per task

A task is NOT `done` until ALL apply:

- All tests listed for the task in the phase doc pass.
- TypeScript / PHP static analysis is clean (`npx tsc --noEmit` for mobile; `vendor/bin/phpstan analyse` if configured for backend).
- The non-negotiables doc has been re-read; nothing in the change violates it.
- For mobile: `npx fallow audit --format json --quiet --explain` returns `pass` or `warn` (per repo CLAUDE.md).
- For backend: any new migration's `down()` works (verified with `migrate:rollback --pretend`).
- The corresponding `PROGRESS.md` row is updated.

If any gate fails: do NOT mark `done`. Write the failure into the task's `notes` block, leave it `in_progress`, commit progress (`chore(cards): note phase-X task-Y test failure`), stop the session, and report to the user.

### 3.4 Session end

End a session when ANY of these is true:

- A phase boundary is reached (last task of phase done) — always stop here.
- A task is blocked (external dependency, ambiguity in docs, environment issue).
- A quality gate fails and the fix is non-trivial.
- Context is getting thin (you're approaching the conversation's compaction boundary).

Before stopping:

1. Make sure `PROGRESS.md` reflects reality (every task you touched has a status that matches the code).
2. Make sure all changes are committed.
3. Push to the remote (see §6 for remote rules).
4. Write a handoff note in `PROGRESS.md` under the "Handoff log" section (see §3.5).
5. Tell the user in one paragraph: what you did, where you stopped, what's next.

### 3.5 Handoff log

The bottom of `PROGRESS.md` has a `## Handoff log` section. Append an entry every session:

```markdown
### 2026-05-09 — backend session 3 (claude-opus-4-7)

- Completed: phase 3 task 4 (`CardFeeServiceTest::calculateAtmFee`).
- Stopped at: phase 3 task 5 (`previewTransaction`); started, not finished. See task notes for what's left.
- Next session should: read `tests/Feature/Cards/Services/CardFeeServiceTest.php` to see the partial test, finish the previewTransaction implementation in `CardFeeService.php`, run the test, mark task done.
- Quality gates: pest passing on the 4 tasks done; phpstan clean; no non-negotiable violations.
```

Keep entries terse. The point is the next agent (or you, fresh) can re-orient in 2 minutes.

---

## 4. Stop conditions (mandatory pause)

Stop and surface to the user — do NOT proceed — when:

1. **Doc is wrong.** If the doc contradicts itself or the codebase reality (e.g. a referenced file does not exist, a referenced service has a different signature), stop. Do not "best-effort" your way around. Report the discrepancy.

2. **Non-negotiable would be violated.** Reading [`10-non-negotiables.md`](./10-non-negotiables.md) / [`06-non-negotiables.md`](./06-non-negotiables.md) makes you realise an instruction in the doc would create a violation. The non-negotiables WIN. Stop, surface.

3. **Cross-repo coordination required.** If a backend task requires a mobile change that has not been done (or vice versa), stop. Note the dependency in `PROGRESS.md`. Coordinate with the user.

4. **Money-affecting change without test coverage.** Anything that debits/credits a wallet or moves a card to/from `active` state MUST have a test before the production code. If you cannot write the test (e.g. you don't understand the input shape), stop and ask.

5. **Production-shaped operations.** Anything that would touch a non-dev environment (push to a non-feature branch, create a PR against `main` for production, deploy, run migrations on staging/prod) is the user's call. Stop and ask.

6. **Unfamiliar architecture corner.** If you find yourself reading code from a domain unrelated to cards (e.g. `app/Domain/AgentProtocol/...`) to figure out how to make cards work, stop. The domain boundary is a clue that you are about to make a non-card change. Surface it.

---

## 5. Commit conventions

Every commit: conventional-commits prefix + scope + subject. Subject mentions the phase/task tag.

```
<type>(cards): <short subject> [phase-<N> task-<M>]
```

Allowed types:

- `feat(cards):` new functionality.
- `fix(cards):` bug fix.
- `docs(cards):` doc-only change (including `PROGRESS.md` updates).
- `test(cards):` test-only change.
- `refactor(cards):` non-behavioural code restructure.
- `chore(cards):` build, deps, scripts.
- `ci(cards):` CI config.

Examples:

- `feat(cards): add CardEntitlementService scaffolding [phase-2 task-3]`
- `test(cards): cover FX fee formula for non-SZL/ZAR currencies [phase-3 task-4]`
- `docs(cards): mark phase-1 done; add migration commands to handoff log`

Body (when needed): explain the **why**, not the what.

Footer:

```
Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
```

(Adjust the model identifier to whatever the actual agent is.)

### 5.1 What to commit per task

A task's commit batch typically contains:

- The implementation files (services, migrations, components).
- The test files.
- The relevant doc update (if any).
- The `PROGRESS.md` row update.

Do NOT commit:

- Unrelated changes accumulated in the working tree (run `git status` and stage explicitly).
- Files matching `.env*`, secrets, large binaries.
- Changes to other features. If you discover a bug in unrelated code, file it in the user's notes — do not fix it in a card commit.

### 5.2 Branching

Pre-production. Default: commit to `main` directly.

If the user has indicated they want PR review (e.g. by enabling branch protection, or by saying so), switch to a feature branch per phase: `feat/cards-phase-<N>`. Rebase on `main` before opening the PR. Do NOT force-push.

Each agent must verify the current branch on session start and respect the user's existing strategy. If the previous agent created a feature branch, stay on it.

---

## 6. Push / remote rules

- After a commit: `git push origin <branch>` (only if the branch is already tracked).
- Never `git push --force`.
- Never push to `main` of a repo with branch protection without explicit user OK in the chat.
- Pre-production: pushing to `main` is fine if no other agent is pushing concurrently. Run `git pull --rebase` first if behind.
- If a push fails with non-fast-forward: `git pull --rebase`, resolve, push again. Do not `git reset --hard`.

---

## 7. Cross-repo coordination

Any agent works in one repo per session. Both repos have a `PROGRESS.md`. Backend phases lead mobile phases by ~1 step (mobile cannot consume an endpoint that does not exist).

Rule of thumb:

- A backend phase finishing → unlocks the corresponding mobile phase.
- A mobile phase does NOT need to wait for the *next* backend phase to start.
- If a mobile task asks for a backend endpoint that is not yet deployed to dev, it is a `blocked` task. Do not proceed; coordinate.

When you finish a backend phase, leave a clear marker in the **mobile** `PROGRESS.md`:

> Backend phase X done at <commit>; mobile may proceed with phase X mobile-tasks.

Do that by editing the mobile repo's `PROGRESS.md` in a separate commit:

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
# edit docs/cards/PROGRESS.md
git add docs/cards/PROGRESS.md
git commit -m "docs(cards): backend phase 4 ready; unblock mobile phase 4 [cross-repo]"
git push
```

This is the ONE case where a single agent session touches both repos: a coordination signal, no code.

---

## 8. Non-negotiables (read before every implementation)

The full lists are in [`10-non-negotiables.md`](./10-non-negotiables.md) (backend) and [`06-non-negotiables.md`](./06-non-negotiables.md) (mobile). The most operationally dangerous ones to keep top-of-mind:

| Non-negotiable | Why an agent gets this wrong |
|---|---|
| PAN/CVV NEVER in MaphaPay infra | Tempting to "just store last4 + first6 for analytics" — don't. |
| Wallet works without a card | Tempting to add a `card_required` check on a wallet operation when implementing cards — don't. |
| Audit before mutate | Easy to slip into "audit on success" — write the audit row inside the same transaction, BEFORE the state change. |
| `hash_equals` for HMAC | Easy to use `===` and not realise it's timing-leaking. |
| `MoneyConverter` for all amounts | Easy to write `floatval($amount) * 1.03` — never. |
| KYC enum: `VERIFIED` not `'approved'` | Strategy docs and external references say "approved"; the codebase says `VERIFIED`. Use the enum. |
| Models live in `app/Domain/CardIssuance/` and `app/Domain/CardSubscriptions/` | NEVER `app/Models/Card.php`. |
| Mobile feature flags hide UI; backend enforces | Easy to assume "if the flag is off, no one can call the endpoint" — ALWAYS enforce server-side. |

---

## 9. When you finish a phase

A phase is `done` when **every** task inside it is `done`.

At phase end:

1. Run the full test suite for the phase: `vendor/bin/pest tests/Feature/Cards/...` (backend) or `node --test src/features/cards/**/*.test.mjs` (mobile).
2. Run the cross-cutting checks:
   - Backend: `php artisan route:list | grep cards` matches what the API contract says should exist; `php artisan schedule:list` matches what `07-jobs-and-events.md` says.
   - Mobile: `npx tsc --noEmit` and `npx fallow audit --format json --quiet --explain`.
3. Verify byte-identicalness of the canonical files:

   ```bash
   diff -q maphapay-backoffice/docs/cards/CONTRACT.md maphapayrn/docs/cards/CONTRACT.md
   diff -q maphapay-backoffice/docs/cards/01-product-config.md maphapayrn/docs/cards/01-product-config.md
   diff -q maphapay-backoffice/docs/cards/04-api-contract.md maphapayrn/docs/cards/03-api-contract.md
   ```

4. Update the `PROGRESS.md` "Phases" section: phase status → `done`, with completion date and the closing commit SHA.
5. If applicable, leave the cross-repo coordination signal (§7).
6. Commit: `chore(cards): close phase <N> [phase-<N> done]`.
7. Stop the session. Tell the user.

---

## 10. When something goes wrong mid-task

You are halfway through implementing a service. Tests don't pass. You don't know why. Your context is filling up.

Do this, in order:

1. STOP coding.
2. Run `git status` and `git diff` to see exactly what you've touched.
3. Decide: is this fixable in 5–10 minutes? If yes, fix and continue. If no:
   - Commit what you have on a `wip/` branch (e.g. `wip/cards-phase-3-task-5-billing-bug`):
     ```bash
     git checkout -b wip/cards-phase-3-task-5-billing-bug
     git add -A
     git commit -m "wip(cards): partial phase-3 task-5 — billing service has X failure"
     git push -u origin wip/cards-phase-3-task-5-billing-bug
     ```
   - Update `PROGRESS.md`: task stays `in_progress`, notes block describes the failure mode and where the wip is.
   - Hand off to the user.

4. If you panic-revert (`git reset`), you LOSE work that the next agent could have used to debug. Don't.

---

## 11. Definition of done for the whole project

The card monetisation initiative is done when:

- All phases in the backend `PROGRESS.md` are `done`.
- All phases in the mobile `PROGRESS.md` are `done`.
- All canonical files diff cleanly between repos.
- Phase 11 (backend pre-launch security audit) checklist is complete.
- The user has explicitly approved each phase boundary.
- A short post-mortem doc is added at `docs/cards/POST-MORTEM.md` listing: gotchas hit during implementation, doc bugs found and fixed, deferred items.

---

## 12. What this prompt is NOT

- It is not a substitute for the design docs. It tells you HOW to work; the docs tell you WHAT to build.
- It is not a permission slip. Anything not explicitly authorised here (or in user instructions) requires asking.
- It is not optional. Every session, every agent. Read it. Follow it.

---

**Last instruction:** at the end of this session, the very last thing you do is leave `PROGRESS.md` in a state such that another agent reading it cold can pick up exactly where you left off in five minutes or less. If they cannot, you have failed the handoff regardless of how much code you wrote.
