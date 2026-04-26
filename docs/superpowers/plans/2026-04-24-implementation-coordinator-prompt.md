# Implementation Coordinator Prompt

> Paste this entire prompt as the task for a new general-purpose agent.
> The agent should be dispatched from the repo root:
> `/Users/Lihle/Development/Coding/maphapay-backoffice`

---

You are the **implementation coordinator** for a set of compliance, data integrity, and quality fixes derived from an engineering audit of the MaphaPay backoffice (a Laravel 12 / PHP 8.4 / Filament v3 admin panel). Your job is to execute each plan file using **subagent-driven development**: dispatch a fresh implementer subagent per task, then run a spec-compliance review and a code-quality review before marking it done.

## Repo

`/Users/Lihle/Development/Coding/maphapay-backoffice`

- PHP 8.4, Laravel 12, Pest, PHPStan Level 8
- Test DB: MySQL 8 at `127.0.0.1:3306`, database `maphapay_backoffice_test`, user `maphapay_test`, password `maphapay_test_password`
- Run tests: `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test DB_PASSWORD='maphapay_test_password' ./vendor/bin/pest <path> --stop-on-failure`
- Code style: `./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php`
- PHPStan: `XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G`
- Commit style: `fix(P2):` / `feat(P2):` etc. + `Co-Authored-By: Claude <noreply@anthropic.com>`
- All files use `<?php declare(strict_types=1);` and DDD namespace conventions

## Plans to Execute (in this order)

Execute the plans below **sequentially** — each plan is self-contained. Do **not** start the next plan until every task in the current plan passes both reviews.

### Plan 1 — Minor Accounts: Remaining Data Integrity
`docs/superpowers/plans/2026-04-24-minor-accounts-remaining-data-integrity.md`

Covers:
- MINOR-P1-004 remainder: replace `$guarded = []` with `$fillable` on `Account.php`
- MINOR-P2-001: `chunkById` + `lockForUpdate()` in `ExpireMinorSpendApprovals`
- MINOR-P2-003: `MinorAccountLifecycleTransitionStateObserver` for terminal-state enforcement
- MINOR-P2-006: replace `* 30` with `* now()->daysInMonth` in `MinorCardLimit.php`

### Plan 2 — Minor Accounts: Config Externalization
`docs/superpowers/plans/2026-04-24-minor-accounts-config-externalization.md`

Covers:
- MINOR-P2-002: create `config/minor_family.php` with `env()` overrides for all 12+ hardcoded thresholds; update `MinorAccountController` and `ValidateMinorAccountPermission` to use config references

### Plan 3 — Minor Accounts: Compliance & PII
`docs/superpowers/plans/2026-04-24-minor-accounts-compliance-pii.md`

Covers:
- MINOR-P3-003: add `protected $hidden = ['date_of_birth']` to `UserProfile.php`
- MINOR-P3-004: add guardian-side `AccountAuditLog` entry in `updatePermissionLevel()`
- MINOR-P3-001: create `ChorePolicy`, `RewardPolicy`, `MinorCardPolicy`; replace `abort(403)` calls with `$this->authorize()`

### Plan 4 — Mobile Remaining Features
`docs/superpowers/plans/2026-04-24-mobile-remaining-features.md`

Covers:
- MINOR-P2-004: `GET /api/feature-flags` endpoint over the existing `Feature` model
- MINOR-P2-005: add `lifecycle_status` to `store()` and `updatePermissionLevel()` responses in `MinorAccountController`

### Plan 5 — Revenue Domain Fixes
`docs/superpowers/plans/2026-04-24-revenue-domain-fixes.md`

Covers:
- REVENUE-P1-001: replace `ceil($totalUsd * 1_000_000)` with `bcmul()` in `SmsPricingService`
- REVENUE-P2-002: audit trail for target deletions + soft deletes on `revenue_targets`
- REVENUE-P3-001: replace `Cache::flush()` with tagged cache flush in `RevenuePricingPage`
- REVENUE-P3-002: cross-field `stream_code`/`currency` validation in `RevenueTargetResource`

### Plan 6 — Revenue Tenant Timezone
`docs/superpowers/plans/2026-04-24-revenue-tenant-timezone.md`

Covers:
- REVENUE-P2-001: replace bare `Carbon::now()` with `Carbon::now($this->tenantTimezone())` in `WalletRevenueActivityMetrics` and `RevenuePerformanceOverview`

### Plan 7 — PHPStan Baseline Reduction (Sprint 1 only)
`docs/superpowers/plans/2026-04-24-phpstan-baseline-reduction.md`

**Execute Sprint 1 only** (Steps 1.1–1.6 in the plan): fix the 3 `BelongsTo<..., $this(...)>` return type errors in `MinorCardLimit`, `MinorCardRequest`, and `Card`, eliminate `phpstan-baseline-phase12.neon`, and reduce Minor Account entries in `phpstan-baseline-level8.neon`. Stop after Sprint 1 — later sprints require separate coordination.

---

## How to Execute Each Plan

For every plan, follow this loop:

1. **Read the plan file** once at the start of that plan. Extract all tasks with their full text. Do not make implementer subagents read the plan file — paste the task text into their prompt.

2. **Create a TodoWrite** entry for each task in the plan before dispatching the first subagent.

3. For each task, **dispatch an implementer subagent** (general-purpose agent) with:
   - The full task text (copied verbatim from the plan)
   - The repo path and test command
   - Relevant context: which files to touch, what pattern to follow, any existing method names to verify
   - Instruction to follow TDD (write failing test → confirm red → implement → confirm green → commit)
   - Instruction to report: DONE | DONE_WITH_CONCERNS | BLOCKED | NEEDS_CONTEXT

4. If the implementer asks questions, answer them before they proceed.

5. **After the implementer reports DONE**, dispatch a **spec-compliance reviewer** (general-purpose agent) with:
   - The full task text from the plan
   - The git diff since the task started (`git diff HEAD~1` or the relevant commit range)
   - Ask: "Does the implementation match the spec exactly? List anything missing, anything extra, and any deviation."

6. If spec reviewer flags issues, dispatch the implementer again to fix them. Re-run spec review. Repeat until ✅.

7. **After spec review passes**, dispatch a **code-quality reviewer** (general-purpose agent) with:
   - The git diff
   - The codebase conventions (PHP 8.4, strict types, no comments unless WHY is non-obvious, no over-abstraction)
   - Ask: "Rate the implementation quality. List any issues that must be fixed before this is mergeable."

8. If quality reviewer flags issues, dispatch the implementer to fix. Re-run quality review. Repeat until ✅.

9. **Mark the task complete** in TodoWrite. Move to next task.

10. After all tasks in a plan pass both reviews, move to the next plan.

---

## Important Constraints

- **Never skip a review.** Both spec-compliance and code-quality reviews are required for every task.
- **Never move to the next task** while either review has open issues.
- **Never start on main without a feature branch.** Before implementing Plan 1 Task 1, confirm you are on a branch (or create one: `git checkout -b fix/audit-compliance-2026-04-24`).
- **PHPStan must pass** after every plan's final commit. Run `XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G` before marking a plan complete.
- **php-cs-fixer must be clean** before final commit of each plan.
- If any implementer reports BLOCKED or NEEDS_CONTEXT, resolve the blocker before continuing — never skip a blocked task.
- The PHPStan plan (Plan 7) covers Sprint 1 only. Do not attempt Sprints 2–6.

---

## After All Plans Are Complete

1. Run the full test suite: `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test DB_PASSWORD='maphapay_test_password' ./vendor/bin/pest tests/ --parallel --stop-on-failure`
2. Run PHPStan: `XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G`
3. Run code style: `./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php`
4. Report a summary: which plans completed, which tasks had review loops, any blockers encountered, and the final test/PHPStan status.
