# Execution Pack — Account & Balance Tenancy Canonicalization

Companion to: [`2026-05-16-account-tenancy-canonicalization.md`](2026-05-16-account-tenancy-canonicalization.md)

## How to use this pack

1. Tasks are grouped into **waves**. Within a wave, tasks have no dependency on each other and **can** run in parallel sessions. Between waves, you must wait for the previous wave to finish.
2. Each task lists its **model floor** (cheapest model that can do it safely). Open a new session with that model. Paste the prompt verbatim.
3. The prompt assumes nothing about the new session. It tells the agent to read the plan first, then execute its specific task.
4. After the agent replies `DONE <sha>`, mark it off and move on.
5. If the agent replies `BLOCKED: <reason>`, escalate the model one tier (Haiku→Sonnet, Sonnet→Opus) and re-issue.

### Parallelism note

Tasks within a wave **share the same branch**. If you actually run them in parallel sessions, each session will try to push to `refactor/account-tenancy-canonicalization` and the second one will be rejected on conflict. **Two safe options:**

- **(Easier) Run wave-internal tasks sequentially in separate sessions.** You still gain the cheaper-model cost savings; you only lose wall-clock parallelism.
- **(Faster) Have each parallel session work in its own git worktree + sub-branch**, then merge each back into `refactor/account-tenancy-canonicalization` at the end of the wave. Append the *Worktree variant* block (shown once below) to the task prompt when using this path.

### Worktree variant (append to any task prompt to run truly in parallel)

```
Before starting: this task runs in parallel with siblings. Create an isolated worktree:

  git fetch origin
  git worktree add ../maphapay-backoffice-task-N.M -b refactor/account-tenancy/task-N.M origin/refactor/account-tenancy-canonicalization

cd into that worktree and do all work there. When done, push your sub-branch:

  git push -u origin refactor/account-tenancy/task-N.M

Reply with: DONE <commit-sha> on branch refactor/account-tenancy/task-N.M (do NOT merge into refactor/account-tenancy-canonicalization yourself).
```

The user merges each completed sub-branch at the wave boundary.

---

## Standard prompt preamble (referenced by every task)

Each task prompt expands the placeholder `<PREAMBLE>` to this block. To save scrolling, the preamble is shown once here. Every task prompt below starts with it.

```
Repo: /Users/Lihle/Development/Coding/maphapay-backoffice
Plan: docs/superpowers/plans/2026-05-16-account-tenancy-canonicalization.md
Branch: refactor/account-tenancy-canonicalization (verify checked out, pull first)
PHP: "/Users/Lihle/Library/Application Support/Herd/bin/php"
Test DB env (prepend to pest commands):
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
  DB_PASSWORD='maphapay_test_password'

Hard rules:
- TDD per CLAUDE.md: write the failing test first, prove it fails, then write impl, prove it passes.
- Run php-cs-fixer + phpstan on every file you change BEFORE committing.
- ONE commit per task. Use the exact commit message template from the task body in the plan.
- Do NOT improvise outside the task spec. If something blocks you, reply BLOCKED and stop.
- Do NOT modify files outside the "Files:" list in the task.

Reply format at the end:
  DONE <commit-sha>
or:
  BLOCKED: <one-sentence reason>
```

---

## Wave 1 — Branch bootstrap (1 task, sequential gate)

Must succeed before anything else.

### Task 0.1 — model: **Haiku**

```
<PREAMBLE>

Execute Task 0.1 (Create feature branch) from the plan. Section 6 of the plan.

The branch may already exist (someone may have started). If `git rev-parse refactor/account-tenancy-canonicalization` succeeds, just check it out and pull. If not, create it from latest main.

Reply DONE branch-ready after `git status` shows the right branch and a clean tree. No commit needed for this task.
```

---

## Wave 2 — Baseline verification + factory (3 tasks, parallel-eligible)

These have no dependency on each other. Wave 1 must be done.

### Task 0.2 — model: **Haiku**

```
<PREAMBLE>

Execute Task 0.2 (Verify baseline test suite is green) from the plan. Section 6.

Run only the test command from Task 0.2 Step 1. Capture the final "Tests:" line.

If green: reply DONE baseline-green.
If anything red: reply BLOCKED with the first failing test name. DO NOT attempt to fix — that's outside scope.
```

### Task 0.3 — model: **Haiku**

```
<PREAMBLE>

Execute Task 0.3 (PHPStan + cs-fixer baseline) from the plan. Section 6.

Run both commands. Capture the error count and the "Fixed N of M" line. Reply DONE baseline-static-<phpstan-error-count>-<cs-fixer-fixed-count>. No code changes; this is record-only.
```

### Task 1.5 — model: **Haiku**

```
<PREAMBLE>

Execute Task 1.5 (Add AccountMembership factory if missing) from the plan. Section 7.

1. Check whether `database/factories/AccountMembershipFactory.php` exists.
2. If it exists, reply DONE factory-already-present (no commit).
3. If missing, read `app/Domain/Account/Models/AccountMembership.php` first to confirm the fillable fields and required columns. Then create the factory exactly as shown in Task 1.5 Step 2 of the plan, adapting only field names that the model demands differently.
4. Commit using the template in Task 1.5 Step 4.

Reply DONE <sha>.
```

---

## Wave 3 — Concern + helper tests (2 tasks, parallel-eligible)

Both are failing-test-first tasks for the foundation traits. Wave 2 must be done.

### Task 1.1 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 1.1 (Write failing test for WithAccountTenancy::initializeTenancyForRecord) from the plan. Section 7.

Copy the test code from Task 1.1 Step 1 verbatim into the new test file. Run pest. Confirm it fails with "Class WithAccountTenancy not found".

Commit (no code yet, just the failing test):
  git add tests/Feature/Filament/Admin/Concerns/WithAccountTenancyTest.php
  git commit -m "test(admin): failing tests for WithAccountTenancy concern

  Red phase of TDD; concern is implemented in the next commit.

  Co-Authored-By: Claude <noreply@anthropic.com>"

Reply DONE <sha>.
```

### Task 1.3 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 1.3 (Write failing test for WithTenantContext helper) from the plan. Section 7.

Copy the test code from Task 1.3 Step 1 verbatim. Run pest. Confirm it fails with "Class WithTenantContext not found".

Commit (test only):
  git add tests/Feature/Domain/Shared/Concerns/WithTenantContextTest.php
  git commit -m "test(shared): failing tests for WithTenantContext helper

  Red phase of TDD; trait is implemented in the next commit.

  Co-Authored-By: Claude <noreply@anthropic.com>"

Reply DONE <sha>.
```

---

## Wave 4 — Concern + helper implementations (2 tasks, parallel-eligible)

Each turns the matching Wave-3 failing test green. Wave 3 must be done.

### Task 1.2 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 1.2 (Implement WithAccountTenancy concern) from the plan. Section 7.

Copy the trait code from Task 1.2 Step 1 verbatim into `app/Filament/Admin/Concerns/WithAccountTenancy.php`. Run the test from Task 1.1 — it must pass (3 tests). Run cs-fixer + phpstan. Commit per Task 1.2 Step 4.

Reply DONE <sha>.
```

### Task 1.4 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 1.4 (Implement WithTenantContext helper) from the plan. Section 7.

Copy the trait code from Task 1.4 Step 1 verbatim into `app/Domain/Shared/Concerns/WithTenantContext.php`. Run the test from Task 1.3 — it must pass (3 tests). Run cs-fixer + phpstan. Commit per Task 1.4 Step 4.

Reply DONE <sha>.
```

---

## Wave 5 — Integration smoke for the concern (1 task, sequential)

Depends on Task 1.2.

### Task 1.6 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 1.6 (End-to-end smoke test of the concern) from the plan. Section 7.

The integration test in Task 1.6 may fail if the test environment doesn't have multi-tenant DBs wired. If that happens (look for `UsesTenantConnection::shouldUseDefaultConnection()` returning true under 'testing'), DO NOT skip — instead:
1. Write the test as shown.
2. Document the limitation as a `$this->markTestSkipped(...)` with a clear reason, AND
3. Add a runtime smoke entry to `docs/migration-runs/2026-05-16-concern-smoke.md` describing how Task 7.4 (the cmd:run prod smoke) covers this gap.

If you can't decide, reply BLOCKED: integration-test-needs-opus-design-review.

Otherwise commit per Task 1.6 Step 3. Reply DONE <sha>.
```

---

## Wave 6 — Phase 2 canary test (1 task, sequential gate)

Proves the pattern works on one real Filament page before mass application.

### Task 2.1 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 2.1 (TDD canary: assert ViewAccount initializes tenancy) from the plan. Section 8.

Write the failing test exactly per Task 2.1 Step 1. Run it. Confirm it fails on the Tenancy::initialized assertion (the concern hasn't been applied to ViewAccount yet).

Commit test-only:
  git add tests/Feature/Filament/Admin/Resources/AccountResource/Pages/ViewAccountTenancyTest.php
  git commit -m "test(admin): failing canary for ViewAccount tenancy init

  Red. Phase-2 pattern application turns it green in the next commit.

  Co-Authored-By: Claude <noreply@anthropic.com>"

Reply DONE <sha>.
```

---

## Wave 7 — Apply concern to ViewAccount (1 task, sequential gate)

Proves the pattern. Wave 6 must be done.

### Task 2.2 — model: **Haiku**

```
<PREAMBLE>

Execute Task 2.2 (Apply concern to ViewAccount page) from the plan. Section 8.

Mechanical change:
1. Open `app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php`.
2. Add `use App\Filament\Admin\Concerns\WithAccountTenancy;` to the imports.
3. Inside the class body, add `use WithAccountTenancy;` near the top (before `protected static string $resource = …;`).
4. Add the `mount` method exactly as shown in Task 2.2 Step 1.

Run the canary test from Wave 6 — it must now pass. Run cs-fixer + phpstan on the changed file. Commit per Task 2.2 Step 4.

Reply DONE <sha>.
```

---

## Wave 8 — Apply concern to remaining single-record surfaces (parallel-eligible)

7 sub-tasks. Each touches a different file → independent. Wave 7 must be done (pattern proven). Worktrees recommended if running concurrently.

### Task 2.3 — model: **Haiku**

```
<PREAMBLE>

Execute Task 2.3 from Section 8 of the plan (Apply concern to EditAccount).

1. Check if `app/Filament/Admin/Resources/AccountResource/Pages/EditAccount.php` exists.
2. If it does NOT exist: reply DONE n-a-no-edit-page, no commit.
3. If it does: apply the Section-8 pattern (import, trait, mount with `parent::mount($record); $this->initializeTenancyForRecord($this->record);`). Write a canary test mirroring `ViewAccountTenancyTest.php` — change only the page class name. Run, lint, commit per the Wave 7 pattern.

Reply DONE <sha>.
```

### Task 2.4 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 2.4 from Section 8 (Apply concern to FundAccountPage).

This page is different: account selected via dropdown into `$selectedAccount`. DO NOT call the concern in `mount` — the account isn't selected yet. Instead:

1. Find the form field that sets `$selectedAccount` (likely a Filament `Select` component on an account_uuid).
2. On that Select, add `->afterStateUpdated(function ($state) { if ($state) { $this->initializeTenancyForRecord($this->form->getRecord() ?? \App\Domain\Account\Models\Account::where('uuid', $state)->firstOrFail()); } })`.
3. Apply `use App\Filament\Admin\Concerns\WithAccountTenancy;` and `use WithAccountTenancy;` to the page.

Write a Livewire-based test asserting that selecting an account in the dropdown initializes tenancy to that account's tenant.

If the page's actual form construction makes this approach awkward, reply BLOCKED: fund-page-form-structure-needs-design.

Lint + commit per the Section-8 pattern.

Reply DONE <sha>.
```

### Task 2.5 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 2.5 from Section 8 (Apply concern to AdjustBalancePage).

Same approach as Task 2.4: dropdown selects the account; hook tenancy init into the Select's `afterStateUpdated`. Use Task 2.4's commit as the reference style.

Reply DONE <sha>.
```

### Task 2.6 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 2.6 from Section 8 (Apply concern to TransferBetweenAccountsPage).

CRITICAL: this page has TWO accounts (source + destination) that may belong to DIFFERENT tenants. The credit must run in destination's tenant; the debit must run in source's tenant. Do NOT hold both tenancies open at once.

Strategy:
1. Apply `use App\Domain\Shared\Concerns\WithTenantContext;` to the page (NOT `WithAccountTenancy` — we need the wrap-callback form).
2. In the transfer action handler:
   - `$this->withAccountTenancy($source->uuid, fn () => /* perform debit */);`
   - `$this->withAccountTenancy($destination->uuid, fn () => /* perform credit */);`
3. Add a Livewire test using two `Tenant::factory()->create()` and two memberships to assert both legs land in the correct tenants.

If the page currently delegates to a service that itself does the debit+credit atomically, refactor to two service calls — one per tenant. Document this in the commit message.

If unclear, reply BLOCKED: transfer-page-needs-design.

Lint + commit. Reply DONE <sha>.
```

### Task 2.7 — model: **Haiku**

```
<PREAMBLE>

Execute the Section-8 pattern application for `app/Filament/Admin/Resources/AccountResource/RelationManagers/` — every RelationManager class in that directory.

For each RelationManager:
1. If it extends `RelationManager` and operates on Account records, apply `use WithAccountTenancy` and override the appropriate lifecycle method (try `mount`; if absent, use `boot`).
2. If it operates on a non-Account model (e.g. notes on a user), skip — reply for that file: SKIP non-account.

Write one canary test per RelationManager you modify. Group all changes into one commit if they share semantics, otherwise one commit per file.

Reply DONE <sha-list>.
```

### Task 2.8 — model: **Haiku**

```
<PREAMBLE>

Execute Section-8 pattern for `app/Filament/Admin/Resources/ReconciliationReportResource/Widgets/ReconciliationDiscrepancyWidget.php`.

Filament widgets often receive `$record` via the page they're embedded in. If this widget is record-scoped, apply the concern via `mount()`. If it's a dashboard-mode widget (cross-account), DO NOT apply — flag for Phase 4 instead.

Write a canary test if you apply the concern.

Reply DONE <sha> or DONE flag-for-phase-4 (no commit).
```

### Task 2.9 — model: **Haiku**

```
<PREAMBLE>

Execute Task 2.9 from Section 8 (verify AccountStatsOverview header widget on ViewAccount sees correct tenant).

This is a verification-and-regression-test task, not a code change (Task 2.2 already inits tenancy on ViewAccount, and the widget is a child of that page, so it inherits tenancy).

1. Write a test that seeds an account with a known SZL balance in a tenant DB, opens ViewAccount, asserts the AccountStatsOverview widget displays that exact number.
2. Run, expect green (no code change needed if Task 2.2 is correct).
3. If the test fails, escalate — that's a design gap.

Commit the test-only addition.

Reply DONE <sha>.
```

---

## Wave 9 — User-scoped Filament surfaces (2 tasks, parallel-eligible)

### Task 3.1 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 3.1 from Section 9 (Apply concern to AccountsRelationManager per-row).

This is harder than Wave-8 tasks because the relation manager renders MANY rows (one per user account) and each may live in a different tenant. The pattern: use `WithTenantContext` (not `WithAccountTenancy`) and wrap the balance column's `getStateUsing` in `withAccountTenancy` per row.

Implementation pattern is shown in Task 3.1 Step 2. Performance: O(N) tenant inits per page render. Add a TODO comment pointing to a follow-up issue for batching (no need to actually create the issue here; just leave the breadcrumb).

Write a test with two memberships (two different tenants) and assert both balances display correctly in the relation manager.

Lint + commit. Reply DONE <sha>.
```

### Task 3.2 — model: **Haiku**

```
<PREAMBLE>

Execute Task 3.2 from Section 9 — Section-8 pattern application to ReconciliationDiscrepancyWidget. Skip if already done in Task 2.8.

If not yet done: apply concern via `mount`, add canary test, commit.

Reply DONE <sha> or DONE already-done-in-2.8.
```

---

## Wave 10 — Cross-tenant aggregates (3 tasks, parallel-eligible)

Each rewrites a widget/page that fundamentally cannot live in one tenant context.

### Task 4.1 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 4.1 from Section 10 (Rewrite AccountStatsOverview dashboard mode to iterate tenants).

Spec is fully in the plan. Notable requirements:
- Use `Tenant::on('central')->lazy(100)->each(...)` to iterate without loading all tenants into memory.
- Init Stancl tenancy per tenant in a try/finally.
- Use `is_frozen` column (tenant schema), NOT `frozen` (central schema) — verify the actual column on the tenant accounts table first via `cmd:run` or a local tenant migration.
- Sum BIGINT balances as int, not float (use `(int) AccountBalance::sum('balance')`).
- Optionally cache the result 60s with `Cache::remember`.

Write the failing test exactly as in Task 4.1 Step 1. Then implement Step 2. Lint + commit.

If you cannot verify the `is_frozen` vs `frozen` column locally (no tenant DB in test env), proceed with `is_frozen` and add a docblock note that production verification is required during Task 7.4.

Reply DONE <sha>.
```

### Task 4.2 — model: **Sonnet** (escalate to Opus if Filament custom data source is unfamiliar)

```
<PREAMBLE>

Execute Task 4.2 from Section 10 (Build central directory for ListAccounts).

Pick implementation (b) from the plan: iterate tenants and merge results into a Collection. This is the simpler path; (a) is a follow-up scale optimization.

Override `AccountResource::getEloquentQuery()` to return a query against `AccountMembership` joined with `users`, projecting columns that match the existing list columns. For the balance column, use a per-row tenant init (same pattern as Task 3.1).

If Filament v3's custom data source API is unclear to you (you'd be guessing about `query()` returning a Collection vs a Builder), STOP and reply BLOCKED: filament-custom-query-needs-opus.

Write a failing test before changing code: list-page request shows accounts across two tenants. Implement, test, lint, commit.

Reply DONE <sha>.
```

### Task 4.3 — model: **Haiku**

```
<PREAMBLE>

Execute Task 4.3 from Section 10 (Audit remaining cross-account widgets).

For each of these files (verify they exist):
- `app/Filament/Admin/Widgets/PendingAdjustmentsWidget.php`
- `app/Filament/Admin/Widgets/OperationsStatsOverview.php`

Read the file. If it queries `Account` or `AccountBalance` cross-user (no `$record` scoping), apply the Tenant::on('central')->lazy iteration pattern from Task 4.1.

If it does NOT touch Account/AccountBalance, reply for that file: SKIP no-account-access.

One commit per file modified. If no files needed changes: DONE no-changes-needed.

Reply DONE <sha-list-or-no-changes>.
```

---

## Wave 11 — Manual admin smoke (1 task, sequential)

Depends on Waves 8-10 done. Manual UI verification.

### Task 4.4 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 4.4 from Section 10 (Manual smoke test of admin panel).

1. Boot the admin panel locally (`php artisan serve` + open `http://localhost:8000/admin`).
2. Click through: AccountResource list → ViewAccount → run Freeze action → run Unfreeze → open FundAccountPage → select an account → confirm the balance shown matches what the seeded tenant has → submit a small fund → confirm no 500 errors.
3. Capture screenshots into `docs/superpowers/plans/2026-05-16-account-tenancy-canonicalization-screenshots/` (create dir if missing). Name them `wave-11-step-N-<desc>.png`.

Reply with a summary table:
  | Step | Status |
  |---|---|
  | AccountResource list loads | ok / fail |
  | ViewAccount loads | ok / fail |
  | Freeze works | ok / fail |
  | FundAccountPage loads | ok / fail |
  | Balance display correct | ok / fail |

Commit the screenshots dir:
  git add docs/superpowers/plans/2026-05-16-account-tenancy-canonicalization-screenshots/
  git commit -m "test(admin): manual smoke evidence for Wave 11

  Co-Authored-By: Claude <noreply@anthropic.com>"

If anything is fail, reply BLOCKED with which step.

Reply DONE <sha> + table.
```

---

## Wave 12 — Workflow + CLI remediation (7 tasks, parallel-eligible)

Each task touches a different file. Independent. Use worktrees if running concurrently.

### Task 5.1 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 5.1 from Section 11 (BlockchainWithdrawalActivities).

1. Open `app/Domain/Wallet/Workflows/Activities/BlockchainWithdrawalActivities.php`.
2. Identify the method signatures: which methods take an account uuid as input?
3. Write a failing test that invokes one of those methods outside an HTTP request (e.g. via `dispatch_sync`) and asserts the write lands in the tenant DB (not central).
4. Add `use App\Domain\Shared\Concerns\WithTenantContext;` to the class.
5. Wrap each `DB::table('account_balance_locks')->insert(...)`, `DB::table('transactions')->insert(...)` and similar raw writes in `$this->withAccountTenancy($accountUuid, fn () => /* the write */);`.
6. Run the test; expect green.
7. Lint, commit.

If the test environment cannot exercise the tenant swap (testing env falls back to default), document the limitation in the test and add a smoke-test entry analogous to Task 1.6's pattern.

Reply DONE <sha>.
```

### Task 5.2 — model: **Sonnet**

```
<PREAMBLE>

Same pattern as Task 5.1, applied to `app/Domain/Wallet/Workflows/Activities/BlockchainDepositActivities.php`. Follow Task 5.1's structure verbatim.

Reply DONE <sha>.
```

### Task 5.3 — model: **Sonnet**

```
<PREAMBLE>

Same pattern as Task 5.1, applied to `app/Domain/Lending/Workflows/Activities/LoanApplicationActivities.php` (lines around 30, 89, 142).

Reply DONE <sha>.
```

### Task 5.4 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 5.4 from Section 11 (MigrateLegacyBalances command).

1. Open `app/Console/Commands/MigrateLegacyBalances.php`.
2. Add a `--tenant=<uuid>` option to the command signature. When provided, the command should resolve the tenant and init Stancl tenancy before running its existing logic.
3. When the option is NOT provided, the command should refuse to run with a clear error directing the operator to specify a tenant (or use `--all-tenants` if you add that as a separate iteration helper).
4. Wrap balance creation in `WithTenantContext::withAccountTenancy(...)` or the tenant-init pattern.
5. Write a Pest test for both code paths (with --tenant and without).
6. Lint, commit.

Reply DONE <sha>.
```

### Task 5.5 — model: **Sonnet**

```
<PREAMBLE>

Same as Task 5.4 for `app/Console/Commands/RunLoadTests.php`. Add tenant scoping; refuse to run without explicit tenant context.

Reply DONE <sha>.
```

### Task 5.6 — model: **Sonnet**

```
<PREAMBLE>

Same as Task 5.4 for `app/Console/Commands/RepairOwnerMembership.php`. Add `--tenant=<uuid>` option; wrap writes in `withAccountTenancy`.

Reply DONE <sha>.
```

### Task 5.7 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 5.7 from Section 11 (DemoDataSeeder).

1. Open `database/seeders/DemoDataSeeder.php`.
2. For every `Account::factory()->create()` or related call, wrap in `WithTenantContext::withAccountTenancy(...)`. The seeder must establish a tenant per demo user before creating accounts.
3. Add a smoke test asserting the seeder runs without throwing and the resulting accounts are in tenant DBs.
4. Lint, commit.

Reply DONE <sha>.
```

---

## Wave 13 — Invariant guard (2 tasks, sequential)

Task 6.2 depends on 6.1. Don't run in parallel.

### Task 6.1 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 6.1 from Section 12 (Failing test that writing without tenancy throws).

Copy the test code from Task 6.1 Step 1 verbatim. Run, expect failure (no guard yet).

Commit test-only:
  git add tests/Feature/Domain/Account/TenancyInvariantTest.php
  git commit -m "test(account): failing invariant — writes without tenancy must throw

  Red. Guard impl in next commit.

  Co-Authored-By: Claude <noreply@anthropic.com>"

Reply DONE <sha>.
```

### Task 6.2 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 6.2 from Section 12 (Add guard to Account and AccountBalance).

1. Create `app/Domain/Shared/Traits/RequiresTenantContext.php` exactly as shown in Task 6.2 Step 1.
2. Add `use App\Domain\Shared\Traits\RequiresTenantContext;` and `use RequiresTenantContext;` to:
   - `app/Domain/Account/Models/Account.php`
   - `app/Domain/Account/Models/AccountBalance.php`
3. Run the invariant test from Task 6.1 — must pass.
4. Run the full Pest suite. If anything else fails: those failures are real bugs — code paths that mutate Account without initializing tenancy. DO NOT weaken the guard. Reply BLOCKED with the list of failing tests.
5. If all green: lint, commit.

Reply DONE <sha> or BLOCKED: tests-N-Y-failing-revealing-untenant-write-paths.
```

---

## Wave 14 — Full suite verification (1 task, sequential gate)

Hard gate before any data migration.

### Task 6.3 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 6.3 from Section 12.

Run:
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
  DB_PASSWORD='maphapay_test_password' \
  "$PHP" -d max_execution_time=600 ./vendor/bin/pest --parallel --stop-on-failure

If green: reply DONE suite-green.
If red: reply BLOCKED with the test ID and one-line failure summary. Stop. The data migration must NOT run until the suite is green.

No code changes in this task. No commit.
```

---

## Wave 15 — Build the sweep command (1 task, sequential)

### Task 7.1 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 7.1 from Section 13 (Build SweepOrphanCentralBalancesCommand).

The command must:
- Be named `maphapay:sweep-orphan-central-balances`
- Accept `--apply` flag (default is dry-run)
- Accept `--user=<email|uuid>` to scope to one user (for the canary run)
- Query `central.accounts` joined with `central.account_balances` (use connection 'mysql' explicitly).
- For each result, look up the user's `AccountMembership` (in central) and find the tenant_id.
- Init Stancl tenancy for that tenant.
- Check if `account_balances` for `(membership.account_uuid, asset_code)` already exists in tenant DB.
- If not: build an insert plan with the central balance value.
- With `--apply`: idempotently `AccountBalance::updateOrCreate([…], ['balance' => …])` and zero the central balance row in the same DB transaction (where possible — cross-DB transactions are tricky; document the ordering carefully).
- Without `--apply`: print a table of planned changes (account_uuid, asset_code, amount, src tenant).

Write a Pest test with two-tenant fixture proving:
1. Dry run produces a plan, doesn't mutate.
2. --apply migrates, second run finds nothing to do (idempotent).

Lint, commit.

Reply DONE <sha>.
```

---

## Wave 16 — Production data migration for one user (1 task, sequential, REQUIRES HUMAN APPROVAL)

### Task 7.2 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 7.2 from Section 13 (Apply migration in production for lihledlam@gmail.com).

CRITICAL: this mutates production data. Before running --apply:
1. Run --user=lihledlam@gmail.com WITHOUT --apply first. Print the plan. Reply STOP-FOR-REVIEW with the plan; wait for human confirmation before continuing.
2. Only after the human replies "approved", run with --apply.

After --apply succeeds:
3. Via cmd:run, verify the tenant DB for tenant 4f601144-3214-4921-9451-d8cb69afec67 now has an SZL `account_balances` row with `balance = 12922900`.
4. Verify the central balance row for account a633f32c-… is now 0.
5. Ask the human (lihledlam@gmail.com) to open the mobile app and pull-to-refresh; confirm the balance displays E 129,229.00.

Save the cmd:run outputs of dry-run and apply to:
  docs/migration-runs/2026-05-16-orphan-sweep-user-2-dryrun.txt
  docs/migration-runs/2026-05-16-orphan-sweep-user-2-apply.txt

Commit:
  git add docs/migration-runs/
  git commit -m "ops(migration): runbook for user-2 orphan central balance sweep

  Co-Authored-By: Claude <noreply@anthropic.com>"

Reply DONE <sha> mobile-shows-correct-balance:yes/no.
```

---

## Wave 17 — Production data migration for all users (1 task, REQUIRES HUMAN APPROVAL)

### Task 7.3 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 7.3 from Section 13 (Apply migration for all remaining affected users).

Same approval flow as Task 7.2:
1. Run without --apply; print plan; STOP-FOR-REVIEW.
2. On approval, run with --apply.
3. Save outputs to `docs/migration-runs/2026-05-16-orphan-sweep-all-{dryrun,apply}.txt`.
4. Spot-check 3 random affected users via cmd:run.

Commit the runbook. Reply DONE <sha>.
```

---

## Wave 18 — End-to-end smoke + cron sweep (2 tasks, parallel-eligible)

### Task 7.4 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 7.4 from Section 13 (End-to-end smoke from prod).

For at least 2 users (lihledlam@gmail.com + one other migrated user), perform:
1. Admin opens AccountResource list — note balance.
2. Mobile dashboard — note balance.
3. Admin funds E 1.00 via FundAccountPage.
4. Mobile pulls-to-refresh; assert balance increased by E 1.00.
5. Mobile send-money of E 1.00 to a peer.
6. Admin AccountResource refresh; assert balance decreased by E 1.00.

Document in `docs/migration-runs/2026-05-16-e2e-smoke.md`. Commit. Reply DONE <sha> + pass/fail.
```

### Task 7.5 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 7.5 from Section 13 (Cron sweep for drift detection).

1. In `routes/console.php` (or equivalent), schedule `php artisan maphapay:sweep-orphan-central-balances` (no --apply) to run nightly at e.g. 02:00.
2. Wrap in: if it finds ANY drift, send an alert (use the existing log channel or any monitoring hook in the codebase — find an existing alert pattern, don't invent a new one).
3. Pest test for the schedule registration.
4. Lint, commit.

Reply DONE <sha>.
```

---

## Wave 19 — Decommission (3 tasks, sequential within wave)

Task 8.3 is intentionally a separate 30-day-later task — NOT in this wave.

### Task 8.1 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 8.1 from Section 14 (Rename central tables to legacy).

1. Create the migration exactly as shown in Task 8.1 Step 1.
2. BEFORE committing: run `git grep -nE "DB::connection\\(\\s*'mysql'\\s*\\)->table\\(\\s*'(accounts|account_balances)'\\s*\\)" app/` and `git grep -F "Schema::connection('mysql')->table('accounts')" app/`. If anything matches, those code paths will break — list them and reply BLOCKED: <paths> still reference central tables directly.
3. If grep is clean: run the migration locally (test DB) to confirm it executes. Roll back to leave local clean.
4. Commit. DO NOT push yet — Task 8.2 ships in the same wave.

Reply DONE <sha>.
```

### Task 8.2 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 8.2 from Section 14 (Add CI guard that central tables stay quiet).

1. Create `app/Console/Commands/AssertNoCentralAccountAccessCommand.php` that:
   - Queries `accounts_legacy_pre_canonicalization` row count.
   - Compares against a baseline file at `storage/app/central-legacy-baseline.json` (you write it on first run).
   - Asserts row count hasn't changed. Throws if it has.
2. Schedule nightly in `routes/console.php`.
3. Pest test.
4. Lint, commit.

Reply DONE <sha>.
```

### Task 8.4 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 8.4 from Section 14 (Remove UsesTenantConnection fallback).

1. Open `app/Domain/Shared/Traits/UsesTenantConnection.php`.
2. Change `getConnectionName()` to throw `RuntimeException` if `app(Tenancy::class)->initialized === false` and `app()->environment() !== 'testing'`.
3. Run the full Pest suite. Any failure indicates a code path that still reaches Account without tenancy — fix that path, don't weaken the guard.
4. Lint, commit.

If suite goes red and you can't trivially fix the failing path: reply BLOCKED with the failing tests.

Reply DONE <sha>.

Push the whole Wave-19 chain (Tasks 8.1 + 8.2 + 8.4) at the end.
```

---

## Wave 20 — Documentation (3 tasks, parallel-eligible)

### Task 9.1 — model: **Haiku**

```
<PREAMBLE>

Execute Task 9.1 from Section 15 (Update CLAUDE.md).

Add a `## Multi-Tenancy Contract` section under the existing structure. Required content:
- Tenant DB is canonical for Account and AccountBalance.
- HTTP routes get tenancy from `account.context` middleware (link to ResolveAccountContext.php).
- Non-HTTP code must use `WithTenantContext::withAccountTenancy()`.
- Filament admin uses `WithAccountTenancy` concern on every record page.
- Cross-tenant aggregates iterate `Tenant::on('central')->lazy()`.
- Link: `docs/superpowers/plans/2026-05-16-account-tenancy-canonicalization.md`

Commit. Reply DONE <sha>.
```

### Task 9.2 — model: **Haiku**

```
<PREAMBLE>

Execute Task 9.2 from Section 15 (Write ADR).

Create `docs/architecture/ADR-001-account-tenancy.md` using a standard ADR template:
- Title
- Status: Accepted
- Date: 2026-05-16
- Context (the split-brain bug, root cause)
- Decision (Direction A — tenant canonical)
- Alternatives considered (Direction B, Direction C from the plan)
- Consequences (positive + negative)
- Implementation pointer (link to the plan)

Commit. Reply DONE <sha>.
```

### Task 9.3 — model: **Haiku**

```
<PREAMBLE>

Execute Task 9.3 from Section 15 (Update investigation doc).

Open `docs/INVESTIGATION_BALANCE_BUG_2026_05_16.md`. Append a `## Final Resolution` section pointing to the plan and the merged refactor branch. Mark the original investigation status as "Superseded by refactor; see plan".

Commit. Reply DONE <sha>.
```

---

## Wave 21 — Drop legacy tables (1 task, scheduled +30 days)

DO NOT run within 30 days of Wave 17 completion. Tracker only — set a reminder.

### Task 8.3 — model: **Sonnet**

```
<PREAMBLE>

Execute Task 8.3 from Section 14 (Drop central accounts_legacy tables).

Pre-conditions to verify before running:
- At least 30 days have elapsed since Wave 17's --apply commit.
- `AssertNoCentralAccountAccessCommand` has run nightly with zero drift for that entire window.
- A fresh Laravel Cloud DB snapshot exists and is verified restorable.

Then:
1. Create the drop migration.
2. Run on staging if available.
3. Apply on prod during a scheduled window.
4. Commit, push.

Reply DONE <sha> tables-dropped-at <utc-timestamp>.
```

---

## Wave-by-wave checklist (cut-and-keep)

| Wave | Tasks | Parallel? | Model spread | Gate |
|---|---|---|---|---|
| 1 | 0.1 | n | Haiku | branch ready |
| 2 | 0.2, 0.3, 1.5 | y (3 sessions) | Haiku × 3 | baseline known |
| 3 | 1.1, 1.3 | y (2) | Sonnet × 2 | red tests in place |
| 4 | 1.2, 1.4 | y (2) | Sonnet × 2 | concerns implemented + green |
| 5 | 1.6 | n | Sonnet | integration smoke recorded |
| 6 | 2.1 | n | Sonnet | canary test red |
| 7 | 2.2 | n | Haiku | canary green; pattern proven |
| 8 | 2.3-2.9 | y (up to 7) | Haiku ×5 + Sonnet ×2 | all single-record pages tenant-aware |
| 9 | 3.1, 3.2 | y (2) | Sonnet + Haiku | RelMgr + Reconciliation done |
| 10 | 4.1, 4.2, 4.3 | y (3) | Sonnet ×3 | cross-tenant aggregates correct |
| 11 | 4.4 | n | Sonnet | admin manual smoke evidence captured |
| 12 | 5.1-5.7 | y (up to 7) | Sonnet ×7 | workflow + CLI remediated |
| 13 | 6.1, 6.2 | n | Sonnet ×2 | guard active |
| 14 | 6.3 | n | Sonnet | full suite green |
| 15 | 7.1 | n | Sonnet | sweep command shipped |
| 16 | 7.2 | n | Sonnet | one-user migration verified |
| 17 | 7.3 | n | Sonnet | all users migrated |
| 18 | 7.4, 7.5 | y (2) | Sonnet ×2 | e2e + cron drift detection |
| 19 | 8.1, 8.2, 8.4 | n (chain) | Sonnet ×3 | legacy renamed + guarded + fallback removed |
| 20 | 9.1, 9.2, 9.3 | y (3) | Haiku ×3 | docs done |
| 21 | 8.3 | n (+30d) | Sonnet | legacy dropped |

Total: 33 tasks across 21 waves. Max concurrent: 7 (Wave 8 or 12). Practical concurrent (no worktree pain): 1-2.

## Token cost orientation (rough)

The plan is ~1700 lines; loading it once per session costs ~6-8k input tokens. Multiplied across 33 task sessions ≈ 200-260k input tokens just for context. Two ways to reduce:

1. **Excerpt strategy**: in each prompt, replace "Read sections 0, 1, 2, 3 of the plan" with the actual text of those sections inlined. Doubles each prompt's size but eliminates the agent's read step.
2. **Slim preamble**: drop the requirement to read sections 0-3 for Haiku tasks (they don't need decision context). Keep it for Sonnet tasks that involve judgment.

Apply if budget bites. For first run, leave as-is.
