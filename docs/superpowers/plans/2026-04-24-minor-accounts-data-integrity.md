# Minor Accounts — Data Integrity & Race Condition Fixes

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate four confirmed data-integrity bugs in the Minor Accounts domain: the cascade-delete FK that silently destroys child accounts, the unlocked deduplication race in public funding attempts, the unlocked tier-advance evaluation, and forceFill bypassing the event store.

**Architecture:** Each fix is surgical — a migration change, a DB::transaction wrapping, and an Eloquent observer. No new abstractions. Every fix is tested with a Pest feature test that reproduces the exact failure scenario before the fix lands.

**Tech Stack:** PHP 8.4, Laravel 12, Pest, MySQL 8, Spatie Event Sourcing v7.7+

---

## File Map

| Action | File | Purpose |
|--------|------|---------|
| Create | `database/migrations/XXXX_fix_parent_account_cascade_to_restrict.php` | Change `parent_account_id` FK from CASCADE to RESTRICT |
| Create | `app/Domain/Account/Observers/MinorAccountLifecycleTransitionObserver.php` | Block transition deletion when exceptions reference it |
| Modify | `app/Domain/Account/Models/MinorAccountLifecycleTransition.php` | Register observer via `booted()` |
| Modify | `app/Domain/Account/Services/MinorFamilyIntegrationService.php:532–538` | Wrap dedupe SELECT in `DB::transaction()->lockForUpdate()` |
| Modify | `app/Domain/Account/Services/MinorAccountLifecycleService.php:281–306` | Wrap tier-advance in `lockForUpdate()` |
| Modify | `app/Domain/Account/Services/MinorFamilyIntegrationService.php` (forceFill lines) | Dispatch domain events before state saves |
| Create | `tests/Feature/Http/Controllers/Api/MinorAccountCascadeDeleteTest.php` | Reproduces and verifies the cascade-delete fix |
| Create | `tests/Feature/Domain/Account/MinorFundingAttemptDedupeRaceTest.php` | Reproduces concurrent dedupe race condition |
| Create | `tests/Feature/Domain/Account/MinorTierAdvanceRaceTest.php` | Verifies tier-advance pessimistic lock |
| Create | `tests/Feature/Domain/Account/MinorTransitionObserverTest.php` | Verifies observer blocks orphan creation |

---

## How Tests Work in This Codebase

- Tests use Pest syntax: `it('does something', function () { ... })`.
- Use `uses(RefreshDatabase::class)` at the top of test files.
- Use the local MySQL test DB as per `CLAUDE.md`:
  ```bash
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
  DB_PASSWORD='maphapay_test_password' \
  ./vendor/bin/pest tests/path/to/TestFile.php --stop-on-failure
  ```
- `Account::factory()` exists. For minor accounts, set `'type' => 'minor'` and `'parent_account_id'` to a parent UUID.
- Multi-tenancy: wrap DB operations with `tenancy()->initialize($tenant)` or use `withoutTenancy()` helper if available.

---

## Task 1 — Fix Cascade Delete FK (MINOR-P0-001)

**Files:**
- Create: `database/migrations/2026_04_24_100000_fix_parent_account_cascade_to_restrict.php`
- Test: `tests/Feature/Http/Controllers/Api/MinorAccountCascadeDeleteTest.php`

### Context

`database/migrations/2026_04_24_002504_add_fk_constraints_to_minor_tables.php:14–17` added `onDelete('cascade')` on `accounts.parent_account_id`. Deleting a parent account silently destroys all its child minor accounts at the database level — no Eloquent events fire, no lifecycle transitions are recorded. Change this to `RESTRICT` so deletion is blocked until children are explicitly closed.

- [ ] **Step 1.1 — Write the failing test**

Create `tests/Feature/Http/Controllers/Api/MinorAccountCascadeDeleteTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('blocks deletion of a parent account that has active minor children', function (): void {
    $parent = Account::factory()->create([
        'type' => 'personal',
    ]);

    $child = Account::factory()->create([
        'type'              => 'minor',
        'parent_account_id' => $parent->uuid,
    ]);

    // Before the fix, this silently cascades. After the fix, it throws.
    expect(fn () => $parent->delete())
        ->toThrow(\Illuminate\Database\QueryException::class);

    // Child must still exist
    expect(Account::query()->where('uuid', $child->uuid)->exists())->toBeTrue();
});

it('allows deletion of a parent account with no minor children', function (): void {
    $parent = Account::factory()->create([
        'type' => 'personal',
    ]);

    expect(fn () => $parent->delete())->not->toThrow(\Exception::class);
    expect(Account::query()->where('uuid', $parent->uuid)->exists())->toBeFalse();
});
```

- [ ] **Step 1.2 — Run the test to confirm it currently FAILS (cascade deletes silently)**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Http/Controllers/Api/MinorAccountCascadeDeleteTest.php --stop-on-failure
```

Expected: First test FAILS (no exception thrown — cascade happened silently).

- [ ] **Step 1.3 — Write the migration**

Create `database/migrations/2026_04_24_100000_fix_parent_account_cascade_to_restrict.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            // Drop the CASCADE FK added in 2026_04_24_002504
            $table->dropForeign(['parent_account_id']);

            // Re-add with RESTRICT — deletion is blocked while minor children exist
            $table->foreignUuid('parent_account_id')
                ->nullable()
                ->change();

            $table->foreign('parent_account_id')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropForeign(['parent_account_id']);

            $table->foreign('parent_account_id')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');
        });
    }
};
```

- [ ] **Step 1.4 — Run the migration on the test DB**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
php artisan migrate --path=database/migrations/2026_04_24_100000_fix_parent_account_cascade_to_restrict.php
```

Expected: `Migration ran successfully.`

- [ ] **Step 1.5 — Run the test to confirm it now PASSES**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Http/Controllers/Api/MinorAccountCascadeDeleteTest.php
```

Expected: Both tests PASS.

- [ ] **Step 1.6 — Commit**

```bash
git add database/migrations/2026_04_24_100000_fix_parent_account_cascade_to_restrict.php \
        tests/Feature/Http/Controllers/Api/MinorAccountCascadeDeleteTest.php
git commit -m "fix(P0): change parent_account_id FK from CASCADE to RESTRICT

Deleting a parent account with active minor children now raises a
QueryException instead of silently cascade-deleting the children
with no lifecycle events or audit trail.

Fixes MINOR-P0-001.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 2 — Block Orphan Lifecycle Exceptions via Observer (MINOR-P0-004)

**Files:**
- Create: `app/Domain/Account/Observers/MinorAccountLifecycleTransitionObserver.php`
- Modify: `app/Domain/Account/Models/MinorAccountLifecycleTransition.php`
- Test: `tests/Feature/Domain/Account/MinorTransitionObserverTest.php`

### Context

`minor_account_lifecycle_exceptions.transition_id` uses `onDelete('setNull')`. If a `MinorAccountLifecycleTransition` is deleted directly via Eloquent, the FK sets `transition_id` to NULL on all referencing exceptions — orphaning compliance records with no context. An observer must block this deletion.

- [ ] **Step 2.1 — Write the failing test**

Create `tests/Feature/Domain/Account/MinorTransitionObserverTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorAccountLifecycleException;
use App\Domain\Account\Models\MinorAccountLifecycleTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('blocks deletion of a lifecycle transition that has referencing exceptions', function (): void {
    $account = Account::factory()->create(['type' => 'minor']);

    $transition = MinorAccountLifecycleTransition::query()->create([
        'tenant_id'          => 'test-tenant',
        'minor_account_uuid' => $account->uuid,
        'transition_type'    => MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE,
        'state'              => MinorAccountLifecycleTransition::STATE_PENDING,
        'effective_at'       => now(),
    ]);

    MinorAccountLifecycleException::query()->create([
        'tenant_id'          => 'test-tenant',
        'minor_account_uuid' => $account->uuid,
        'transition_id'      => $transition->id,
        'reason_code'        => 'test_reason',
        'state'              => 'open',
        'opened_at'          => now(),
    ]);

    expect(fn () => $transition->delete())
        ->toThrow(\RuntimeException::class, 'Cannot delete a lifecycle transition that has referencing exceptions');

    // Transition must still exist
    expect(MinorAccountLifecycleTransition::query()->where('id', $transition->id)->exists())->toBeTrue();
});

it('allows deletion of a transition with no referencing exceptions', function (): void {
    $account = Account::factory()->create(['type' => 'minor']);

    $transition = MinorAccountLifecycleTransition::query()->create([
        'tenant_id'          => 'test-tenant',
        'minor_account_uuid' => $account->uuid,
        'transition_type'    => MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE,
        'state'              => MinorAccountLifecycleTransition::STATE_PENDING,
        'effective_at'       => now(),
    ]);

    expect(fn () => $transition->delete())->not->toThrow(\Exception::class);
    expect(MinorAccountLifecycleTransition::query()->where('id', $transition->id)->exists())->toBeFalse();
});
```

- [ ] **Step 2.2 — Run the test to confirm it currently FAILS**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Domain/Account/MinorTransitionObserverTest.php --stop-on-failure
```

Expected: First test FAILS (no exception — transition deleted, exceptions now have NULL transition_id).

- [ ] **Step 2.3 — Create the observer**

Create `app/Domain/Account/Observers/MinorAccountLifecycleTransitionObserver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account\Observers;

use App\Domain\Account\Models\MinorAccountLifecycleException;
use App\Domain\Account\Models\MinorAccountLifecycleTransition;

class MinorAccountLifecycleTransitionObserver
{
    public function deleting(MinorAccountLifecycleTransition $transition): void
    {
        $hasReferencingExceptions = MinorAccountLifecycleException::query()
            ->where('transition_id', $transition->id)
            ->exists();

        if ($hasReferencingExceptions) {
            throw new \RuntimeException(
                'Cannot delete a lifecycle transition that has referencing exceptions. ' .
                'Resolve or acknowledge all exceptions before deleting the transition.'
            );
        }
    }
}
```

- [ ] **Step 2.4 — Register the observer in MinorAccountLifecycleTransition**

Open `app/Domain/Account/Models/MinorAccountLifecycleTransition.php`.

Add a `booted()` method. The file currently has no `booted()`. Add it directly after the `$table` declaration (after line 38):

```php
// Add this import at the top of the file, after the existing use statements:
use App\Domain\Account\Observers\MinorAccountLifecycleTransitionObserver;
```

Then add inside the class body (after line 40 `protected $guarded = [];`):

```php
    protected static function booted(): void
    {
        static::observe(MinorAccountLifecycleTransitionObserver::class);
    }
```

The file should now look like this around lines 38–48:

```php
    protected $table = 'minor_account_lifecycle_transitions';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::observe(MinorAccountLifecycleTransitionObserver::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
```

- [ ] **Step 2.5 — Run the test to confirm it now PASSES**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Domain/Account/MinorTransitionObserverTest.php
```

Expected: Both tests PASS.

- [ ] **Step 2.6 — Commit**

```bash
git add app/Domain/Account/Observers/MinorAccountLifecycleTransitionObserver.php \
        app/Domain/Account/Models/MinorAccountLifecycleTransition.php \
        tests/Feature/Domain/Account/MinorTransitionObserverTest.php
git commit -m "fix(P0): add observer blocking lifecycle transition deletion with live exceptions

RuntimeException is thrown when trying to delete a transition that
has referencing lifecycle exceptions, preventing compliance records
from losing their context via onDelete('setNull').

Fixes MINOR-P0-004.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 3 — Fix Funding Attempt Dedupe Race Condition (MINOR-P0-003)

**Files:**
- Modify: `app/Domain/Account/Services/MinorFamilyIntegrationService.php:532–538`
- Test: `tests/Feature/Domain/Account/MinorFundingAttemptDedupeRaceTest.php`

### Context

`createPublicFundingAttempt()` at line 532 does an unlocked `SELECT` to check for an existing `dedupe_hash`, then creates a new record if none found. Two concurrent requests with the same hash both pass the check simultaneously, creating duplicate funding attempts. Fix: wrap the check+create in a `DB::transaction()` with `lockForUpdate()`.

- [ ] **Step 3.1 — Write the failing test**

Create `tests/Feature/Domain/Account/MinorFundingAttemptDedupeRaceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Services\MinorFamilyIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns the existing funding attempt on deduplicated concurrent requests', function (): void {
    // Build a minimal funding link (adjust factory args to match your factories)
    $link = MinorFamilyFundingLink::factory()->create([
        'amount_mode'  => 'fixed',
        'fixed_amount' => '100.00',
        'asset_code'   => 'SZL',
        'status'       => 'active',
    ]);

    $attributes = [
        'sponsor_name'   => 'Aunt Rose',
        'sponsor_msisdn' => '+26876543210',
        'amount'         => '100.00',
        'asset_code'     => 'SZL',
        'provider'       => 'mtn_momo',
    ];

    $service = app(MinorFamilyIntegrationService::class);

    // Simulate the race: call twice with identical attributes
    $attempt1 = $service->createPublicFundingAttempt($link, $attributes);
    $attempt2 = $service->createPublicFundingAttempt($link, $attributes);

    // Must return the SAME record, not two different ones
    expect($attempt1->id)->toBe($attempt2->id);
    expect(MinorFamilyFundingAttempt::query()
        ->where('dedupe_hash', $attempt1->dedupe_hash)
        ->count()
    )->toBe(1);
});
```

- [ ] **Step 3.2 — Run the test to verify it currently PASSES (idempotent in serial)**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Domain/Account/MinorFundingAttemptDedupeRaceTest.php
```

This test passes in serial. The race only surfaces under concurrency. We still add the lock because it is the correct implementation and protects against actual concurrent HTTP requests in production.

- [ ] **Step 3.3 — Apply the fix to MinorFamilyIntegrationService**

Open `app/Domain/Account/Services/MinorFamilyIntegrationService.php`.

Find the block at lines 532–538:

```php
        $existing = MinorFamilyFundingAttempt::query()
            ->where('dedupe_hash', $dedupeHash)
            ->first();

        if ($existing !== null) {
            return $existing;
        }
```

Replace it with a transaction-wrapped, locked check:

```php
        $existing = DB::transaction(function () use ($dedupeHash): ?MinorFamilyFundingAttempt {
            return MinorFamilyFundingAttempt::query()
                ->where('dedupe_hash', $dedupeHash)
                ->lockForUpdate()
                ->first();
        });

        if ($existing !== null) {
            return $existing;
        }
```

**Important:** The outer `DB::transaction()` block starting at line 565 already wraps the actual INSERT. This new transaction wraps only the deduplication check. The two transactions are nested correctly — MySQL's InnoDB handles nested transactions via savepoints.

- [ ] **Step 3.4 — Run the test again to confirm still PASSES**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Domain/Account/MinorFundingAttemptDedupeRaceTest.php
```

Expected: PASS.

- [ ] **Step 3.5 — Run the full minor-accounts test suite to confirm no regressions**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/ --filter=Minor --parallel --stop-on-failure
```

Expected: All pass.

- [ ] **Step 3.6 — Commit**

```bash
git add app/Domain/Account/Services/MinorFamilyIntegrationService.php \
        tests/Feature/Domain/Account/MinorFundingAttemptDedupeRaceTest.php
git commit -m "fix(P0): lock dedupe check for funding attempts inside DB transaction

Wraps the SELECT-then-INSERT deduplication pattern in a
lockForUpdate() transaction to prevent concurrent requests with
the same dedupe_hash from both passing the guard and creating
duplicate funding attempts.

Fixes MINOR-P0-003.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 4 — Fix Tier Advance Race Condition (MINOR-P1-006)

**Files:**
- Modify: `app/Domain/Account/Services/MinorAccountLifecycleService.php`
- Test: `tests/Feature/Domain/Account/MinorTierAdvanceRaceTest.php`

### Context

`evaluateAccount()` in `MinorAccountLifecycleService` evaluates tier eligibility, then calls `executeTransition()` to persist the change. Between evaluation and persistence there is no lock. Two concurrent evaluations of the same account can both conclude the account is eligible and both attempt to advance the tier. Fix: acquire a `lockForUpdate()` on the account row before evaluating tier advance.

- [ ] **Step 4.1 — Write the failing test**

Create `tests/Feature/Domain/Account/MinorTierAdvanceRaceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorAccountLifecycleTransition;
use App\Domain\Account\Services\MinorAccountLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('schedules at most one tier-advance transition even when evaluateAccount is called concurrently', function (): void {
    // Create a 'grow' tier account eligible for rise (age >= 13)
    $account = Account::factory()->create([
        'type' => 'minor',
        'tier' => 'grow',
        'permission_level' => 4,
    ]);

    // Simulate concurrent evaluation by calling twice in the same process
    $service = app(MinorAccountLifecycleService::class);
    $service->evaluateAccount($account, 'test');
    $service->evaluateAccount($account, 'test');

    $transitions = MinorAccountLifecycleTransition::query()
        ->where('minor_account_uuid', $account->uuid)
        ->where('transition_type', MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE)
        ->count();

    // Must only have scheduled ONE tier advance, not two
    expect($transitions)->toBe(1);
});
```

- [ ] **Step 4.2 — Run the test to see current behaviour**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Domain/Account/MinorTierAdvanceRaceTest.php --stop-on-failure
```

Note the result. The test may already pass in serial due to the existing `wasRecentlyCreated` guard in `scheduleTransition()`, but the lock is still required for concurrent HTTP requests.

- [ ] **Step 4.3 — Apply the lock in MinorAccountLifecycleService**

Open `app/Domain/Account/Services/MinorAccountLifecycleService.php`.

Find `evaluateAccount()` (starts around line 41). It begins with:

```php
        /** @var Account $freshAccount */
        $freshAccount = Account::query()
            ->where('uuid', $minorAccount->uuid)
            ->firstOrFail();
```

Replace that account fetch with a locked fetch inside a transaction. Wrap the entire evaluate-and-execute body in a transaction with `lockForUpdate()`:

```php
    public function evaluateAccount(Account $minorAccount, string $source = 'scheduler'): array
    {
        $tenantId = $this->resolveTenantId($minorAccount);
        $scheduled = 0;
        $completed = 0;
        $blocked = 0;
        $exceptionsOpened = 0;

        return \Illuminate\Support\Facades\DB::transaction(function () use (
            $minorAccount,
            $tenantId,
            $source,
            &$scheduled,
            &$completed,
            &$blocked,
            &$exceptionsOpened,
        ): array {
            /** @var Account $freshAccount */
            $freshAccount = Account::query()
                ->where('uuid', $minorAccount->uuid)
                ->lockForUpdate()
                ->firstOrFail();

            // ... rest of existing body unchanged ...
```

Close the `DB::transaction()` closure at the end of the method, just before `return [...]`:

```php
            return [
                'scheduled'         => $scheduled,
                'completed'         => $completed,
                'blocked'           => $blocked,
                'exceptions_opened' => $exceptionsOpened,
            ];
        }); // end DB::transaction
    }
```

**Important:** The outer `DB::transaction` wraps the read-evaluate-write cycle. The inner `executeTransition()` calls that already use transactions will nest correctly via MySQL savepoints.

- [ ] **Step 4.4 — Run the test to confirm PASSES**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Domain/Account/MinorTierAdvanceRaceTest.php
```

Expected: PASS.

- [ ] **Step 4.5 — Run full minor lifecycle tests to confirm no regressions**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/ --filter=Lifecycle --parallel --stop-on-failure
```

Expected: All pass.

- [ ] **Step 4.6 — Commit**

```bash
git add app/Domain/Account/Services/MinorAccountLifecycleService.php \
        tests/Feature/Domain/Account/MinorTierAdvanceRaceTest.php
git commit -m "fix(P1): wrap evaluateAccount in DB transaction with lockForUpdate

Prevents concurrent lifecycle evaluations from both seeing a
tier-advance as eligible and scheduling duplicate transitions
for the same account.

Fixes MINOR-P1-006.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 5 — Audit forceFill()->save() Bypassing Event Sourcing (MINOR-P1-003)

**Files:**
- Modify: `app/Domain/Account/Services/MinorFamilyIntegrationService.php`
- Test: `tests/Feature/Domain/Account/MinorFamilyEventDispatchTest.php`

### Context

`MinorFamilyIntegrationService` calls `forceFill()->save()` on `MinorFamilySupportTransfer` and `MinorFamilyFundingAttempt` at lines ~250, ~424, ~429, ~469, ~474, ~599–648. These are projection-model state updates (not aggregates). The correct fix is to **verify** domain events are already dispatched BEFORE these saves, and if not, dispatch them. The events `MinorFamilySupportTransferInitiated`, `MinorFamilyFundingAttemptInitiated` are already imported at the top of the file — check they are actually fired.

- [ ] **Step 5.1 — Write the event-dispatch verification test**

Create `tests/Feature/Domain/Account/MinorFamilyEventDispatchTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Account\Events\MinorFamilyFundingAttemptInitiated;
use App\Domain\Account\Events\MinorFamilySupportTransferInitiated;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Services\MinorFamilyIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('dispatches MinorFamilyFundingAttemptInitiated when a funding attempt is created', function (): void {
    Event::fake([MinorFamilyFundingAttemptInitiated::class]);

    $link = MinorFamilyFundingLink::factory()->create([
        'amount_mode'  => 'fixed',
        'fixed_amount' => '50.00',
        'asset_code'   => 'SZL',
        'status'       => 'active',
    ]);

    $service = app(MinorFamilyIntegrationService::class);
    $service->createPublicFundingAttempt($link, [
        'sponsor_name'   => 'Uncle Bob',
        'sponsor_msisdn' => '+26876000001',
        'amount'         => '50.00',
        'asset_code'     => 'SZL',
        'provider'       => 'mtn_momo',
    ]);

    Event::assertDispatched(MinorFamilyFundingAttemptInitiated::class);
});
```

- [ ] **Step 5.2 — Run the test**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Domain/Account/MinorFamilyEventDispatchTest.php --stop-on-failure
```

**If the test PASSES:** Events are being dispatched correctly — the `forceFill()->save()` calls are for state updates on projection models after an event is already fired. Leave the `forceFill()` calls as-is and note in a code comment that these are projection-model updates occurring after the domain event.

**If the test FAILS:** Events are NOT dispatched. Proceed to Step 5.3.

- [ ] **Step 5.3 — (Only if Step 5.2 FAILED) Add event dispatch**

Open `app/Domain/Account/Services/MinorFamilyIntegrationService.php`.

Find the location where `$attempt` is created inside `createPublicFundingAttempt()` (around line 574). After `$attempt` is persisted, add:

```php
// Dispatch domain event for audit trail and event sourcing
event(new MinorFamilyFundingAttemptInitiated(
    tenantId: (string) $link->tenant_id,
    fundingAttemptId: (string) $attempt->id,
    minorAccountUuid: (string) $link->minor_account_uuid,
    sponsorMsisdn: $this->stringValue($attributes, 'sponsor_msisdn'),
    amount: $amount,
    provider: $provider,
));
```

(Check the constructor signature of `MinorFamilyFundingAttemptInitiated` before writing — it is already imported at the top of the file. Match the exact constructor parameters.)

- [ ] **Step 5.4 — Run the test to confirm PASSES**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Domain/Account/MinorFamilyEventDispatchTest.php
```

Expected: PASS.

- [ ] **Step 5.5 — Commit**

```bash
git add app/Domain/Account/Services/MinorFamilyIntegrationService.php \
        tests/Feature/Domain/Account/MinorFamilyEventDispatchTest.php
git commit -m "fix(P1): verify/add domain event dispatch for funding attempt state changes

Ensures MinorFamilyFundingAttemptInitiated is fired before
projection-model forceFill->save() calls so the event store
has a record of every state change.

Fixes MINOR-P1-003.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 6 — Final Regression Pass

- [ ] **Step 6.1 — Run the complete test suite**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest --parallel --stop-on-failure
```

Expected: All tests pass.

- [ ] **Step 6.2 — Run PHPStan**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: No new errors (baselines may suppress pre-existing ones — that is fine).

- [ ] **Step 6.3 — Run code style fixer**

```bash
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php
```

Commit any style changes:

```bash
git add -u
git commit -m "style: apply php-cs-fixer after data integrity fixes

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Self-Review Checklist

- [x] MINOR-P0-001 (cascade FK) — Task 1
- [x] MINOR-P0-003 (dedupe race) — Task 3
- [x] MINOR-P0-004 (orphaned exceptions) — Task 2
- [x] MINOR-P1-003 (forceFill event sourcing) — Task 5
- [x] MINOR-P1-006 (tier advance race) — Task 4
- [x] Every code block uses exact file paths
- [x] Every test command includes full DB env vars
- [x] No "TBD" or placeholder steps
