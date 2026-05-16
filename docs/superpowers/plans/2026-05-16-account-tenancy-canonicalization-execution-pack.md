# Execution Pack — Account & Balance Tenancy Canonicalization

Companion to: [`2026-05-16-account-tenancy-canonicalization.md`](2026-05-16-account-tenancy-canonicalization.md)

## How to use this pack

- Work happens directly on branch `refactor/account-tenancy-canonicalization` off of `main`. No worktrees.
- The 33 tasks in the plan are **bundled into 21 prompts** (one per wave). Each prompt does multiple related tasks in one session — that way you copy/paste 21 times instead of 33, and each session amortizes loading the plan.
- Each prompt lists its **model floor** (cheapest model that can do it safely). Open a session with that model, paste verbatim.
- After the agent replies `DONE`, mark off and move to the next wave.
- If the agent replies `BLOCKED`, escalate one model tier (Haiku → Sonnet → Sonnet → Opus) and re-issue.

Prompts are designed so a fresh session needs nothing from you beyond the paste. Each opens with the same preamble; each ends with explicit reply-format instructions.

## Standard preamble (every prompt starts with this)

```
Repo: /Users/Lihle/Development/Coding/maphapay-backoffice
Plan: docs/superpowers/plans/2026-05-16-account-tenancy-canonicalization.md
Branch: refactor/account-tenancy-canonicalization (off main; create from main if missing, otherwise checkout + pull)
PHP binary: "/Users/Lihle/Library/Application Support/Herd/bin/php"
Test DB env (prepend to every pest invocation):
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
  DB_PASSWORD='maphapay_test_password'

Hard rules (do not deviate):
- TDD per CLAUDE.md: failing test first, prove it fails, then implementation, prove it passes.
- Run php-cs-fixer + phpstan on every changed file BEFORE committing.
- One commit per task in the bundle (so we can bisect later) using the exact message template in each task body.
- After each task's commit, push so subsequent waves see the change.
- Do NOT modify files outside each task's listed "Files:" section.
- If stuck, reply BLOCKED — do not improvise.

Final reply format:
  DONE
    task 0.1 → <commit-sha>
    task 0.2 → <commit-sha>
    ...
or:
  BLOCKED at task <id>: <one-sentence reason>
```

---

## Wave 1 — Branch bootstrap

### Prompt 1/21 (model: **Haiku**) — Task 0.1

```
<PREAMBLE>

Bundle:
- Task 0.1 (Create feature branch)

Read Section 6 of the plan. Execute Task 0.1. If the branch already exists, just check it out and pull. No commit needed for branch creation.

Reply DONE branch-ready or BLOCKED.
```

---

## Wave 2 — Baseline + factory

### Prompt 2/21 (model: **Haiku**) — Tasks 0.2, 0.3, 1.5

```
<PREAMBLE>

Bundle (run in this order):
- Task 0.2 (Verify baseline test suite green) — no commit
- Task 0.3 (PHPStan + cs-fixer baseline) — no commit; record counts
- Task 1.5 (Add AccountMembership factory if missing) — commit only if you create the file

Read Sections 6 and 7 of the plan. Execute each task.

For Task 1.5: first check whether `database/factories/AccountMembershipFactory.php` exists. If yes, skip. If no, read `app/Domain/Account/Models/AccountMembership.php` to get the fillable fields, then create the factory.

Reply:
  DONE
    0.2 → baseline-green (or BLOCKED with first failing test)
    0.3 → phpstan-errors=<N>, cs-fixer-fixes=<M>
    1.5 → <commit-sha or "already-exists">
```

---

## Wave 3 — Foundation traits (red + green, both)

### Prompt 3/21 (model: **Sonnet**) — Tasks 1.1, 1.2, 1.3, 1.4

```
<PREAMBLE>

Bundle (run in this exact order — TDD red/green for two parallel foundation traits):
1. Task 1.1 (Failing test for WithAccountTenancy) — commit red
2. Task 1.3 (Failing test for WithTenantContext) — commit red
3. Task 1.2 (Implement WithAccountTenancy) — commit green
4. Task 1.4 (Implement WithTenantContext) — commit green

Read Section 7 of the plan. Copy code blocks verbatim from each task body.

Commits:
- After step 1: "test(admin): failing tests for WithAccountTenancy concern"
- After step 2: "test(shared): failing tests for WithTenantContext helper"
- After step 3 (Task 1.2 Step 4 template): "feat(admin): add WithAccountTenancy concern for tenant init"
- After step 4 (Task 1.4 Step 4 template): "feat(shared): add WithTenantContext helper for non-HTTP code paths"

All 4 commits include `Co-Authored-By: Claude <noreply@anthropic.com>`.

Reply DONE with 4 SHAs or BLOCKED.
```

---

## Wave 4 — Integration smoke

### Prompt 4/21 (model: **Sonnet**) — Task 1.6

```
<PREAMBLE>

Bundle:
- Task 1.6 (End-to-end smoke test of the concern)

Read Section 7 Task 1.6 of the plan.

Critical: if the test environment's `UsesTenantConnection::shouldUseDefaultConnection()` returns true for 'testing' (likely — see trait at app/Domain/Shared/Traits/UsesTenantConnection.php:73-76), the integration test cannot actually swap DBs. Do not skip silently:
1. Write the test as shown.
2. Wrap the assertions in a `$this->markTestSkipped("...")` with a clear reason if you detect the limitation.
3. Add a smoke-test entry to `docs/migration-runs/2026-05-16-concern-smoke.md` describing how Task 7.4 (the production cmd:run) will cover this gap.

Commit per Task 1.6 Step 3 template.

Reply DONE <sha> or BLOCKED.
```

---

## Wave 5 — Phase 2 canary (red + green proving the pattern)

### Prompt 5/21 (model: **Sonnet**) — Tasks 2.1, 2.2

```
<PREAMBLE>

Bundle (TDD red/green for the Filament pattern):
1. Task 2.1 (Failing canary test for ViewAccount tenancy init) — commit red
2. Task 2.2 (Apply concern to ViewAccount) — commit green

Read Section 8 of the plan including the "Pattern (memorize this)" sub-section. Tasks 2.1 and 2.2 are the proof point that the rest of Wave 6/7 can apply mechanically.

Commits:
- After 2.1: "test(admin): failing canary for ViewAccount tenancy init"
- After 2.2 (Task 2.2 Step 4 template): "fix(admin): initialize tenancy on ViewAccount page"

Reply DONE 2.1=<sha> 2.2=<sha> or BLOCKED.
```

---

## Wave 6 — Mechanical concern application (Haiku-eligible files)

### Prompt 6/21 (model: **Haiku**) — Tasks 2.3, 2.7, 2.8, 2.9

```
<PREAMBLE>

Bundle (apply the same Section-8 pattern to each; commit per file):
1. Task 2.3 (EditAccount — skip if file doesn't exist)
2. Task 2.7 (every RelationManager in app/Filament/Admin/Resources/AccountResource/RelationManagers/ that operates on Account)
3. Task 2.8 (ReconciliationDiscrepancyWidget — skip and flag for Phase 4 if it's a dashboard-mode widget)
4. Task 2.9 (verify-and-test the AccountStatsOverview header widget on ViewAccount)

For each: write the canary test (template = Task 2.1's test, change only the class name), apply the concern via `use WithAccountTenancy` + `mount()` override, run the canary, lint, commit.

Pattern reminder:
  use App\Filament\Admin\Concerns\WithAccountTenancy;
  // inside class body:
  use WithAccountTenancy;
  public function mount(int|string $record): void {
      parent::mount($record);
      $this->initializeTenancyForRecord($this->record);
  }

For 2.7's relation managers that don't extend a class with `mount(int|string)`, use whichever lifecycle method sees `$this->ownerRecord` first.

If any task's file genuinely cannot accept the pattern, reply for that task: SKIP <reason>.

Reply DONE with one line per task showing sha or SKIP.
```

---

## Wave 7 — Concern application with design judgment (FundManagement)

### Prompt 7/21 (model: **Sonnet**) — Tasks 2.4, 2.5, 2.6

```
<PREAMBLE>

Bundle (each is a mutation page where account is selected by dropdown — NOT mount-time):
1. Task 2.4 (FundAccountPage)
2. Task 2.5 (AdjustBalancePage)
3. Task 2.6 (TransferBetweenAccountsPage — TWO accounts, possibly two tenants)

For 2.4 and 2.5: hook tenancy init into the account-Select's `afterStateUpdated` callback, NOT in mount. The account isn't selected at mount time.

For 2.6: use `WithTenantContext::withAccountTenancy()` (not the Filament concern) inside the transfer action handler. Two calls — one wrapping the debit on source's tenant, one wrapping the credit on destination's tenant. Never hold both tenancies open simultaneously.

Each task: write a Livewire test that proves tenancy is initialized correctly for the page action. Lint, one commit per task with the standard commit template (see Section 8 for examples).

If 2.4 or 2.5's form construction makes the afterStateUpdated approach impossible, reply BLOCKED at <task>: fund-page-form-structure-needs-design.

Reply DONE 2.4=<sha> 2.5=<sha> 2.6=<sha> or BLOCKED.
```

---

## Wave 8 — User-scoped surfaces

### Prompt 8/21 (model: **Sonnet**) — Tasks 3.1, 3.2

```
<PREAMBLE>

Bundle:
1. Task 3.1 (AccountsRelationManager per-row tenancy)
2. Task 3.2 (ReconciliationDiscrepancyWidget — skip if already done in Wave 6)

Task 3.1: each row in the relation manager may belong to a different tenant. Wrap the balance column's `getStateUsing` in `$this->withAccountTenancy($record->uuid, fn () => $record->fresh()->getBalance('SZL'))` using `WithTenantContext` trait. Note O(N) tenant inits per page render; add a TODO comment for batching follow-up.

Task 3.2: if Wave 6 already handled it, reply DONE 3.2=already-done. Otherwise apply the standard concern via mount.

Reply DONE 3.1=<sha> 3.2=<sha-or-already-done> or BLOCKED.
```

---

## Wave 9 — Cross-tenant aggregates (easier two)

### Prompt 9/21 (model: **Sonnet**) — Tasks 4.1, 4.3

```
<PREAMBLE>

Bundle:
1. Task 4.1 (Rewrite AccountStatsOverview dashboard mode to iterate tenants)
2. Task 4.3 (Audit + fix or skip PendingAdjustmentsWidget and OperationsStatsOverview)

Task 4.1 spec is fully in Section 10. Key points:
- Use `Tenant::on('central')->lazy(100)->each(fn ($t) => …)` to iterate.
- Init Stancl tenancy per tenant inside the loop with try/finally end.
- Tenant schema uses `is_frozen` (boolean), NOT `frozen`. If you can't verify this locally because the test env lacks tenant DBs, proceed with `is_frozen` and add a docblock TODO that Task 7.4 must verify in prod.
- Sum balances as int: `(int) AccountBalance::query()->where('asset_code', $cur)->sum('balance')`.
- Optionally cache 60s with `Cache::remember`.

Task 4.3: for each of the two widget files (if they exist), grep for `Account::` or `AccountBalance::` calls. If found, apply the same iterate-tenants pattern. If not found, reply SKIP no-account-access for that file.

Commits: one per file modified. Templates per the standard convention.

Reply DONE 4.1=<sha> 4.3=<sha-list-or-no-changes> or BLOCKED.
```

---

## Wave 10 — ListAccounts cross-tenant directory

### Prompt 10/21 (model: **Sonnet**, escalate to Opus if Filament custom data source is unclear) — Task 4.2

```
<PREAMBLE>

Bundle:
- Task 4.2 (Build central directory for ListAccounts)

Read Section 10 Task 4.2. Pick implementation (b) — iterate tenants and merge into a Collection. Implementation (a) is a future scale optimization; out of scope here.

Override `AccountResource::getEloquentQuery()` (or whichever Filament hook returns the table data) so it does NOT call into the tenant-scoped Account model. Instead, build the rows by iterating `Tenant::on('central')->lazy()`, initializing each tenant briefly, fetching Account rows, accumulating into a Collection.

For the balance column, reuse the per-row pattern from Task 3.1.

If Filament v3's custom query/data-source API is unclear to you (you'd be guessing about `query()` returning a Collection vs a Builder), reply BLOCKED: filament-custom-query-needs-opus.

Write a Pest test with two tenants asserting all accounts appear in the list with correct balances. Lint, commit with template.

Reply DONE <sha> or BLOCKED.
```

---

## Wave 11 — Manual admin smoke

### Prompt 11/21 (model: **Sonnet**, requires you-the-human in a browser) — Task 4.4

```
<PREAMBLE>

Bundle:
- Task 4.4 (Manual smoke of admin panel after Waves 6-10)

Read Section 10 Task 4.4. This needs you (the human) at a browser. The agent's job is to:
1. Tell you to start the dev server (`php artisan serve`) and open http://localhost:8000/admin.
2. Walk you through each smoke step.
3. After you report results, capture them into `docs/superpowers/plans/2026-05-16-account-tenancy-canonicalization-screenshots/` (create dir if missing). If you provide screenshots, save them. If not, capture text-summary results.
4. Commit the evidence with the template from Task 4.4.

Reply DONE <sha> with a pass/fail table for each step, or BLOCKED with the failing step.
```

---

## Wave 12 — Workflow + CLI remediation (all in one)

### Prompt 12/21 (model: **Sonnet**) — Tasks 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7

```
<PREAMBLE>

Bundle (each file independent; do in this order, one commit per file):
1. Task 5.1 — BlockchainWithdrawalActivities (lines 197, 282, 296)
2. Task 5.2 — BlockchainDepositActivities
3. Task 5.3 — LoanApplicationActivities (lines 30, 89, 142)
4. Task 5.4 — MigrateLegacyBalances command (add --tenant=<uuid> option)
5. Task 5.5 — RunLoadTests command
6. Task 5.6 — RepairOwnerMembership command (add --tenant=<uuid> option)
7. Task 5.7 — DemoDataSeeder

Read Section 11 of the plan. Pattern for all activities/commands:
- Add `use App\Domain\Shared\Concerns\WithTenantContext;` to the class.
- Wrap each raw `DB::table('accounts'|'account_balances'|'transactions'|'account_balance_locks')->...->insert/update` call in `$this->withAccountTenancy($accountUuid, fn () => /* the write */);`.
- For commands: add `--tenant=<uuid>` option that resolves the tenant and calls `withAccountTenancy` for the duration of the command body.

For each task: write a failing Pest test that proves the write lands in the tenant DB (not central). If the test env can't validate the swap, add a doc note and a runtime smoke entry like Task 1.6 did.

Commit messages: `fix(<domain>): require tenant context for <file>` with the standard co-author footer.

If any single task gets stuck, reply BLOCKED at <task>: <reason>. Do not skip — that task gets escalated and re-issued.

Reply DONE with one SHA per task or BLOCKED.
```

---

## Wave 13 — Invariant guard (red + green)

### Prompt 13/21 (model: **Sonnet**) — Tasks 6.1, 6.2

```
<PREAMBLE>

Bundle:
1. Task 6.1 (Failing test that writes without tenancy throw) — commit red
2. Task 6.2 (Add RequiresTenantContext trait + apply to Account & AccountBalance) — commit green

Read Section 12 of the plan.

Task 6.2 Step 4 will run the full suite. If anything other than 6.1's test goes red, those reds are the bugs this guard is designed to catch — code paths that mutate Account without initializing tenancy. DO NOT weaken the guard. DO NOT widen test exclusions. Reply BLOCKED at task 6.2: tests-X-Y-Z-failing-revealing-missed-write-paths with the list.

Otherwise commit per the standard template.

Reply DONE 6.1=<sha> 6.2=<sha> or BLOCKED.
```

---

## Wave 14 — Full suite verification (hard gate)

### Prompt 14/21 (model: **Sonnet**) — Task 6.3

```
<PREAMBLE>

Bundle:
- Task 6.3 (Run the full Pest suite end-to-end)

Read Section 12 Task 6.3. No code changes, no commits.

If green: reply DONE suite-green. Wave 15 is unblocked.
If red: reply BLOCKED with the first failing test id + 1-line failure summary. STOP. The data migration in Wave 15+ MUST NOT proceed until the suite is green — fix any reds in a follow-up before running Wave 15.
```

---

## Wave 15 — Build the migration command

### Prompt 15/21 (model: **Sonnet**) — Task 7.1

```
<PREAMBLE>

Bundle:
- Task 7.1 (Build SweepOrphanCentralBalancesCommand)

Read Section 13 Task 7.1.

Requirements (from the plan):
- Command name: `maphapay:sweep-orphan-central-balances`
- Options: `--apply` (default dry-run), `--user=<email|uuid>` (scope to one user)
- Joins central.accounts with central.account_balances; for each, resolves AccountMembership for the user (NOTE: membership.account_uuid will likely differ from the central accounts.uuid — match by user_uuid).
- Inits tenancy per tenant; checks if tenant `account_balances` already has a row for (membership.account_uuid, asset_code).
- With --apply: idempotent `AccountBalance::updateOrCreate([...], ['balance' => ...])` AND zero out the central balance row.
- Without --apply: prints a table.

Pest test must prove:
1. Dry run produces correct plan, no mutation.
2. --apply migrates correctly.
3. Re-running --apply is a no-op (idempotent).

Lint, commit with template.

Reply DONE <sha> or BLOCKED.
```

---

## Wave 16 — Production data migration for the affected user (HUMAN APPROVAL REQUIRED)

### Prompt 16/21 (model: **Sonnet**) — Task 7.2

```
<PREAMBLE>

Bundle:
- Task 7.2 (Apply migration in production for lihledlam@gmail.com)

Read Section 13 Task 7.2. This mutates production data.

Mandatory flow:
1. Run --user=lihledlam@gmail.com WITHOUT --apply via:

     "$PHP" vendor/laravel/cloud-cli/builds/cloud cmd:run env-a163f1c0-2c3b-4aef-a936-dfa1f14adc63 \
       --cmd='php artisan maphapay:sweep-orphan-central-balances --user=lihledlam@gmail.com'

2. Print the full plan output.
3. Reply STOP-FOR-REVIEW <plan-summary>. Wait for human to reply "approved" before continuing.
4. On approval, re-run with --apply.
5. Verify via cmd:run that:
   - Tenant DB tenant4f601144-3214-4921-9451-d8cb69afec67 has account_balances row (account_uuid=dcb74026-..., asset_code=SZL, balance=12922900).
   - Central account_balances row for account a633f32c-... is now 0.
6. Tell the human to open the mobile app, pull to refresh, confirm E 129,229.00 displays.

Save full cmd:run output of both dry-run and --apply to:
  docs/migration-runs/2026-05-16-orphan-sweep-user-2-dryrun.txt
  docs/migration-runs/2026-05-16-orphan-sweep-user-2-apply.txt

Commit:
  git add docs/migration-runs/
  git commit -m "ops(migration): runbook for user-2 orphan central balance sweep

  Co-Authored-By: Claude <noreply@anthropic.com>"

Reply DONE <sha> mobile-balance-confirmed=yes/no or BLOCKED.
```

---

## Wave 17 — Production data migration for ALL users (HUMAN APPROVAL REQUIRED)

### Prompt 17/21 (model: **Sonnet**) — Task 7.3

```
<PREAMBLE>

Bundle:
- Task 7.3 (Apply migration for all remaining affected users)

Same approval flow as Wave 16:
1. Dry run without --user, without --apply. Print full plan.
2. STOP-FOR-REVIEW; wait for "approved".
3. Run with --apply.
4. Save outputs to docs/migration-runs/2026-05-16-orphan-sweep-all-{dryrun,apply}.txt.
5. Spot-check 3 random affected users via cmd:run; confirm each tenant DB now has the expected SZL balance row.

Commit runbook with standard template.

Reply DONE <sha> spot-checks=<count-passing>/3 or BLOCKED.
```

---

## Wave 18 — E2E smoke + cron drift detection

### Prompt 18/21 (model: **Sonnet**) — Tasks 7.4, 7.5

```
<PREAMBLE>

Bundle:
1. Task 7.4 (End-to-end smoke from production)
2. Task 7.5 (Cron sweep for drift detection)

Task 7.4 requires the human in a browser + on the mobile app. Walk them through:
- 2 users (lihledlam@gmail.com + one other migrated)
- For each: admin opens AccountResource → notes balance; mobile dashboard → notes balance (must match); admin funds E 1.00 via FundAccountPage; mobile pull-to-refresh → balance up by E 1.00; mobile sends E 1.00 to a peer; admin refreshes → balance down by E 1.00.
- Document each step + result in docs/migration-runs/2026-05-16-e2e-smoke.md.

Task 7.5:
- In routes/console.php (or equivalent scheduling file), schedule the sweep daily at 02:00 with no --apply.
- Find an existing alerting/log channel in the codebase (don't invent a new one); wire it so any nonzero drift triggers an alert.
- Pest test for the schedule registration.

One commit per task. Reply DONE 7.4=<sha> 7.5=<sha> with e2e pass/fail summary, or BLOCKED.
```

---

## Wave 19 — Decommission (rename + guard + remove fallback)

### Prompt 19/21 (model: **Sonnet**) — Tasks 8.1, 8.2, 8.4

```
<PREAMBLE>

Bundle (sequential within session; commit per task; push only at the end):
1. Task 8.1 (Rename central accounts/account_balances → *_legacy_pre_canonicalization)
2. Task 8.2 (AssertNoCentralAccountAccessCommand + schedule)
3. Task 8.4 (Remove UsesTenantConnection silent-fallback behavior)

Read Section 14 of the plan.

Pre-check before Task 8.1 commits:
  git grep -nE "DB::connection\(\s*'mysql'\s*\)->table\(\s*'(accounts|account_balances)'\s*\)" app/
  git grep -F "Schema::connection('mysql')->table('accounts')" app/

If anything matches, reply BLOCKED at 8.1: <paths-still-using-central-tables> — those paths must be migrated to use tenant context FIRST. Do not proceed to rename.

If clean: run the rename migration locally on test DB to confirm it executes, roll back to leave local clean, commit.

Task 8.4 will likely surface latent paths. If `vendor/bin/pest --parallel` goes red after applying 8.4: reply BLOCKED at 8.4 with the failing tests. DO NOT weaken the throw — those tests reveal the bug class this whole plan exists to kill.

After all three pass: push the wave in one push.

Reply DONE 8.1=<sha> 8.2=<sha> 8.4=<sha> or BLOCKED.
```

---

## Wave 20 — Documentation

### Prompt 20/21 (model: **Haiku**) — Tasks 9.1, 9.2, 9.3

```
<PREAMBLE>

Bundle (one commit per doc):
1. Task 9.1 (Add "Multi-Tenancy Contract" section to CLAUDE.md)
2. Task 9.2 (Create docs/architecture/ADR-001-account-tenancy.md)
3. Task 9.3 (Append "Final Resolution" to docs/INVESTIGATION_BALANCE_BUG_2026_05_16.md)

Read Section 15 of the plan. Each task's content requirements are spelled out there. Standard ADR template for 9.2: Title / Status / Date / Context / Decision / Alternatives / Consequences / Implementation pointer.

Commits use `docs:` prefix with the standard co-author footer.

Reply DONE 9.1=<sha> 9.2=<sha> 9.3=<sha> or BLOCKED.
```

---

## Wave 21 — Drop legacy tables (run +30 days after Wave 17 — NOT NOW)

### Prompt 21/21 (model: **Sonnet**) — Task 8.3

```
<PREAMBLE>

Bundle:
- Task 8.3 (DROP central accounts_legacy_pre_canonicalization tables)

PRE-CONDITIONS (refuse to run if any fail; reply BLOCKED):
- At least 30 days have elapsed since the Wave 17 --apply commit was merged to main.
- AssertNoCentralAccountAccessCommand has run nightly with zero drift across the entire 30-day window. Read the recorded baselines + latest results.
- A fresh Laravel Cloud DB snapshot exists and is verified restorable.

If pre-conditions all pass:
1. Create the drop migration (down() restores via a no-op stub with a clear comment, since this is intentionally irreversible).
2. Run on staging if available; if not, schedule a maintenance window with the human.
3. Apply on prod via the standard deploy flow.
4. Commit with template.

Reply DONE <sha> dropped-at <utc-timestamp> or BLOCKED.
```

---

## Cut-and-keep checklist

| # | Wave | Tasks bundled | Model | Gate |
|---|---|---|---|---|
| 1 | Branch bootstrap | 0.1 | Haiku | branch ready |
| 2 | Baseline + factory | 0.2, 0.3, 1.5 | Haiku | baseline known, factory ready |
| 3 | Foundation traits | 1.1, 1.3, 1.2, 1.4 | Sonnet | concerns + helper green |
| 4 | Integration smoke | 1.6 | Sonnet | smoke recorded |
| 5 | Phase 2 canary | 2.1, 2.2 | Sonnet | pattern proven on ViewAccount |
| 6 | Mechanical pattern (Haiku-eligible) | 2.3, 2.7, 2.8, 2.9 | Haiku | misc record pages tenant-aware |
| 7 | FundManagement (judgment) | 2.4, 2.5, 2.6 | Sonnet | mutation pages tenant-aware |
| 8 | User-scoped surfaces | 3.1, 3.2 | Sonnet | RelMgr + Reconciliation |
| 9 | Cross-tenant aggregates (easier) | 4.1, 4.3 | Sonnet | StatsOverview + widgets correct |
| 10 | ListAccounts cross-tenant directory | 4.2 | Sonnet (escalate Opus if needed) | list view cross-tenant |
| 11 | Manual admin smoke | 4.4 | Sonnet + human-in-browser | evidence captured |
| 12 | Workflow + CLI remediation | 5.1-5.7 | Sonnet | all non-HTTP writes tenant-aware |
| 13 | Invariant guard | 6.1, 6.2 | Sonnet | guard active |
| 14 | Full suite gate | 6.3 | Sonnet | suite green |
| 15 | Sweep command | 7.1 | Sonnet | migration tool shipped |
| 16 | Prod migration: user-2 (approval) | 7.2 | Sonnet + human-approve | device confirmed E 129,229.00 |
| 17 | Prod migration: all users (approval) | 7.3 | Sonnet + human-approve | all migrated |
| 18 | E2E smoke + cron drift | 7.4, 7.5 | Sonnet + human-test | drift detection live |
| 19 | Decommission | 8.1, 8.2, 8.4 | Sonnet | central renamed + guarded + fallback removed |
| 20 | Documentation | 9.1, 9.2, 9.3 | Haiku | docs done |
| 21 | Drop legacy (+30 days) | 8.3 | Sonnet | tables dropped |

**21 prompts. Sequential — one wave at a time. No worktrees.**

### Model usage tally

- Haiku: Waves 1, 2, 6, 20 → 4 sessions
- Sonnet: Waves 3, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 21 → 17 sessions
- Opus: 0 (only as escalation fallback)

The expensive part is the 17 Sonnet sessions. If budget bites, two levers (also in the original pack):
1. **Inline plan excerpts**: replace "Read Section X" with the actual section text in the prompt. Bigger prompts, but the agent skips the read step.
2. **Skip plan reading for tasks with full code blocks**: Wave 3 prompt could inline all four code blocks from the plan and tell the agent to type them out without reading.
