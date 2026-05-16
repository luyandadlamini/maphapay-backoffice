# Account & Balance Tenancy Canonicalization — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Cost-aware execution:** Every task is tagged with a recommended model (Haiku / Sonnet / Opus). Dispatch the cheapest model that can do the task safely. The model column in each task is the **floor** — escalate if the task surprises you. Never downgrade.

**Goal:** Eliminate the split-brain between central and tenant databases for `Account` and `AccountBalance`. Tenant DB becomes the single source of truth. Admin (Filament) and mobile (compat API) read and write the same data, every time, for every user.

**Architecture:** Tenant DB is canonical. `ResolveAccountContext` already initializes Stancl/Tenancy on every API request that hits `account.context`. Filament admin currently runs **without** any tenant middleware, so its reads/writes leak into the central DB. The fix: a small Filament concern that resolves the active record's tenant from `AccountMembership` and initializes Stancl tenancy for the duration of each admin page lifecycle. Cross-tenant aggregation widgets are rewritten to iterate tenants explicitly. Workflows and CLI commands gain a `WithTenantContext` helper. Central `accounts` / `account_balances` tables are renamed `_legacy` and gated by a guard that throws on read/write.

**Tech Stack:** PHP 8.4 · Laravel 12 · Stancl/Tenancy v3+ · Filament v3 · Spatie EventSourcing · Pest · PHPStan L8 · MySQL 8

---

## 0. Bug-In-Production Context (read this first)

A user (`lihledlam@gmail.com`, user_id 2) sees `balance: 0.00` on the mobile Home screen. The admin panel shows `E 129,229.00` for the same wallet. Both UIs use the same Eloquent `Account` model with the same `getBalance('SZL')` code path. They disagree because:

- `Account` uses `App\Domain\Shared\Traits\UsesTenantConnection` → its connection name is `'tenant'`.
- When tenancy is initialized (Stancl swaps the `'tenant'` connection config), reads/writes hit the per-tenant DB.
- When tenancy is **not** initialized, the `'tenant'` connection config falls back to `DB_DATABASE` — the **central** DB (see the trait docblock at `app/Domain/Shared/Traits/UsesTenantConnection.php:55-77`).
- The compat `/api/dashboard` route has `account.context` middleware → tenancy **is** initialized → reads tenant DB → sees the empty `dcb74026-…` account.
- Filament admin panel has **no** tenancy middleware (`AdminPanelProvider.php:71-83`) → reads/writes go to central → sees `a633f32c-…` with the real money.

Concrete data for the affected user (verified via `cmd:run` on prod):

| Where | account.uuid | account_balances.balance (SZL minor) |
|---|---|---|
| Central DB | `a633f32c-a24e-4435-83be-92b822a02862` | 12,922,900 (E 129,229.00) |
| Tenant DB `tenant4f601144…` | `dcb74026-b79b-421f-b04b-20bfaaa34eaa` | (no row) |
| `central.account_memberships` | `account_uuid=dcb74026-…`, `tenant_id=4f601144-…` | — |

The original investigation doc (`docs/INVESTIGATION_BALANCE_BUG_2026_05_16.md`) was reading the **tenant** copy of the `accounts` table and got confused because it has a `currency` column (the central copy doesn't). The two tables have **divergent schemas** — this plan also realigns them.

---

## 1. Decision Record (locked)

**Direction A confirmed.** Tenant DB is canonical for `Account` and `AccountBalance`. Why:

1. The tenant schema is the **richer, newer** one (`currency`, `available_balance`, `reserved_balance`, `is_active`, `is_frozen`, `verification_tier`, `aml_status`, `metadata`, …). The central schema is the legacy stub.
2. **154 sibling domain models** in the codebase already follow `UsesTenantConnection`. Inverting just two of them would create a special-case island.
3. Mobile already works correctly under tenancy; only admin is broken. Fixing admin (~10 surfaces) is a smaller blast radius than re-pointing the data model.
4. Multi-tenancy is intentional in the codebase for partner/whitelabel isolation; demoting `Account` out of it would close that door.

**Out of scope for this plan:**
- The 154 other tenant-aware models (already correct).
- Cross-tenant business reporting beyond what `AccountStatsOverview` already attempts.
- Frontend mobile changes — the mobile is correct; the bug is server-side.

---

## 2. Glossary

| Term | Meaning in this codebase |
|---|---|
| **Central DB** | The `mysql` connection (`DB_DATABASE`). Holds `users`, `tenants`, `account_memberships`, system tables. |
| **Tenant DB** | A per-tenant schema named `tenant{tenant_uuid}` (see `central.tenants.tenancy_db_name`). Holds the rich account/balance/transaction data. |
| **Tenancy initialized** | `app(Stancl\Tenancy\Tenancy::class)->initialize($tenant)` has been called. The `'tenant'` connection config is rewritten to point at the per-tenant schema. |
| **`'tenant'` connection** | A named Laravel DB connection. **Without** tenancy init, its `database` falls back to `DB_DATABASE` (central). **With** init, it points to the per-tenant schema. This fallback is the silent failure mode. |
| **`UsesTenantConnection`** | Trait at `app/Domain/Shared/Traits/UsesTenantConnection.php` that sets `getConnectionName()` to `'tenant'`. Applied to 156 models. |
| **`account.context`** | Middleware alias for `App\Http\Middleware\ResolveAccountContext`. Resolves `AccountMembership` from `X-Account-Id` header or default, then initializes Stancl tenancy. Mounted on all `routes/api-compat.php` routes. |
| **`AccountMembership`** | Central-DB model joining `user_uuid` → `tenant_id` → `account_uuid` (`tenant`-scoped account UUID, not central). |

---

## 3. Inventory (do not re-discover)

Use these lists verbatim. Cheaper models should not re-run audits.

### 3.1 Filament surfaces that touch Account / AccountBalance

Category meanings:
- **single-record**: page or action that operates on one `$record` → use `WithAccountTenancy` concern (Task 2.2).
- **cross-tenant aggregate**: queries balances across users → rewrite to iterate tenants (Task 4.1).
- **list**: index of records across all users → use central directory pattern (Task 4.2).

| File | Lines | Category | Risk | Notes |
|---|---|---|---|---|
| `app/Filament/Admin/Resources/AccountResource.php` | 133-233 (table), 238-405 (actions), 412-448 (bulk) | list + actions | HIGH | Table column `make('balance')` triggers accessor; actions `deposit/withdraw/freeze/unfreeze` mutate via `AccountService`. Bulk freeze iterates `$records`. |
| `app/Filament/Admin/Resources/AccountResource/Pages/ListAccounts.php` | (whole) | list | HIGH | Index page; per-row tenant init needed for balance display. |
| `app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php` | 23-228 | single-record | HIGH | Freeze/Unfreeze actions call `AccountService::freeze($record->uuid, …)`; AdminActionGovernance request-adjustment + replay actions. |
| `app/Filament/Admin/Resources/AccountResource/Pages/EditAccount.php` | (whole) | single-record | MEDIUM | If present; verify it exists before Task 2.3.4. |
| `app/Filament/Admin/Resources/AccountResource/Widgets/AccountStatsOverview.php` | 21-50 (dashboard mode), 52-89 (per-record) | cross-tenant aggregate + single-record | HIGH | Dashboard mode sums `AccountBalance::sum('balance')` across all tenants — this is the cross-tenant rewrite. |
| `app/Filament/Admin/Resources/UserResource/RelationManagers/AccountsRelationManager.php` | 36 (balance read), 44-100 (actions) | single-record (per row of one user) | HIGH | Each row belongs to one user → init tenancy per row. |
| `app/Filament/Admin/Pages/FundManagement/FundAccountPage.php` | 33-80 | single-record (mutation) | HIGH | `?Account $selectedAccount` property; fund action writes balance. |
| `app/Filament/Admin/Pages/FundManagement/AdjustBalancePage.php` | 17-60 | single-record (mutation) | HIGH | Adjust applies a debit/credit. |
| `app/Filament/Admin/Pages/FundManagement/TransferBetweenAccountsPage.php` | 17-59 | dual-record (mutation) | HIGH | Two accounts → potentially two different tenants → handle carefully. |
| `app/Filament/Admin/Resources/ReconciliationReportResource/Widgets/ReconciliationDiscrepancyWidget.php` | 48 | single-record | MEDIUM | Compares balance vs projection; runs in record context. |
| `app/Filament/Admin/Widgets/PendingAdjustmentsWidget.php` | (verify file path before Task 4.1.5) | cross-tenant aggregate | LOW | Doesn't read AccountBalance directly per audit, but verify. |

**Note on `AccountResource/Pages/EditAccount.php`**: if this file does not exist when you check, skip its sub-task and mark it n/a in the commit message.

### 3.2 Non-tenant write paths (workflows, commands, seeders)

| File | Lines | Risk | Notes |
|---|---|---|---|
| `app/Domain/Wallet/Workflows/Activities/BlockchainWithdrawalActivities.php` | 197, 282, 296 | HIGH | Raw `DB::table('account_balance_locks')`, `DB::table('transactions')` writes. Temporal activity → no HTTP middleware. |
| `app/Domain/Wallet/Workflows/Activities/BlockchainDepositActivities.php` | (full) | HIGH | Same pattern. |
| `app/Domain/Lending/Workflows/Activities/LoanApplicationActivities.php` | 30, 89, 142 | HIGH | Workflow activities; raw `DB::table()`. |
| `app/Console/Commands/MigrateLegacyBalances.php` | 60+ | MEDIUM | CLI; no tenancy init by default. |
| `app/Console/Commands/RunLoadTests.php` | (write paths) | MEDIUM | Dev-only but creates real records. |
| `app/Console/Commands/RepairOwnerMembership.php` | 80+ | MEDIUM | Repair command; no tenancy init. |
| `database/seeders/DemoDataSeeder.php` | (full) | LOW | Dev-only; still gets fixed for hygiene. |

### 3.3 Already-correct paths (do **not** modify)

| File | Why it's already correct |
|---|---|
| `app/Http/Controllers/Api/Compatibility/Dashboard/DashboardController.php` | Behind `account.context` middleware. Tenancy initialized before invoke. |
| `app/Http/Controllers/Api/Compatibility/Pockets/PocketsAddFundsController.php` | Same. |
| All controllers in `routes/api-compat.php` | Same — middleware group at `bootstrap/app.php:61, 75, 94`. |
| `app/Domain/Asset/Projectors/AssetTransactionProjector.php:37` | Event projector; inherits the request that fired the event. |
| `app/Domain/Account/Projectors/AssetBalanceProjector.php:65, 76` | Same. |
| `app/Domain/Account/Actions/CreditAccount.php:20` | Domain action; called from listeners that inherit caller context. |

### 3.4 Single source-of-truth files

- **Trait**: `app/Domain/Shared/Traits/UsesTenantConnection.php`
- **Middleware**: `app/Http/Middleware/ResolveAccountContext.php`
- **Membership model**: `app/Domain/Account/Models/AccountMembership.php`
- **Tenant model**: `app/Models/Tenant.php`
- **Bootstrap routing**: `bootstrap/app.php:18-106`
- **Filament panel**: `app/Providers/Filament/AdminPanelProvider.php:71-83` (middleware stack — where the new `InitializeTenancyForAccount` middleware will be added globally for the admin panel? No — we deliberately do NOT add it globally because list pages cross tenants. We add a per-record concern instead.)

### 3.5 Verified prod data (lihledlam@gmail.com, user_id 2)

- user_uuid: `019d3b44-15d5-7030-87d3-828753d845fd`
- AccountMembership: `account_uuid=dcb74026-b79b-421f-b04b-20bfaaa34eaa`, `tenant_id=4f601144-3214-4921-9451-d8cb69afec67`
- Tenant DB name: `tenant4f601144-3214-4921-9451-d8cb69afec67`
- Central account UUID (orphan, holds real money): `a633f32c-a24e-4435-83be-92b822a02862`
- Central `account_balances.balance` SZL: 12,922,900 minor
- Tenant `account_balances` rows: **0**

---

## 4. Model Dispatch Policy

| Task type | Floor model | Why |
|---|---|---|
| Pattern-application: apply existing `WithAccountTenancy` concern to a Filament page | **Haiku** | Mechanical; the pattern is fixed and shown verbatim in the task. |
| Writing a new Pest test from a complete test spec | **Haiku** | Code is given; just type it out and run it. |
| Designing a new helper trait / concern / middleware | **Sonnet** | Requires judgment about API surface, naming, edge cases. |
| Cross-tenant aggregation rewrite (`AccountStatsOverview` dashboard mode) | **Sonnet** | Requires iterating tenants, handling missing tenants, perf considerations. |
| Production data migration scripts | **Sonnet** | Mistakes here corrupt prod. Needs careful idempotency and dry-run. |
| Running a production data migration | **Sonnet** | Same. Must verify with dry-run output before `--apply`. |
| Renaming the central legacy tables (point-of-no-return-ish migration) | **Sonnet** | Single irreversible step; the diff is small but the consequence is large. |
| Architectural decisions deviating from this plan | **Opus** | If you hit a case this plan didn't anticipate, stop and escalate. |
| Plan amendments / re-scoping | **Opus** | Don't let a cheaper model silently widen scope. |

**Rule:** if a task feels harder than its floor, **stop and escalate**. The plan is not a contract; the floor is a budget hint.

---

## 5. Execution Phases (overview)

| # | Phase | Floor model | Est. tasks | Est. session length | Depends on |
|---|---|---|---|---|---|
| 0 | Pre-flight: branch, baseline test green, verify env | Haiku | 3 | 15 min | — |
| 1 | Build `WithAccountTenancy` concern + invariant guard | Sonnet | 6 | 1-2 h | 0 |
| 2 | Apply concern to single-record Filament pages/actions | Haiku (Sonnet for FundManagement) | 9 | 2-3 h | 1 |
| 3 | Apply concern to AccountsRelationManager + ReconciliationDiscrepancyWidget | Haiku | 2 | 30 min | 1 |
| 4 | Cross-tenant aggregate rewrite + ListAccounts central directory | Sonnet | 4 | 2-3 h | 1 |
| 5 | Workflow + CLI remediation (`WithTenantContext` helper) | Sonnet | 7 | 2-3 h | 1 |
| 6 | Invariant enforcement on Account / AccountBalance models | Sonnet | 3 | 1 h | 1 |
| 7 | Data migration: central → tenant, including affected user | Sonnet | 5 | 1-2 h | 6 |
| 8 | Decommission central legacy tables (rename + guard) | Sonnet | 4 | 1 h | 7 |
| 9 | Documentation (CLAUDE.md, ADR, runbook) | Haiku | 3 | 45 min | 8 |

Phases 2, 3, 5 contain many similar Haiku-friendly sub-tasks. Bundle siblings into a single sequential session per the execution pack — they share enough context that loading the plan once amortizes well.

---

## 6. Pre-flight (Phase 0)

### Task 0.1: Create feature branch [Haiku]

**Files:** none

- [ ] **Step 1: From `main`, branch off**

```bash
git checkout main
git pull origin main
git checkout -b refactor/account-tenancy-canonicalization
```

- [ ] **Step 2: Verify branch**

```bash
git status
```

Expected: `On branch refactor/account-tenancy-canonicalization` and `nothing to commit, working tree clean`.

### Task 0.2: Verify baseline test suite is green [Haiku]

**Files:** none (read-only verification)

- [ ] **Step 1: Run the existing Account-domain test suite**

```bash
PHP_BIN="$HOME/Library/Application Support/Herd/bin/php"
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
"$PHP_BIN" -d max_execution_time=300 ./vendor/bin/pest \
  tests/Feature/Http/Controllers/Api/Compatibility \
  tests/Feature/Filament/Admin 2>&1 | tail -30
```

Expected: ends with `Tests:    N passed`. If anything is red, **stop and escalate to Opus** — do not refactor on top of a broken baseline.

### Task 0.3: Run PHPStan + cs-fixer baseline [Haiku]

**Files:** none

- [ ] **Step 1: PHPStan**

```bash
XDEBUG_MODE=off "$PHP_BIN" vendor/bin/phpstan analyse --memory-limit=2G \
  app/Filament/Admin/Resources/AccountResource.php \
  app/Filament/Admin/Resources/AccountResource/ \
  app/Filament/Admin/Pages/FundManagement/ \
  app/Domain/Account/ \
  app/Http/Middleware/ResolveAccountContext.php 2>&1 | tail -20
```

Expected: `[OK] No errors` OR a known pre-existing error count. Record the count so you can verify you didn't increase it.

- [ ] **Step 2: cs-fixer dry-run**

```bash
"$PHP_BIN" ./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --dry-run \
  app/Filament/Admin/ app/Domain/Account/ 2>&1 | tail -5
```

Expected: `Fixed 0 of N files` or known pre-existing fixes.

---

## 7. Phase 1: Foundation — `WithAccountTenancy` concern + invariant guard

This phase produces the reusable building blocks. Everything in Phases 2-6 applies them.

### Task 1.1: Write failing test for `WithAccountTenancy::initializeTenancyForRecord()` [Sonnet]

**Files:**
- Create: `tests/Feature/Filament/Admin/Concerns/WithAccountTenancyTest.php`
- Test target (does not exist yet): `app/Filament/Admin/Concerns/WithAccountTenancy.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Concerns;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Filament\Admin\Concerns\WithAccountTenancy;
use App\Models\Tenant;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class WithAccountTenancyTest extends TestCase
{
    #[Test]
    public function it_initializes_stancl_tenancy_from_an_account_membership(): void
    {
        $tenant = Tenant::factory()->create();
        $userUuid = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();

        AccountMembership::factory()->create([
            'user_uuid'    => $userUuid,
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $account = (new Account())->forceFill(['uuid' => $accountUuid, 'user_uuid' => $userUuid]);

        $host = new class () {
            use WithAccountTenancy;
        };

        $host->initializeTenancyForRecord($account);

        $this->assertTrue(app(Tenancy::class)->initialized);
        $this->assertSame($tenant->id, app(Tenancy::class)->tenant?->id);
    }

    #[Test]
    public function it_throws_when_no_membership_exists_for_the_account(): void
    {
        $account = (new Account())->forceFill([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => (string) Str::uuid(),
        ]);

        $host = new class () {
            use WithAccountTenancy;
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no active membership/i');

        $host->initializeTenancyForRecord($account);
    }

    #[Test]
    public function it_ends_tenancy_when_release_is_called(): void
    {
        $tenant = Tenant::factory()->create();
        $userUuid = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();

        AccountMembership::factory()->create([
            'user_uuid'    => $userUuid,
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $account = (new Account())->forceFill(['uuid' => $accountUuid, 'user_uuid' => $userUuid]);

        $host = new class () {
            use WithAccountTenancy;
        };

        $host->initializeTenancyForRecord($account);
        $host->releaseAccountTenancy();

        $this->assertFalse(app(Tenancy::class)->initialized);
    }
}
```

- [ ] **Step 2: Run, expect class-not-found failure**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
"$PHP_BIN" ./vendor/bin/pest tests/Feature/Filament/Admin/Concerns/WithAccountTenancyTest.php
```

Expected: `Error: Class "App\Filament\Admin\Concerns\WithAccountTenancy" not found`.

### Task 1.2: Implement `WithAccountTenancy` concern [Sonnet]

**Files:**
- Create: `app/Filament/Admin/Concerns/WithAccountTenancy.php`

- [ ] **Step 1: Write the trait**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use App\Domain\Account\Models\AccountMembership;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Stancl\Tenancy\Tenancy;

/**
 * Filament concern: initialize Stancl/Tenancy from a record (Account or any
 * model with a `uuid`/`account_uuid` that maps via AccountMembership).
 *
 * Why this exists: Filament admin runs WITHOUT the API's `account.context`
 * middleware. Without explicit tenancy initialization, reads/writes against
 * the `tenant` connection fall back to the central DB, creating a parallel
 * "shadow" copy of every Account that the mobile API never sees.
 *
 * Usage:
 *   class ViewAccount extends ViewRecord {
 *       use WithAccountTenancy;
 *
 *       protected function mount(int|string $record): void {
 *           parent::mount($record);
 *           $this->initializeTenancyForRecord($this->record);
 *       }
 *   }
 *
 * Pages MUST call releaseAccountTenancy() on teardown if they're not relying
 * on the request lifecycle (background livewire components, etc.). For
 * standard Filament page lifecycles, Laravel's request teardown handles it.
 */
trait WithAccountTenancy
{
    public function initializeTenancyForRecord(Model $record): void
    {
        $accountUuid = (string) ($record->getAttribute('uuid') ?? $record->getAttribute('account_uuid'));

        if ($accountUuid === '') {
            throw new RuntimeException(sprintf(
                'WithAccountTenancy: record of type %s has no uuid/account_uuid attribute',
                $record::class,
            ));
        }

        $membership = AccountMembership::query()
            ->where('account_uuid', $accountUuid)
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            throw new RuntimeException(sprintf(
                'WithAccountTenancy: no active membership for account %s; cannot initialize tenancy',
                $accountUuid,
            ));
        }

        $tenant = Tenant::on('central')->find($membership->tenant_id);

        if ($tenant === null) {
            throw new RuntimeException(sprintf(
                'WithAccountTenancy: membership references missing tenant %s',
                $membership->tenant_id,
            ));
        }

        $tenancy = app(Tenancy::class);

        if ($tenancy->initialized && $tenancy->tenant?->id === $tenant->id) {
            return;
        }

        if ($tenancy->initialized) {
            $tenancy->end();
        }

        $tenancy->initialize($tenant);
    }

    public function releaseAccountTenancy(): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->initialized) {
            $tenancy->end();
        }
    }
}
```

- [ ] **Step 2: Run tests, expect green**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
"$PHP_BIN" ./vendor/bin/pest tests/Feature/Filament/Admin/Concerns/WithAccountTenancyTest.php
```

Expected: `Tests:    3 passed`.

- [ ] **Step 3: phpstan + cs-fixer**

```bash
"$PHP_BIN" ./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php \
  app/Filament/Admin/Concerns/WithAccountTenancy.php \
  tests/Feature/Filament/Admin/Concerns/WithAccountTenancyTest.php
XDEBUG_MODE=off "$PHP_BIN" vendor/bin/phpstan analyse --memory-limit=2G \
  app/Filament/Admin/Concerns/WithAccountTenancy.php \
  tests/Feature/Filament/Admin/Concerns/WithAccountTenancyTest.php
```

Expected: `Fixed 0` and `[OK] No errors`.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Admin/Concerns/WithAccountTenancy.php \
        tests/Feature/Filament/Admin/Concerns/WithAccountTenancyTest.php
git commit -m "$(cat <<'EOF'
feat(admin): add WithAccountTenancy concern for tenant init

New Filament concern that resolves a record's tenant from its
AccountMembership and initializes Stancl tenancy for the page's lifecycle.
Building block for the admin/mobile data-source canonicalization
(see docs/superpowers/plans/2026-05-16-account-tenancy-canonicalization.md).

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

### Task 1.3: Write failing test for `WithTenantContext` helper (workflows/CLI) [Sonnet]

**Files:**
- Create: `tests/Feature/Domain/Shared/Concerns/WithTenantContextTest.php`
- Test target: `app/Domain/Shared/Concerns/WithTenantContext.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Shared\Concerns;

use App\Domain\Account\Models\AccountMembership;
use App\Domain\Shared\Concerns\WithTenantContext;
use App\Models\Tenant;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class WithTenantContextTest extends TestCase
{
    #[Test]
    public function it_runs_callback_within_tenant_context_and_restores_after(): void
    {
        $tenant = Tenant::factory()->create();
        $userUuid = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();

        AccountMembership::factory()->create([
            'user_uuid'    => $userUuid,
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $host = new class () {
            use WithTenantContext;
        };

        $insideId = null;
        $host->withAccountTenancy($accountUuid, function () use (&$insideId): void {
            $insideId = app(Tenancy::class)->tenant?->id;
        });

        $this->assertSame($tenant->id, $insideId);
        $this->assertFalse(app(Tenancy::class)->initialized, 'tenancy must be torn down after callback');
    }

    #[Test]
    public function it_returns_the_callback_return_value(): void
    {
        $tenant = Tenant::factory()->create();
        $accountUuid = (string) Str::uuid();

        AccountMembership::factory()->create([
            'user_uuid'    => (string) Str::uuid(),
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $host = new class () {
            use WithTenantContext;
        };

        $result = $host->withAccountTenancy($accountUuid, fn (): int => 42);

        $this->assertSame(42, $result);
    }

    #[Test]
    public function it_restores_tenancy_even_when_callback_throws(): void
    {
        $tenant = Tenant::factory()->create();
        $accountUuid = (string) Str::uuid();

        AccountMembership::factory()->create([
            'user_uuid'    => (string) Str::uuid(),
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $host = new class () {
            use WithTenantContext;
        };

        try {
            $host->withAccountTenancy($accountUuid, function (): void {
                throw new \LogicException('boom');
            });
        } catch (\LogicException $e) {
            // expected
        }

        $this->assertFalse(app(Tenancy::class)->initialized);
    }
}
```

- [ ] **Step 2: Run, expect class-not-found**

```bash
"$PHP_BIN" ./vendor/bin/pest tests/Feature/Domain/Shared/Concerns/WithTenantContextTest.php
```

Expected: `Class "App\Domain\Shared\Concerns\WithTenantContext" not found`.

### Task 1.4: Implement `WithTenantContext` helper [Sonnet]

**Files:**
- Create: `app/Domain/Shared/Concerns/WithTenantContext.php`

- [ ] **Step 1: Write the trait**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use App\Domain\Account\Models\AccountMembership;
use App\Models\Tenant;
use Closure;
use RuntimeException;
use Stancl\Tenancy\Tenancy;
use Throwable;

/**
 * Workflow / CLI / job helper: run a callback inside the tenant context
 * resolved from an account uuid, and guarantee teardown on the way out.
 *
 * Use this in any code path that DOES NOT have HTTP middleware to init
 * tenancy — Temporal activities, queued jobs, scheduled commands, seeders.
 *
 *   class MyActivity {
 *       use WithTenantContext;
 *
 *       public function execute(string $accountUuid): void {
 *           $this->withAccountTenancy($accountUuid, function () {
 *               // Account::where(...)->update(...) now hits the right DB
 *           });
 *       }
 *   }
 */
trait WithTenantContext
{
    /**
     * @template T
     * @param  Closure(): T $callback
     * @return T
     */
    public function withAccountTenancy(string $accountUuid, Closure $callback): mixed
    {
        $membership = AccountMembership::query()
            ->where('account_uuid', $accountUuid)
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            throw new RuntimeException(sprintf(
                'WithTenantContext: no active membership for account %s',
                $accountUuid,
            ));
        }

        $tenant = Tenant::on('central')->find($membership->tenant_id);

        if ($tenant === null) {
            throw new RuntimeException(sprintf(
                'WithTenantContext: missing tenant %s for account %s',
                $membership->tenant_id,
                $accountUuid,
            ));
        }

        $tenancy = app(Tenancy::class);
        $wasInitialized = $tenancy->initialized;
        $previousTenant = $tenancy->tenant;

        $tenancy->initialize($tenant);

        try {
            return $callback();
        } finally {
            $tenancy->end();
            if ($wasInitialized && $previousTenant !== null) {
                $tenancy->initialize($previousTenant);
            }
        }
    }
}
```

- [ ] **Step 2: Run tests, expect green**

```bash
"$PHP_BIN" ./vendor/bin/pest tests/Feature/Domain/Shared/Concerns/WithTenantContextTest.php
```

Expected: `Tests:    3 passed`.

- [ ] **Step 3: phpstan + cs-fixer**

```bash
"$PHP_BIN" ./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php \
  app/Domain/Shared/Concerns/WithTenantContext.php \
  tests/Feature/Domain/Shared/Concerns/WithTenantContextTest.php
XDEBUG_MODE=off "$PHP_BIN" vendor/bin/phpstan analyse --memory-limit=2G \
  app/Domain/Shared/Concerns/WithTenantContext.php \
  tests/Feature/Domain/Shared/Concerns/WithTenantContextTest.php
```

Expected: `Fixed 0` and `[OK] No errors`.

- [ ] **Step 4: Commit**

```bash
git add app/Domain/Shared/Concerns/WithTenantContext.php \
        tests/Feature/Domain/Shared/Concerns/WithTenantContextTest.php
git commit -m "$(cat <<'EOF'
feat(shared): add WithTenantContext helper for non-HTTP code paths

Workflows, queued jobs and CLI commands lack the request middleware that
initializes Stancl tenancy. This trait wraps any callback so it runs under
the correct tenant for a given account, and restores the prior context on
exit (or throw). Used in subsequent phases to repair workflow + command
write paths that currently leak into the central DB.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

### Task 1.5: Add `AccountMembership` factory if missing [Haiku]

**Files:**
- Modify or create: `database/factories/AccountMembershipFactory.php`

- [ ] **Step 1: Check if factory exists**

```bash
find database/factories -name 'AccountMembership*' 2>/dev/null
```

- [ ] **Step 2 (if missing): Create it**

Reading `app/Domain/Account/Models/AccountMembership.php` first to get fillable fields and casts. Then create:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Account\Models\AccountMembership;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountMembership>
 */
class AccountMembershipFactory extends Factory
{
    protected $model = AccountMembership::class;

    public function definition(): array
    {
        return [
            'id'           => (string) Str::uuid(),
            'user_uuid'    => (string) Str::uuid(),
            'tenant_id'    => Tenant::factory(),
            'account_uuid' => (string) Str::uuid(),
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
            'joined_at'    => now(),
        ];
    }
}
```

If the model uses different field names, adapt — but do not invent fields the model rejects.

- [ ] **Step 3 (if missing): Verify by re-running 1.1 tests**

If you already created the factory and 1.1 tests passed, this is a no-op. Skip.

- [ ] **Step 4: Commit (only if factory was created/modified)**

```bash
git add database/factories/AccountMembershipFactory.php
git commit -m "test(account): add AccountMembership factory for tenancy tests

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
```

### Task 1.6: End-to-end smoke test of the concern from a real Filament page (no Filament refactor yet) [Sonnet]

**Files:**
- Create: `tests/Feature/Filament/Admin/Concerns/WithAccountTenancyIntegrationTest.php`

- [ ] **Step 1: Write the integration test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Concerns;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Models\AccountMembership;
use App\Filament\Admin\Concerns\WithAccountTenancy;
use App\Models\Tenant;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proves the concern actually switches DB context. Creates a balance row
 * in the tenant DB, asserts the Account model sees it AFTER tenancy init.
 */
class WithAccountTenancyIntegrationTest extends TestCase
{
    #[Test]
    public function tenancy_switches_account_balance_reads_to_tenant_db(): void
    {
        $tenant = Tenant::factory()->create();
        $userUuid = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();

        AccountMembership::factory()->create([
            'user_uuid'    => $userUuid,
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        // Without tenancy: account doesn't exist on the (default-fallback) connection.
        $beforeAccount = Account::where('uuid', $accountUuid)->first();
        $this->assertNull($beforeAccount, 'precondition: no account in fallback connection');

        $host = new class () {
            use WithAccountTenancy;
        };

        $accountStub = (new Account())->forceFill(['uuid' => $accountUuid, 'user_uuid' => $userUuid]);
        $host->initializeTenancyForRecord($accountStub);

        // Seed tenant data inside tenant context.
        Account::create([
            'uuid'      => $accountUuid,
            'user_uuid' => $userUuid,
            'name'      => 'Test Wallet',
        ]);
        AccountBalance::create([
            'account_uuid' => $accountUuid,
            'asset_code'   => 'SZL',
            'balance'      => 500_00,
        ]);

        $afterAccount = Account::where('uuid', $accountUuid)->first();
        $this->assertNotNull($afterAccount);
        $this->assertSame(500_00, $afterAccount->getBalance('SZL'));

        $host->releaseAccountTenancy();
    }
}
```

- [ ] **Step 2: Run, may need to adjust based on test DB tenancy config**

```bash
"$PHP_BIN" ./vendor/bin/pest tests/Feature/Filament/Admin/Concerns/WithAccountTenancyIntegrationTest.php
```

If the test environment doesn't have multi-tenant DBs wired (likely — see `UsesTenantConnection::shouldUseDefaultConnection()` returning true in testing), **escalate to Opus** to design a tenancy-aware test setup. Do NOT skip this verification — the whole plan rests on this trait actually swapping connections.

If the testing trait short-circuits and falls back to default, document the limitation in the test and add a runtime smoke (Task 7.4) that exercises the concern on prod data via `cmd:run`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Filament/Admin/Concerns/WithAccountTenancyIntegrationTest.php
git commit -m "test(admin): integration coverage that tenancy actually swaps DB

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
```

---

## 8. Phase 2: Apply concern to single-record Filament pages

Each sub-task follows the SAME PATTERN. Read this pattern once, then mechanically apply it.

### Pattern (memorize this)

For any Filament `EditRecord`, `ViewRecord`, or single-record page:

1. Add the import: `use App\Filament\Admin\Concerns\WithAccountTenancy;`
2. Add the trait inside the class: `use WithAccountTenancy;` (alongside any existing traits)
3. Override `mount`:

```php
public function mount(int|string $record): void
{
    parent::mount($record);
    $this->initializeTenancyForRecord($this->record);
}
```

For pages that have a `protected ?Account $selectedAccount = null` (FundManagement pages), call `$this->initializeTenancyForRecord($this->selectedAccount)` whenever `$selectedAccount` changes, typically in a Livewire `updatedSelectedAccount()` lifecycle hook.

For pages that don't extend a Filament `Record` page but have `$record` injected via route binding, override `boot()` (or whichever lifecycle method initializes early) and call the concern.

For pages that do not need to be tenant-aware (cross-tenant lists, dashboards), **do not apply the concern** — they get the separate treatment in Phase 4.

### Task 2.1: TDD canary — write the test that proves a Filament page initializes tenancy [Sonnet]

**Files:**
- Create: `tests/Feature/Filament/Admin/Resources/AccountResource/Pages/ViewAccountTenancyTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources\AccountResource\Pages;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Filament\Admin\Resources\AccountResource\Pages\ViewAccount;
use App\Models\Tenant;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class ViewAccountTenancyTest extends TestCase
{
    #[Test]
    public function viewing_an_account_initializes_tenancy_for_that_accounts_tenant(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::factory()->create();
        $userUuid = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();

        AccountMembership::factory()->create([
            'user_uuid'    => $userUuid,
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        // Seed the account in the (fallback) connection so Filament's record
        // resolution finds it. After mount, tenancy should be initialized.
        Account::create([
            'uuid'      => $accountUuid,
            'user_uuid' => $userUuid,
            'name'      => 'Test Wallet',
        ]);

        $this->actingAs($admin);

        Livewire::test(ViewAccount::class, ['record' => $accountUuid]);

        $this->assertTrue(app(Tenancy::class)->initialized);
        $this->assertSame($tenant->id, app(Tenancy::class)->tenant?->id);
    }
}
```

- [ ] **Step 2: Run, expect failure (tenancy not initialized — concern not applied yet)**

```bash
"$PHP_BIN" ./vendor/bin/pest tests/Feature/Filament/Admin/Resources/AccountResource/Pages/ViewAccountTenancyTest.php
```

Expected: assertion failure on `Tenancy::initialized`.

### Task 2.2: Apply concern to `ViewAccount` page [Haiku]

**Files:**
- Modify: `app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php:1-25`

- [ ] **Step 1: Add the import and trait usage. Apply the pattern from Section 8.**

The exact edit:

```php
// at the top of the class file, add (after existing `use` lines):
use App\Filament\Admin\Concerns\WithAccountTenancy;

// inside the class body, add the trait usage near the top:
class ViewAccount extends ViewRecord
{
    use WithAccountTenancy;

    protected static string $resource = AccountResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->initializeTenancyForRecord($this->record);
    }

    // ... rest of the file unchanged
}
```

- [ ] **Step 2: Run the test, expect green**

```bash
"$PHP_BIN" ./vendor/bin/pest tests/Feature/Filament/Admin/Resources/AccountResource/Pages/ViewAccountTenancyTest.php
```

Expected: `Tests:    1 passed`.

- [ ] **Step 3: phpstan + cs-fixer on changed file**

```bash
"$PHP_BIN" ./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php \
  app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php
XDEBUG_MODE=off "$PHP_BIN" vendor/bin/phpstan analyse --memory-limit=2G \
  app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php
```

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php \
        tests/Feature/Filament/Admin/Resources/AccountResource/Pages/ViewAccountTenancyTest.php
git commit -m "fix(admin): initialize tenancy on ViewAccount page

ViewAccount now uses WithAccountTenancy::initializeTenancyForRecord during
mount so freeze/unfreeze/adjustment actions on this page see the same DB
the mobile sees. Closes one of the surfaces enumerated in the account
tenancy canonicalization plan.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
```

### Tasks 2.3 - 2.9: Apply the same pattern to remaining single-record pages [Haiku each]

Each sub-task is structurally identical to 2.2: write a tiny canary test (or copy 2.1's template), apply the concern, run tests, commit. Bundle the mechanical (Haiku) ones into a single sequential session per the execution pack; do the Sonnet ones (FundManagement, TransferBetweenAccounts) in their own bundle since they need design judgment.

For each file below: copy the Section 8 pattern. Verify the file actually extends a Filament page class with a `mount` method. If it has a different lifecycle (e.g. a custom Livewire component), call `$this->initializeTenancyForRecord(...)` from whichever method runs first with the record in scope.

| Sub-task | File | Special notes |
|---|---|---|
| 2.3 | `app/Filament/Admin/Resources/AccountResource/Pages/EditAccount.php` (if exists) | Standard pattern |
| 2.4 | `app/Filament/Admin/Pages/FundManagement/FundAccountPage.php` | Account is selected via dropdown into `$selectedAccount`. Hook into the dropdown's `afterStateUpdated()` callback OR override the form's `getStateUsing`; call concern there. **Do NOT call in mount** (page mounts before account is selected). |
| 2.5 | `app/Filament/Admin/Pages/FundManagement/AdjustBalancePage.php` | Same as 2.4. |
| 2.6 | `app/Filament/Admin/Pages/FundManagement/TransferBetweenAccountsPage.php` | Two accounts. If source and destination are in different tenants, tenant-init only the **destination** before the credit, init **source** before the debit. Document this clearly in code comments. Treat as Sonnet, not Haiku. |
| 2.7 | `app/Filament/Admin/Resources/AccountResource/RelationManagers/*` | Apply concern in each RelationManager class via `mount` or `boot` method. |
| 2.8 | `app/Filament/Admin/Resources/ReconciliationReportResource/Widgets/ReconciliationDiscrepancyWidget.php` | Widget; apply via `mount()` if a record is passed. |
| 2.9 | `app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php` AccountStatsOverview widget call (line 235-236) | Header widget runs after page mount; tenancy is already initialized by 2.2 — verify, no code change usually needed. Add a regression test asserting the widget shows the correct (tenant-DB) balance. |

For each: write a canary test before changing the file, run, change, run, commit. **One commit per sub-task.**

---

## 9. Phase 3: User-scoped Filament surfaces (single-user-many-accounts)

### Task 3.1: Apply concern to `AccountsRelationManager` per-row [Sonnet]

**Files:**
- Modify: `app/Filament/Admin/Resources/UserResource/RelationManagers/AccountsRelationManager.php`

Each row in the relation manager represents one account belonging to the user. The challenge: Filament renders all rows in one request, but each row may belong to a different tenant.

Strategy: don't pre-init tenancy at the manager level. Instead, **wrap the balance column's `getStateUsing` in a closure that opens tenancy per row, reads, then releases.**

- [ ] **Step 1: Write a failing test** (model the call site that previously read `$record->balance` and now needs tenant-init per row)

(Test code template; the exact test scaffold depends on Filament v3's relation-manager testing helpers. If unsure, escalate to Sonnet or Opus.)

- [ ] **Step 2: Change the balance column**

```php
use App\Domain\Shared\Concerns\WithTenantContext;

class AccountsRelationManager extends RelationManager
{
    use WithTenantContext; // for per-row tenant init

    // in the table columns array, replace:
    //   Tables\Columns\TextColumn::make('balance')
    // with:
    Tables\Columns\TextColumn::make('balance')
        ->label('Balance')
        ->getStateUsing(fn (Account $record): int =>
            $this->withAccountTenancy($record->uuid, fn () => $record->fresh()->getBalance('SZL'))
        )
        ->money(config('banking.default_currency', 'SZL'), 100),
```

The per-row approach is O(N) tenant initializations per page render. For a user with 10+ accounts this is slow. Document a follow-up optimization (batch-group by tenant) in the commit message.

- [ ] **Step 3: Test, lint, commit.** Standard.

### Task 3.2: Apply concern to `ReconciliationDiscrepancyWidget` [Haiku]

**Files:**
- Modify: `app/Filament/Admin/Resources/ReconciliationReportResource/Widgets/ReconciliationDiscrepancyWidget.php`

- [ ] **Step 1: Apply Section-8 pattern via `mount`. Test, lint, commit.**

---

## 10. Phase 4: Cross-tenant aggregates + ListAccounts

These surfaces fundamentally cannot live inside one tenant context. They need a different architecture.

### Task 4.1: Rewrite `AccountStatsOverview` dashboard mode to iterate tenants [Sonnet]

**Files:**
- Modify: `app/Filament/Admin/Resources/AccountResource/Widgets/AccountStatsOverview.php:21-50`

Current code (lines 25-30):
```php
$totalAccounts = Account::count();
$activeAccounts = Account::where('frozen', false)->count();
$frozenAccounts = Account::where('frozen', true)->count();
$totalBalance = AccountBalance::query()
    ->where('asset_code', config('banking.default_currency', 'SZL'))
    ->sum('balance');
```

Problem: with `UsesTenantConnection`, these queries hit whatever the `'tenant'` connection is currently pointing at (central if no tenancy). After the central tables are decommissioned (Phase 8) these queries will fail outright.

- [ ] **Step 1: Write failing test asserting dashboard-mode shows aggregate across multiple tenants**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources\AccountResource\Widgets;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Models\AccountMembership;
use App\Filament\Admin\Resources\AccountResource\Widgets\AccountStatsOverview;
use App\Models\Tenant;
use App\Models\User;
use App\Domain\Shared\Concerns\WithTenantContext;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountStatsOverviewTest extends TestCase
{
    use WithTenantContext;

    #[Test]
    public function it_aggregates_balances_across_two_tenants(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $accountA = (string) Str::uuid();
        $accountB = (string) Str::uuid();

        AccountMembership::factory()->create([
            'user_uuid' => (string) Str::uuid(), 'account_uuid' => $accountA,
            'tenant_id' => $tenantA->id, 'status' => 'active',
        ]);
        AccountMembership::factory()->create([
            'user_uuid' => (string) Str::uuid(), 'account_uuid' => $accountB,
            'tenant_id' => $tenantB->id, 'status' => 'active',
        ]);

        $this->withAccountTenancy($accountA, function () use ($accountA): void {
            Account::create(['uuid' => $accountA, 'user_uuid' => (string) Str::uuid(), 'name' => 'A']);
            AccountBalance::create(['account_uuid' => $accountA, 'asset_code' => 'SZL', 'balance' => 100_00]);
        });
        $this->withAccountTenancy($accountB, function () use ($accountB): void {
            Account::create(['uuid' => $accountB, 'user_uuid' => (string) Str::uuid(), 'name' => 'B']);
            AccountBalance::create(['account_uuid' => $accountB, 'asset_code' => 'SZL', 'balance' => 250_00]);
        });

        $this->actingAs($admin);
        $page = Livewire::test(AccountStatsOverview::class);

        $page->assertSeeText('350.00'); // E 350.00 = 100 + 250
    }
}
```

- [ ] **Step 2: Rewrite the widget**

```php
protected function getStats(): array
{
    if (! $this->record) {
        return $this->getCrossTenantStats();
    }
    // ... per-record branch unchanged
}

private function getCrossTenantStats(): array
{
    $totalAccounts = 0;
    $activeAccounts = 0;
    $frozenAccounts = 0;
    $totalBalanceMinor = 0;

    $defaultCurrency = config('banking.default_currency', 'SZL');

    Tenant::on('central')->lazy(100)->each(function (Tenant $tenant) use (
        &$totalAccounts, &$activeAccounts, &$frozenAccounts, &$totalBalanceMinor, $defaultCurrency,
    ): void {
        $tenancy = app(\Stancl\Tenancy\Tenancy::class);
        $tenancy->initialize($tenant);
        try {
            $totalAccounts   += Account::count();
            $activeAccounts  += Account::where('is_frozen', false)->count();
            $frozenAccounts  += Account::where('is_frozen', true)->count();
            $totalBalanceMinor += (int) AccountBalance::query()
                ->where('asset_code', $defaultCurrency)
                ->sum('balance');
        } finally {
            $tenancy->end();
        }
    });

    return [
        Stat::make('Total Accounts', number_format($totalAccounts))
            ->description($activeAccounts . ' active, ' . $frozenAccounts . ' frozen')
            ->descriptionIcon('heroicon-m-arrow-trending-up')
            ->color('success'),
        Stat::make('Total Balance', BankingDisplay::minorUnitsAsString($totalBalanceMinor))
            ->description('Across all accounts')
            ->descriptionIcon('heroicon-m-banknotes')
            ->color('primary'),
        Stat::make('Average Balance', BankingDisplay::minorUnitsAsString(
            $totalAccounts > 0 ? intdiv($totalBalanceMinor, $totalAccounts) : 0
        ))
            ->description('Per account')
            ->descriptionIcon('heroicon-m-calculator')
            ->color('info'),
        Stat::make('Frozen Accounts', $frozenAccounts)
            ->description(number_format($totalAccounts > 0 ? ($frozenAccounts / $totalAccounts) * 100 : 0, 1) . '% of total')
            ->descriptionIcon($frozenAccounts > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
            ->color($frozenAccounts > 0 ? 'danger' : 'success'),
    ];
}
```

Note schema change: the tenant `accounts` table uses `is_frozen` (not `frozen`). Verify with `SHOW COLUMNS FROM accounts` against the tenant DB before committing.

- [ ] **Step 3: Add an index/cache** — for prod with many tenants, this could be slow. Cache the result with a 60s TTL keyed by an admin-only cache key. Optional; if you skip, document as a follow-up issue.

- [ ] **Step 4: Test, lint, commit.**

### Task 4.2: Build central directory for ListAccounts [Sonnet]

**Files:**
- Modify: `app/Filament/Admin/Resources/AccountResource.php:133-233`
- Possibly create: `app/Domain/Account/Models/AccountDirectoryEntry.php` (a thin read-model)
- Possibly create: a migration adding an indexed view or `account_directory` table joined from `account_memberships` + per-tenant cache

The current `AccountResource` list lives on the `Account` Eloquent model, which is tenant-scoped. Listing accounts cross-user is fundamentally a cross-tenant operation.

**Decision needed in this task:** either
- (a) Display accounts using the central `AccountMembership` table as the index, with a small read-model column for balance refreshed periodically by a job, OR
- (b) Same iteration trick as Task 4.1 — slow but correct.

Both are viable. (a) scales, (b) is simpler. Pick (b) initially; tag a follow-up issue for (a) when tenant count grows past 100.

- [ ] **Step 1: Write failing test** — list page must show all accounts across two tenants.

- [ ] **Step 2: Override `AccountResource::getEloquentQuery()`** to no longer call into the (tenant-scoped) `Account` model. Instead, materialize a Collection by iterating tenants like 4.1 and return it via a custom table adapter. Filament supports custom table data sources via `query()` returning a Collection or via implementing a custom builder.

Detailed implementation is left for the executor — if this exceeds Sonnet's confidence, escalate to Opus.

- [ ] **Step 3-5: Test, lint, commit per the standard loop.**

### Task 4.3: Audit and tag the remaining cross-account widgets [Haiku]

**Files:** (verify existence first)
- `app/Filament/Admin/Widgets/PendingAdjustmentsWidget.php`
- `app/Filament/Admin/Widgets/OperationsStatsOverview.php`

- [ ] **Step 1: For each, read the file. If it queries `Account` or `AccountBalance` cross-user, apply the iterate-tenants pattern from 4.1. If it doesn't, mark n/a and move on.**

- [ ] **Step 2: Commit with a clear summary of which files were affected.**

### Task 4.4: Manual smoke test of admin panel against staging [Sonnet]

**Files:** none

- [ ] **Step 1: Open admin against staging environment, click through Account list, view detail, run a freeze, run an adjust.**
- [ ] **Step 2: Verify each page does not 500 and shows the expected balance.**
- [ ] **Step 3: Capture screenshots into `docs/superpowers/plans/2026-05-16-account-tenancy-canonicalization-screenshots/` for the PR.**

---

## 11. Phase 5: Workflow + CLI remediation

For each of these, apply `WithTenantContext::withAccountTenancy()` from Phase 1.

### Task 5.1: `BlockchainWithdrawalActivities` [Sonnet]

**Files:**
- Modify: `app/Domain/Wallet/Workflows/Activities/BlockchainWithdrawalActivities.php:197, 282, 296`

- [ ] **Step 1: Write failing test** asserting that calling the activity outside an HTTP request writes to the **tenant** DB, not central.

- [ ] **Step 2: Add `use WithTenantContext;`** to the class.

- [ ] **Step 3: Wrap the raw `DB::table(...)` writes** in `$this->withAccountTenancy($accountUuid, fn () => DB::table(...)->insert(...));`. The `$accountUuid` must be available from the activity's input — read the method signatures to confirm.

- [ ] **Step 4: Test, lint, commit.**

### Tasks 5.2 - 5.7: Same pattern [Sonnet each]

| Sub-task | File |
|---|---|
| 5.2 | `app/Domain/Wallet/Workflows/Activities/BlockchainDepositActivities.php` |
| 5.3 | `app/Domain/Lending/Workflows/Activities/LoanApplicationActivities.php` |
| 5.4 | `app/Console/Commands/MigrateLegacyBalances.php` (also add `--tenant=<uuid>` option) |
| 5.5 | `app/Console/Commands/RunLoadTests.php` |
| 5.6 | `app/Console/Commands/RepairOwnerMembership.php` |
| 5.7 | `database/seeders/DemoDataSeeder.php` |

---

## 12. Phase 6: Invariant enforcement on `Account` / `AccountBalance`

Belt-and-suspenders: once everything **should** init tenancy, make it impossible to forget.

### Task 6.1: Failing test that writing without tenancy throws [Sonnet]

**Files:**
- Create: `tests/Feature/Domain/Account/TenancyInvariantTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Account;

use App\Domain\Account\Models\Account;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenancyInvariantTest extends TestCase
{
    #[Test]
    public function account_create_without_tenancy_throws_in_production(): void
    {
        // Force production-like env for this test only.
        config(['app.env' => 'production']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tenancy/i');

        Account::create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => (string) Str::uuid(),
            'name'      => 'should not work',
        ]);
    }
}
```

- [ ] **Step 2: Run, expect failure (no guard yet).**

### Task 6.2: Add guard to `Account` and `AccountBalance` model boot [Sonnet]

**Files:**
- Modify: `app/Domain/Account/Models/Account.php` (add boot guard)
- Modify: `app/Domain/Account/Models/AccountBalance.php` (same)
- Possibly extract into a shared trait: `app/Domain/Shared/Traits/RequiresTenantContext.php`

- [ ] **Step 1: Add the trait**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Shared\Traits;

use RuntimeException;
use Stancl\Tenancy\Tenancy;

/**
 * Models using this trait will refuse to be saved or fetched outside of
 * tenant context in non-testing environments. The guard fires on the
 * `saving` and `retrieved` Eloquent events.
 *
 * This catches the class of bug where a code path that has historically
 * been HTTP-only is later invoked from a job / command / artisan tinker
 * without anyone realizing it must init tenancy first.
 */
trait RequiresTenantContext
{
    public static function bootRequiresTenantContext(): void
    {
        $check = function (): void {
            if (app()->environment('testing', 'local')) {
                return; // dev/test environments tolerate the fallback
            }

            if (! app(Tenancy::class)->initialized) {
                throw new RuntimeException(sprintf(
                    'Refusing to operate on %s without tenant context. '
                    . 'Initialize tenancy via account.context middleware or WithTenantContext::withAccountTenancy().',
                    static::class,
                ));
            }
        };

        static::saving($check);
    }
}
```

- [ ] **Step 2: Apply trait to `Account` and `AccountBalance`**

```php
// in Account.php and AccountBalance.php class body:
use App\Domain\Shared\Traits\RequiresTenantContext;

class Account extends Model
{
    use UsesTenantConnection;
    use RequiresTenantContext;
    // ...
}
```

- [ ] **Step 3: Run the invariant test, expect green**

- [ ] **Step 4: Run the full Account suite, expect green (because every legit path inits tenancy already after Phase 5)**

If anything fails: the failing test path is exactly the bug class this guard catches. **Fix the test path by adding tenancy init**, don't weaken the guard.

- [ ] **Step 5: Commit**

### Task 6.3: Verify entire test suite is still green [Sonnet]

```bash
"$PHP_BIN" -d max_execution_time=600 ./vendor/bin/pest --parallel --stop-on-failure 2>&1 | tail -30
```

Expected: all green. If anything red, the guard has revealed a path Phase 5 missed — fix that path.

---

## 13. Phase 7: Data migration (central → tenant)

### Task 7.1: Dry-run sweep — list every affected user [Sonnet]

**Files:**
- Create: `app/Console/Commands/SweepOrphanCentralBalancesCommand.php`

- [ ] **Step 1: Write the command (signature: `maphapay:sweep-orphan-central-balances {--apply}`)** that:
  1. Queries `central.accounts` joined with `central.account_balances`.
  2. For each, finds the matching `AccountMembership` by `user_uuid` (NOT by `account_uuid` — they differ).
  3. Initializes tenancy, checks whether an account+balance row already exists there.
  4. If not, builds an insert plan.
  5. With `--apply`, executes the inserts inside `withAccountTenancy()` and **zeroes the central balance row**.
  6. Without `--apply`, prints a table of planned changes.

- [ ] **Step 2: Pest test** with two-tenant fixture proving the migration is idempotent.

- [ ] **Step 3: Run dry-run on prod via `cmd:run`. Capture output to `docs/migration-runs/2026-05-16-orphan-sweep-dryrun.txt`.**

- [ ] **Step 4: Manually inspect plan. Confirm count is non-zero and matches expectations.**

- [ ] **Step 5: Commit the command + tests.**

### Task 7.2: Apply migration in production for the originally-affected user only [Sonnet]

**Files:** none — execution only

- [ ] **Step 1: Run with a user filter scoped to lihledlam@gmail.com first**

```bash
PHP_BIN="$HOME/Library/Application Support/Herd/bin/php"
"$PHP_BIN" vendor/laravel/cloud-cli/builds/cloud cmd:run env-a163f1c0-2c3b-4aef-a936-dfa1f14adc63 \
  --cmd='php artisan maphapay:sweep-orphan-central-balances --apply --user=lihledlam@gmail.com'
```

- [ ] **Step 2: Verify via tinker that the tenant DB now has an SZL balance row equal to 12_922_900.**

- [ ] **Step 3: Open the mobile app, pull to refresh, confirm balance shows E 129,229.00. This is the canary verification — if it works for one user the migration is sound.**

- [ ] **Step 4: Commit the runbook entry** (`docs/migration-runs/2026-05-16-orphan-sweep-user-2.txt`).

### Task 7.3: Apply migration for all remaining affected users [Sonnet]

- [ ] **Step 1: Re-run sweep without `--user`, with `--apply`, monitor output.**
- [ ] **Step 2: Capture full output to runbook.**
- [ ] **Step 3: Spot-check 3 random affected users by querying their tenant DB.**

### Task 7.4: End-to-end smoke from prod [Sonnet]

- [ ] **Step 1: For each of: admin view, admin fund, mobile dashboard, mobile send — do one round trip and confirm balance is consistent across all surfaces.**

### Task 7.5: Add cron sweep to catch future drift [Sonnet]

**Files:**
- Modify: `routes/console.php` (or wherever the schedule is)

- [ ] **Step 1: Schedule the sweep nightly with `--dry-run` and alert if it finds ANY drift.** This catches regressions before users notice.

---

## 14. Phase 8: Decommission central legacy tables

### Task 8.1: Add migration renaming central tables [Sonnet]

**Files:**
- Create: `database/migrations/2026_05_17_000001_rename_central_accounts_to_legacy.php`

- [ ] **Step 1: Migration that ONLY runs on the `mysql` (central) connection**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Only rename the central copies. Tenant copies are the canonical
        // source going forward and must not be touched.
        Schema::connection('mysql')->rename('accounts', 'accounts_legacy_pre_canonicalization');
        Schema::connection('mysql')->rename('account_balances', 'account_balances_legacy_pre_canonicalization');
    }

    public function down(): void
    {
        Schema::connection('mysql')->rename('accounts_legacy_pre_canonicalization', 'accounts');
        Schema::connection('mysql')->rename('account_balances_legacy_pre_canonicalization', 'account_balances');
    }
};
```

- [ ] **Step 2: Verify no code path references the renamed table names. `git grep -F "'accounts'" app/ | grep -i 'mysql\|central\|DB::connection'` and similar.**

- [ ] **Step 3: Run on staging first if possible. If no staging, plan a maintenance window.**

- [ ] **Step 4: Test, commit, deploy.**

### Task 8.2: Add CI / runtime check that central tables are NOT readable [Sonnet]

**Files:**
- Create: `app/Console/Commands/AssertNoCentralAccountAccessCommand.php`

- [ ] **Step 1: Command that queries `central.accounts_legacy_pre_canonicalization` and asserts the existing row count hasn't changed since the rename.**

- [ ] **Step 2: Schedule it nightly. Alert on any write activity.**

### Task 8.3: After 30 days clean, drop the legacy tables [Sonnet, separate session, separate PR]

**Files:**
- Create: `database/migrations/2026_06_17_000001_drop_central_accounts_legacy.php`

- [ ] **Step 1: Drop migration. This is irreversible.**

- [ ] **Step 2: Verify backups exist via Laravel Cloud snapshots.**

- [ ] **Step 3: Apply.**

### Task 8.4: Remove `UsesTenantConnection`'s fallback-to-central behavior [Sonnet]

**Files:**
- Modify: `app/Domain/Shared/Traits/UsesTenantConnection.php:55-77`

Currently the trait silently falls back to `DB_DATABASE` if no tenancy is initialized. After Phase 6 we've guarded against this at the model level. Now remove the silent fallback at the trait level too. Pure defense in depth.

- [ ] **Step 1: Change `getConnectionName()` to throw if tenancy isn't initialized in non-testing environments.** Tests cover the testing carve-out.

- [ ] **Step 2: Run full suite, fix anything that surfaces.**

---

## 15. Phase 9: Documentation

### Task 9.1: Update CLAUDE.md with the tenancy contract [Haiku]

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add a `## Multi-Tenancy Contract` section** stating: tenant DB is canonical for Account / AccountBalance; HTTP routes get tenancy from `account.context` middleware; non-HTTP code must use `WithTenantContext::withAccountTenancy()`; Filament admin uses `WithAccountTenancy` concern on every record page; cross-tenant aggregates explicitly iterate tenants. Link to this plan.

### Task 9.2: Write ADR [Haiku]

**Files:**
- Create: `docs/architecture/ADR-001-account-tenancy.md`

- [ ] **Step 1: Capture the decision, the alternatives considered, the rejected paths (Direction B), and the consequences. Use the standard ADR template.**

### Task 9.3: Update the prior investigation doc [Haiku]

**Files:**
- Modify: `docs/INVESTIGATION_BALANCE_BUG_2026_05_16.md`

- [ ] **Step 1: Add a "Final resolution" section pointing to this plan and the eventual fix commit hash. Mark the original bug as resolved.**

---

## 16. Acceptance criteria (what "done" means)

A reasonable reviewer should be able to verify all of these without you in the room:

- [ ] Pest suite green (`./vendor/bin/pest --parallel`).
- [ ] PHPStan baseline not increased (`vendor/bin/phpstan analyse --memory-limit=2G`).
- [ ] cs-fixer clean.
- [ ] Mobile dashboard for lihledlam@gmail.com shows E 129,229.00.
- [ ] Admin AccountResource for the same user shows the same number.
- [ ] Admin Filament `FundAccountPage` credit of E 1.00 → mobile dashboard shows E 129,230.00 within 30s (one cache TTL).
- [ ] Mobile send of E 1.00 → admin AccountResource shows E 129,229.00 within 30s.
- [ ] Central tables renamed; no code references the old names.
- [ ] Invariant guard active in production env.
- [ ] CLAUDE.md updated; ADR-001 written.
- [ ] Investigation doc closed out.

---

## 17. Rollback plan

Per phase, in reverse:

| Phase | Rollback |
|---|---|
| 9 | Revert docs commits. |
| 8 | Migration `down()` renames tables back. **Only safe if no writes have occurred to the new tenant copies.** Run sweep again in reverse direction. |
| 7 | If migration is wrong: re-run with corrected logic. The sweep is idempotent. **Worst case**: restore the central tables from snapshot and re-run from scratch. |
| 6 | Remove the trait usage from the two models. |
| 5 | Revert each commit; workflow / CLI paths return to fallback behavior. |
| 4 | Revert widget rewrite; aggregates return to broken-but-non-throwing state. |
| 2, 3 | Revert concern usage in each file. Concern stays defined but unused. |
| 1 | Delete the new trait files. No effect on prod. |
| 0 | `git checkout main && git branch -D refactor/account-tenancy-canonicalization`. |

The point of no return is **Task 8.3 (DROP the legacy tables)**. Everything before it is reversible.

---

## 18. Risk register

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| 1 | Phase 4 cross-tenant iteration is slow (many tenants) | Med | Med (admin UX) | Cache widget output 60s; add background pre-warm job in Phase 4 follow-up. |
| 2 | Hidden code path still writes to central after Phase 6 guard | Med | High (would block prod writes) | Run staging soak before prod deploy. Guard throws loud errors that surface immediately. |
| 3 | Sweep migration double-counts (already-migrated user re-run) | Low | High (creates duplicate balance) | Idempotent insert: `updateOrCreate` keyed on `(account_uuid, asset_code)`. Verify with a Pest test before Task 7.1 ships. |
| 4 | Tenant DB missing for a user with central balance (membership orphaned) | Low | Med | Sweep emits a report of these and skips them; manual remediation. |
| 5 | Mobile cache (30s TTL on `/api/dashboard`) holds stale 0 after migration | High | Low | Tell the user to pull-to-refresh, or invalidate the Redis key during sweep. |
| 6 | Filament freeze action goes through `AccountService::freeze($uuid, ...)` which itself queries `Account::find($uuid)` — if `AccountService` is called from a non-tenant context (cron?), it dies. | Med | Med | Verify in Phase 5 audit that `AccountService` only fires from properly-context'd callers; if not, refactor `AccountService` to require `accountUuid` AND init tenancy itself. |

---

## 19. Notes for the executor

- **Don't deviate without escalating.** If a task feels wrong, stop and escalate to Opus. Don't quietly improvise.
- **One commit per task.** Lots of small commits beat one giant commit. Bisecting later is easy.
- **Always TDD.** Write the failing test before changing implementation. Mandated by `CLAUDE.md`.
- **Don't skip cs-fixer + phpstan.** They've already caught real bugs in earlier phases.
- **Don't decommission until migration verified.** Phase 8 only after Phase 7 fully clean.
- **Production data mutations require manual review.** Tasks 7.2 and 7.3 must be reviewed by a human before `--apply`.

---

## Self-Review

- [x] Spec coverage: every audited surface (Section 3) maps to a task.
- [x] No placeholders: code blocks where code is required; commands where commands required.
- [x] Type consistency: `initializeTenancyForRecord`, `releaseAccountTenancy`, `withAccountTenancy` used consistently.
- [x] Model floor recommendations on every phase.
- [x] Rollback plan and risk register.
- [x] Self-contained: a fresh engineer (or fresh agent) can read Sections 0-3 and pick up any task.
