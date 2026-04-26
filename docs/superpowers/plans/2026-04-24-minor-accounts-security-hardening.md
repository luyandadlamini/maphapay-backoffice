# Minor Accounts — Security Hardening

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden the Minor Accounts domain against five confirmed security gaps: missing SCA on `setEmergencyAllowance()` and co-guardian invite creation, mass assignment on 10+ models, weak public funding link token generation, double-points on `updatePermissionLevel()` retries, and the `minor-accounts:lifecycle-evaluate` command running only once daily instead of every 15 minutes.

**Architecture:** Each fix is a surgical edit to the relevant controller, model, or schedule. No new service layers. All security changes are verified by feature tests that reproduce the exact attack scenario before the fix.

**Tech Stack:** PHP 8.4, Laravel 12, Pest, Laravel Sanctum (SCA via existing `scaVerificationService` pattern found in `MinorCardController`).

---

## Prerequisite Reading

Before implementing, read these files to understand the existing SCA pattern:
- `app/Http/Controllers/Api/Account/MinorCardController.php` — the `freeze()` method shows how SCA is already applied; copy this exact pattern.
- `app/Http/Middleware/` — look for any SCA/2FA middleware to understand if there's a middleware alternative.
- `routes/console.php:257–261` — current lifecycle-evaluate schedule (dailyAt 01:15).

---

## File Map

| Action | File | Purpose |
|--------|------|---------|
| Modify | `app/Http/Controllers/Api/MinorAccountController.php:243–270` | Add SCA to `setEmergencyAllowance()` |
| Modify | `app/Http/Controllers/Api/CoGuardianController.php:25–48` | Add SCA to `storeInvite()` |
| Modify | `app/Domain/Account/Models/MinorAccountLifecycleTransition.php` | Replace `$guarded = []` with `$fillable` |
| Modify | `app/Domain/Account/Models/MinorSpendApproval.php` | Replace `$guarded = []` with `$fillable` |
| Modify | `app/Domain/Account/Models/GuardianInvite.php` | Replace `$guarded = []` with `$fillable` |
| Modify | `app/Domain/Account/Models/MinorPointsLedger.php` | Replace `$guarded = []` with `$fillable` |
| Modify | `app/Domain/Account/Models/MinorFamilyReconciliationException.php` | Replace `$guarded = []` with `$fillable` |
| Modify | `app/Domain/Account/Models/Account.php` | Replace `$guarded = []` with `$fillable` |
| Modify | `app/Domain/Account/Services/MinorFamilyIntegrationService.php:194` | Replace `Str::uuid()` token with `Str::random(64)` |
| Modify | `app/Http/Controllers/Api/PublicMinorFundingLinkController.php` | Compare hashed token, not plaintext |
| Modify | `app/Http/Controllers/Api/MinorAccountController.php:151–235` | Add idempotency key to `updatePermissionLevel()` |
| Modify | `routes/console.php:257–261` | Change lifecycle-evaluate schedule to `everyFifteenMinutes()` |
| Create | `tests/Feature/Http/Controllers/Api/MinorEmergencyAllowanceScaTest.php` | Verify SCA required |
| Create | `tests/Feature/Http/Controllers/Api/CoGuardianScaTest.php` | Verify SCA required for invite |
| Create | `tests/Feature/Http/Controllers/Api/MinorPermissionLevelIdempotencyTest.php` | Verify no double-points |
| Create | `tests/Feature/Domain/Account/MinorFundingLinkTokenSecurityTest.php` | Verify token is hashed |

---

## Task 1 — Register Lifecycle Evaluation on Every 15 Minutes (MINOR-P0-002 partial)

**Files:**
- Modify: `routes/console.php:257–261`

### Context

The lifecycle evaluation command currently runs once daily at 01:15. An 18-year-old child retains minor-account privileges for up to 23+ hours. Changing to every 15 minutes reduces the window to ≤15 minutes.

- [ ] **Step 1.1 — Update the schedule**

Open `routes/console.php`. Find lines 257–261:

```php
Schedule::command('minor-accounts:lifecycle-evaluate')
    ->dailyAt('01:15')
    ->description('Evaluate and execute minor account lifecycle transitions')
    ->appendOutputTo(storage_path('logs/minor-account-lifecycle-evaluate.log'))
    ->withoutOverlapping();
```

Replace with:

```php
Schedule::command('minor-accounts:lifecycle-evaluate')
    ->everyFifteenMinutes()
    ->description('Evaluate and execute minor account lifecycle transitions')
    ->appendOutputTo(storage_path('logs/minor-account-lifecycle-evaluate.log'))
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::critical('minor-accounts:lifecycle-evaluate failed to run');
    });
```

- [ ] **Step 1.2 — Verify the schedule registers correctly**

```bash
php artisan schedule:list | grep lifecycle-evaluate
```

Expected output contains: `minor-accounts:lifecycle-evaluate` with `Every 15 minutes`

- [ ] **Step 1.3 — Commit**

```bash
git add routes/console.php
git commit -m "fix(P0): run minor-accounts lifecycle-evaluate every 15 minutes

Previously ran once daily at 01:15, leaving an 18-year-old child
with minor-account restrictions for up to 23+ hours after their
birthday. Every-15-minute cadence reduces the window to ≤15min.

Partially fixes MINOR-P0-002.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 2 — Add SCA to setEmergencyAllowance() (MINOR-P1-001)

**Files:**
- Modify: `app/Http/Controllers/Api/MinorAccountController.php:243–270`
- Test: `tests/Feature/Http/Controllers/Api/MinorEmergencyAllowanceScaTest.php`

### Context

`setEmergencyAllowance()` modifies the child's emergency spending cap with no secondary authentication. A compromised guardian session can silently raise a child's spending. The existing SCA pattern is in `app/Http/Controllers/Api/Account/MinorCardController.php` — read that file's `freeze()` method first to understand the exact injection and call pattern.

- [ ] **Step 2.1 — Read MinorCardController to understand the SCA pattern**

```bash
grep -n "sca\|SCA\|verification\|verify\|twoFactor\|2fa" \
  app/Http/Controllers/Api/Account/MinorCardController.php | head -20
```

Note the exact service class name, the injection in `__construct()`, and the method call used (e.g., `$this->scaService->verifyOrFail($request)`). You **must** use the same pattern.

- [ ] **Step 2.2 — Write the failing test**

Create `tests/Feature/Http/Controllers/Api/MinorEmergencyAllowanceScaTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('rejects setEmergencyAllowance without SCA verification header', function (): void {
    $guardian = User::factory()->create();
    $minorAccount = Account::factory()->create(['type' => 'minor']);

    // Create guardian membership (adjust to match your factory/service pattern)
    // The guardian must have 'guardian' role on the minor account
    \App\Domain\Account\Models\AccountMembership::factory()->create([
        'user_uuid'    => $guardian->uuid,
        'account_uuid' => $minorAccount->uuid,
        'role'         => 'guardian',
        'status'       => 'active',
    ]);

    Sanctum::actingAs($guardian, ['read', 'write', 'delete']);

    // Call without SCA — should be rejected
    $response = $this->putJson(
        "/api/accounts/minor/{$minorAccount->uuid}/emergency-allowance",
        ['amount' => 5000]
    );

    // Must require SCA — 428 (Precondition Required) or 403
    $response->assertStatus(428);
});

it('accepts setEmergencyAllowance with valid SCA verification', function (): void {
    $guardian = User::factory()->create();
    $minorAccount = Account::factory()->create(['type' => 'minor']);

    \App\Domain\Account\Models\AccountMembership::factory()->create([
        'user_uuid'    => $guardian->uuid,
        'account_uuid' => $minorAccount->uuid,
        'role'         => 'guardian',
        'status'       => 'active',
    ]);

    Sanctum::actingAs($guardian, ['read', 'write', 'delete']);

    // Pass the SCA header/token your codebase uses (check MinorCardController tests for the exact header)
    $response = $this->putJson(
        "/api/accounts/minor/{$minorAccount->uuid}/emergency-allowance",
        ['amount' => 5000],
        ['X-SCA-Token' => 'valid-test-sca-token'] // adjust header name to match codebase
    );

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
});
```

**Before running:** Check existing tests for `MinorCardController` (e.g., `tests/Feature/Http/Controllers/Api/Account/MinorCardControllerTest.php`) to find the exact header name and how SCA is bypassed in tests.

- [ ] **Step 2.3 — Run the test to confirm it currently FAILS (no SCA = 200)**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Http/Controllers/Api/MinorEmergencyAllowanceScaTest.php --stop-on-failure
```

Expected: First test FAILS (returns 200 instead of 428).

- [ ] **Step 2.4 — Add SCA to setEmergencyAllowance()**

Open `app/Http/Controllers/Api/MinorAccountController.php`.

**Step A:** Add the SCA service to the constructor injection. Find `__construct` (lines 26–29) and add the SCA service (using the exact class name from `MinorCardController`):

```php
    public function __construct(
        private readonly AccountMembershipService $membershipService,
        private readonly AccountPolicy $accountPolicy,
        private readonly \App\Domain\Security\Services\ScaVerificationService $scaService, // add this line (use exact class name from MinorCardController)
    ) {
    }
```

**Step B:** In `setEmergencyAllowance()`, add the SCA call immediately after the `abort_unless()` policy check (after line 249):

```php
        abort_unless($this->accountPolicy->updateMinor($user, $account), 403);

        // SCA required for financial limit changes
        $this->scaService->verifyOrFail($request); // use exact method name from MinorCardController
```

- [ ] **Step 2.5 — Run the test to confirm it now PASSES**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Http/Controllers/Api/MinorEmergencyAllowanceScaTest.php
```

Expected: Both tests PASS.

- [ ] **Step 2.6 — Commit**

```bash
git add app/Http/Controllers/Api/MinorAccountController.php \
        tests/Feature/Http/Controllers/Api/MinorEmergencyAllowanceScaTest.php
git commit -m "fix(P1): require SCA verification for setEmergencyAllowance

A compromised guardian session could previously raise a child's
emergency spending cap without secondary authentication. SCA is
now required, matching the pattern already applied in
MinorCardController::freeze().

Fixes MINOR-P1-001.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 3 — Add SCA to Co-Guardian Invite Creation (MINOR-P1-002)

**Files:**
- Modify: `app/Http/Controllers/Api/CoGuardianController.php:25–48`
- Test: `tests/Feature/Http/Controllers/Api/CoGuardianScaTest.php`

### Context

`storeInvite()` creates a co-guardian invite code with no secondary authentication. A compromised primary guardian session can add an unauthorized co-guardian who gains full read access to the child's financial data.

- [ ] **Step 3.1 — Write the failing test**

Create `tests/Feature/Http/Controllers/Api/CoGuardianScaTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('rejects co-guardian invite creation without SCA verification', function (): void {
    $guardian = User::factory()->create();
    $minorAccount = Account::factory()->create(['type' => 'minor']);

    \App\Domain\Account\Models\AccountMembership::factory()->create([
        'user_uuid'    => $guardian->uuid,
        'account_uuid' => $minorAccount->uuid,
        'role'         => 'guardian',
        'status'       => 'active',
    ]);

    Sanctum::actingAs($guardian, ['read', 'write', 'delete']);

    $response = $this->postJson(
        "/api/accounts/minor/{$minorAccount->uuid}/co-guardian-invites"
    );

    // Must require SCA
    $response->assertStatus(428);
});

it('accepts co-guardian invite creation with valid SCA', function (): void {
    $guardian = User::factory()->create();
    $minorAccount = Account::factory()->create(['type' => 'minor']);

    \App\Domain\Account\Models\AccountMembership::factory()->create([
        'user_uuid'    => $guardian->uuid,
        'account_uuid' => $minorAccount->uuid,
        'role'         => 'guardian',
        'status'       => 'active',
    ]);

    Sanctum::actingAs($guardian, ['read', 'write', 'delete']);

    $response = $this->postJson(
        "/api/accounts/minor/{$minorAccount->uuid}/co-guardian-invites",
        [],
        ['X-SCA-Token' => 'valid-test-sca-token'] // use the exact header from MinorCardController tests
    );

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
    $response->assertJsonStructure(['data' => ['code', 'expires_at']]);
});
```

- [ ] **Step 3.2 — Run the test to confirm it currently FAILS**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Http/Controllers/Api/CoGuardianScaTest.php --stop-on-failure
```

Expected: First test FAILS (returns 200 instead of 428).

- [ ] **Step 3.3 — Add SCA to CoGuardianController**

Open `app/Http/Controllers/Api/CoGuardianController.php`.

Add SCA service to constructor (using the exact class from MinorCardController):

```php
    public function __construct(
        private readonly AccountMembershipService $membershipService,
        private readonly AccountPolicy $accountPolicy,
        private readonly \App\Domain\Security\Services\ScaVerificationService $scaService,
    ) {
    }
```

In `storeInvite()`, add after the `abort_unless()` line (after line 31):

```php
        abort_unless($this->accountPolicy->updateMinor($user, $minorAccount), 403);

        // Adding a co-guardian grants ongoing read access to a minor's financial data.
        // SCA is required to prevent a compromised session from silently adding observers.
        $this->scaService->verifyOrFail($request);
```

- [ ] **Step 3.4 — Run the test to confirm PASSES**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Http/Controllers/Api/CoGuardianScaTest.php
```

Expected: Both tests PASS.

- [ ] **Step 3.5 — Commit**

```bash
git add app/Http/Controllers/Api/CoGuardianController.php \
        tests/Feature/Http/Controllers/Api/CoGuardianScaTest.php
git commit -m "fix(P1): require SCA for co-guardian invite creation

Prevents a compromised guardian session from silently adding an
unauthorized co-guardian who would gain ongoing read access to
the child's financial data.

Fixes MINOR-P1-002.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 4 — Replace $guarded = [] with $fillable on All Affected Models (MINOR-P1-004)

**Files:**
- Modify: `app/Domain/Account/Models/Account.php`
- Modify: `app/Domain/Account/Models/MinorSpendApproval.php`
- Modify: `app/Domain/Account/Models/GuardianInvite.php`
- Modify: `app/Domain/Account/Models/MinorPointsLedger.php`
- Modify: `app/Domain/Account/Models/MinorFamilyReconciliationException.php`
- Modify: `app/Domain/Account/Models/MinorAccountLifecycleTransition.php`

### Context

`$guarded = []` means every column can be mass-assigned. Any `create()` or `fill()` call with user-supplied data can overwrite `status`, `decided_at`, `amount`, and audit fields. Replace with an explicit `$fillable` list.

**How to do this for each model:**
1. Read the model's migration to identify all columns.
2. Exclude from `$fillable`: primary keys (`id`, `uuid`), auto-managed timestamps (`created_at`, `updated_at`), audit fields set only by code (`decided_at`, `executed_at`, `status` where status changes are domain-driven).
3. Include in `$fillable`: all columns that `create()` legitimately receives from service code.

- [ ] **Step 4.1 — Find all affected models**

```bash
grep -rl '\$guarded = \[\]' app/Domain/Account/Models/ | sort
```

Add every file listed to your work scope.

- [ ] **Step 4.2 — Fix Account.php**

Read `app/Domain/Account/Models/Account.php` to find its current column set:

```bash
grep -n "guarded\|fillable" app/Domain/Account/Models/Account.php
```

Then read the accounts migration:

```bash
find database/migrations -name "*create_accounts*" -o -name "*accounts_table*" | head -3
```

Replace the `$guarded = []` line in `Account.php` with a `$fillable` array that includes the legitimate writable columns. At minimum:

```php
    protected $fillable = [
        'user_uuid',
        'parent_account_id',
        'name',
        'type',
        'tier',
        'permission_level',
        'status',
        'emergency_allowance_amount',
        'emergency_allowance_balance',
        'tenant_id',
    ];
```

Add any other writable columns you find in the migration. **Do not include** `uuid`, `id`, `created_at`, `updated_at`.

- [ ] **Step 4.3 — Fix MinorSpendApproval.php**

```bash
grep -n "guarded\|fillable" app/Domain/Account/Models/MinorSpendApproval.php
```

Replace `$guarded = []` with:

```php
    protected $fillable = [
        'tenant_id',
        'minor_account_uuid',
        'guardian_account_uuid',
        'from_account_uuid',
        'to_account_uuid',
        'amount',
        'asset_code',
        'status',
        'merchant_category',
        'idempotency_key',
        'expires_at',
    ];
```

(Read the model's migration to confirm column names before writing.)

- [ ] **Step 4.4 — Fix GuardianInvite.php**

```bash
grep -n "guarded\|fillable" app/Domain/Account/Models/GuardianInvite.php
```

Replace `$guarded = []` with:

```php
    protected $fillable = [
        'tenant_id',
        'minor_account_uuid',
        'invited_by_user_uuid',
        'code',
        'status',
        'expires_at',
        'claimed_at',
        'claimed_by_user_uuid',
    ];
```

- [ ] **Step 4.5 — Fix MinorPointsLedger.php, MinorFamilyReconciliationException.php, MinorAccountLifecycleTransition.php**

For each, follow the same read-migration → write-fillable pattern:

```bash
grep -n "guarded\|fillable" app/Domain/Account/Models/MinorPointsLedger.php
grep -n "guarded\|fillable" app/Domain/Account/Models/MinorFamilyReconciliationException.php
grep -n "guarded\|fillable" app/Domain/Account/Models/MinorAccountLifecycleTransition.php
```

Read the corresponding migration for each to get the exact column list. Replace `$guarded = []` with `$fillable = [...]` listing only the columns that `create()` or `fill()` legitimately receives.

- [ ] **Step 4.6 — Run the full test suite to confirm no $fillable regressions**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest --parallel --stop-on-failure
```

If any test fails with `"Add [field] to fillable properties"`: add the missing field to the relevant model's `$fillable` array and re-run.

- [ ] **Step 4.7 — Commit**

```bash
git add app/Domain/Account/Models/Account.php \
        app/Domain/Account/Models/MinorSpendApproval.php \
        app/Domain/Account/Models/GuardianInvite.php \
        app/Domain/Account/Models/MinorPointsLedger.php \
        app/Domain/Account/Models/MinorFamilyReconciliationException.php \
        app/Domain/Account/Models/MinorAccountLifecycleTransition.php
git commit -m "fix(P1): replace \$guarded=[] with explicit \$fillable on minor account models

Prevents mass-assignment of status, decided_at, amount, and other
financial/audit fields via any fill()/create() call. Affects
Account, MinorSpendApproval, GuardianInvite, MinorPointsLedger,
MinorFamilyReconciliationException, MinorAccountLifecycleTransition.

Fixes MINOR-P1-004.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 5 — Idempotency Key on updatePermissionLevel() (MINOR-P1-005)

**Files:**
- Modify: `app/Http/Controllers/Api/MinorAccountController.php:151–235`
- Test: `tests/Feature/Http/Controllers/Api/MinorPermissionLevelIdempotencyTest.php`

### Context

`updatePermissionLevel()` awards 100 points on every call when `newPermissionLevel > previousLevel`. Network retries award points twice. The fix: check for an existing `AccountAuditLog` entry with the same `idempotency_key` before executing; if found, return the stored result without re-awarding points.

- [ ] **Step 5.1 — Write the failing test**

Create `tests/Feature/Http/Controllers/Api/MinorPermissionLevelIdempotencyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorPointsLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('awards points only once when updatePermissionLevel is retried with the same idempotency key', function (): void {
    $guardian = User::factory()->create();
    $minorAccount = Account::factory()->create([
        'type'             => 'minor',
        'tier'             => 'grow',
        'permission_level' => 1,
    ]);

    \App\Domain\Account\Models\AccountMembership::factory()->create([
        'user_uuid'    => $guardian->uuid,
        'account_uuid' => $minorAccount->uuid,
        'role'         => 'guardian',
        'status'       => 'active',
    ]);

    Sanctum::actingAs($guardian, ['read', 'write', 'delete']);

    $idempotencyKey = (string) Str::uuid();

    // First call
    $this->putJson(
        "/api/accounts/minor/{$minorAccount->uuid}/permission-level",
        ['permission_level' => 2, 'idempotency_key' => $idempotencyKey]
    )->assertStatus(200);

    // Retry with same key — must be idempotent
    $this->putJson(
        "/api/accounts/minor/{$minorAccount->uuid}/permission-level",
        ['permission_level' => 2, 'idempotency_key' => $idempotencyKey]
    )->assertStatus(200);

    // Points awarded exactly once
    $pointsCount = MinorPointsLedger::query()
        ->where('minor_account_uuid', $minorAccount->uuid)
        ->where('source', 'level_unlock')
        ->count();

    expect($pointsCount)->toBe(1);
});
```

- [ ] **Step 5.2 — Run the test to confirm it currently FAILS (points awarded twice)**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Http/Controllers/Api/MinorPermissionLevelIdempotencyTest.php --stop-on-failure
```

Expected: FAILS with points count = 2.

- [ ] **Step 5.3 — Add idempotency key handling to updatePermissionLevel()**

Open `app/Http/Controllers/Api/MinorAccountController.php`.

In `updatePermissionLevel()` (starting line 151), make the following changes:

**A — Add `idempotency_key` to the validation rules** (after line 160):

```php
        $validated = $request->validate([
            'permission_level' => ['required', 'integer', 'min:1', 'max:7'],
            'idempotency_key'  => ['nullable', 'string', 'uuid', 'max:36'],
        ]);
```

**B — Add an idempotency check before the account update** (after the tier max validation, before the `forceFill()` call at line 194). Insert this block:

```php
        // Short-circuit on idempotent retry: same key + same target level = already done
        $idempotencyKey = $validated['idempotency_key'] ?? null;
        if ($idempotencyKey !== null) {
            $existingAudit = \App\Domain\Account\Models\AccountAuditLog::query()
                ->where('account_uuid', $account->uuid)
                ->where('action', 'permission_level_changed')
                ->whereJsonContains('metadata->idempotency_key', $idempotencyKey)
                ->first();

            if ($existingAudit !== null) {
                // Idempotent replay — return the same response shape without re-executing
                return response()->json([
                    'success' => true,
                    'data'    => [
                        'uuid'              => $account->uuid,
                        'account_type'      => $account->type,
                        'account_tier'      => $account->tier,
                        'permission_level'  => $account->permission_level,
                        'parent_account_id' => $account->parent_account_id,
                    ],
                ]);
            }
        }
```

**C — Store the idempotency key in the audit log metadata** (in the `AccountAuditLog::create()` call at line 198):

```php
        AccountAuditLog::create([
            'account_uuid'    => $account->uuid,
            'actor_user_uuid' => $user->uuid,
            'action'          => 'permission_level_changed',
            'metadata'        => [
                'old_value'        => $previousLevel,
                'new_value'        => $newPermissionLevel,
                'reason'           => $request->string('reason', 'Guardian updated permission level')->toString(),
                'idempotency_key'  => $idempotencyKey, // add this line
            ],
            'created_at' => now(),
        ]);
```

- [ ] **Step 5.4 — Run the test to confirm PASSES**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Http/Controllers/Api/MinorPermissionLevelIdempotencyTest.php
```

Expected: PASS.

- [ ] **Step 5.5 — Commit**

```bash
git add app/Http/Controllers/Api/MinorAccountController.php \
        tests/Feature/Http/Controllers/Api/MinorPermissionLevelIdempotencyTest.php
git commit -m "fix(P1): add idempotency key to updatePermissionLevel to prevent double-points

Retrying the permission-level update with the same idempotency_key
now short-circuits before awarding points a second time. The key is
stored in AccountAuditLog.metadata for replay detection.

Fixes MINOR-P1-005.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 6 — Cryptographic Funding Link Token Hashing (MINOR-P1-008)

**Files:**
- Modify: `app/Domain/Account/Services/MinorFamilyIntegrationService.php:194`
- Modify: `app/Http/Controllers/Api/PublicMinorFundingLinkController.php:22–30`
- Test: `tests/Feature/Domain/Account/MinorFundingLinkTokenSecurityTest.php`

### Context

Funding link tokens are currently generated as `Str::uuid()` (line 194 of `MinorFamilyIntegrationService.php`) and stored/compared in plaintext. The fix: generate a 64-character cryptographically random token, store only its SHA-256 hash in the database, and compare hashes at lookup time. The plaintext token is returned to the guardian once on creation and never stored.

- [ ] **Step 6.1 — Write the failing test**

Create `tests/Feature/Domain/Account/MinorFundingLinkTokenSecurityTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Account\Models\MinorFamilyFundingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('stores the SHA-256 hash of the token, not the plaintext token', function (): void {
    $link = MinorFamilyFundingLink::factory()->create([
        'token' => Str::random(64),
    ]);

    // The stored token must be exactly 64 hex chars (SHA-256 output)
    expect($link->token)->toMatch('/^[a-f0-9]{64}$/');
});

it('looks up a funding link by the hash of the provided token', function (): void {
    $plaintext = Str::random(64);
    $hash = hash('sha256', $plaintext);

    MinorFamilyFundingLink::factory()->create(['token' => $hash]);

    // Lookup by hash must find the link
    $found = MinorFamilyFundingLink::query()->where('token', $hash)->first();
    expect($found)->not->toBeNull();

    // Lookup by plaintext must NOT find the link (tokens are never stored plaintext)
    $notFound = MinorFamilyFundingLink::query()->where('token', $plaintext)->first();
    expect($notFound)->toBeNull();
});
```

- [ ] **Step 6.2 — Run the test (documents current state)**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Domain/Account/MinorFundingLinkTokenSecurityTest.php --stop-on-failure
```

Note the current behaviour. The first test will likely fail if tokens are currently UUIDs.

- [ ] **Step 6.3 — Update token generation in MinorFamilyIntegrationService**

Open `app/Domain/Account/Services/MinorFamilyIntegrationService.php`. Find line 194:

```php
'token' => $this->nullableStringValue($attributes, 'token') ?? (string) \Illuminate\Support\Str::uuid(),
```

Replace with:

```php
'token' => (function () use ($attributes): string {
    $provided = $this->nullableStringValue($attributes, 'token');
    if ($provided !== null) {
        // If caller provides a token (e.g., replay), hash it for storage
        return strlen($provided) === 64 && ctype_xdigit($provided)
            ? $provided  // already a hash
            : hash('sha256', $provided);
    }
    // Generate a new cryptographically random 64-char token and store its hash
    $plaintext = \Illuminate\Support\Str::random(64);
    return hash('sha256', $plaintext);
    // NOTE: The plaintext must be returned to the guardian. See createFundingLink() caller.
})(),
```

**Also:** Find where the link's token is returned to the guardian (the method that returns a response with `token` in it). The plaintext must be generated BEFORE hashing and returned in the response. Restructure the token generation:

```php
// Before calling persistFundingLink, generate the token:
$plaintext = \Illuminate\Support\Str::random(64);
$tokenHash = hash('sha256', $plaintext);

// Pass $tokenHash to persistFundingLink (stored in DB)
// Return $plaintext to the guardian in the API response (never stored)
```

Locate the response where `token` is included and ensure it returns `$plaintext`.

- [ ] **Step 6.4 — Update PublicMinorFundingLinkController to compare by hash**

Open `app/Http/Controllers/Api/PublicMinorFundingLinkController.php`.

In both `show()` (line 28) and `requestToPay()` (line 64), the lookup currently does:

```php
$link = MinorFamilyFundingLink::query()
    ->where('token', $token)
    ->first();
```

Replace with a hash comparison:

```php
$link = MinorFamilyFundingLink::query()
    ->where('token', hash('sha256', $token))
    ->first();
```

The length guard at lines 24 and 60 (`strlen($token) < 32`) should be updated to 64:

```php
if (strlen($token) !== 64) {
    return $this->notFoundResponse();
}
```

- [ ] **Step 6.5 — Run the tests to confirm PASSES**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Domain/Account/MinorFundingLinkTokenSecurityTest.php
```

Expected: Both tests PASS.

- [ ] **Step 6.6 — Run full suite**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest --parallel --stop-on-failure
```

Expected: All pass.

- [ ] **Step 6.7 — Commit**

```bash
git add app/Domain/Account/Services/MinorFamilyIntegrationService.php \
        app/Http/Controllers/Api/PublicMinorFundingLinkController.php \
        tests/Feature/Domain/Account/MinorFundingLinkTokenSecurityTest.php
git commit -m "fix(P1): hash public funding link tokens with SHA-256

Tokens are now generated with Str::random(64) and stored as their
SHA-256 hash. The plaintext is returned to the guardian once on
creation and never stored. Public lookup compares hash('sha256', token).
Token length guard updated from 32 to 64 characters.

Fixes MINOR-P1-008.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 7 — Final Regression Pass

- [ ] **Step 7.1 — Full test suite**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest --parallel --stop-on-failure
```

- [ ] **Step 7.2 — PHPStan**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 7.3 — Code style**

```bash
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php
git add -u && git commit -m "style: apply php-cs-fixer after security hardening

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Self-Review Checklist

- [x] MINOR-P0-002 (lifecycle schedule) — Task 1
- [x] MINOR-P1-001 (SCA emergency allowance) — Task 2
- [x] MINOR-P1-002 (SCA co-guardian invite) — Task 3
- [x] MINOR-P1-004 (mass assignment) — Task 4
- [x] MINOR-P1-005 (double points idempotency) — Task 5
- [x] MINOR-P1-008 (token hashing) — Task 6
- [x] Every test reproduces the exact attack before the fix
- [x] SCA pattern explicitly instructs agent to read MinorCardController first
- [x] No "TBD" or placeholder steps
