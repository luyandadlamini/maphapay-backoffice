# Minor Accounts — Compliance, PII & Authorization Cleanup

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Three compliance and quality fixes: (1) hide `date_of_birth` from API serialization so minor PII is never accidentally leaked in JSON responses; (2) write a dual-sided audit log entry for guardian permission-level changes (one on the minor account, one on the guardian account); (3) standardize authorization on `MinorChoreController`, `MinorRewardController`, and `MinorCardController` to use Gate/Policy instead of ad-hoc `abort(403)` calls.

**Architecture:** Surgical edits — model `$hidden`, one additional `AccountAuditLog::create()` call, and three new Policy classes with matching `authorize()` calls in controllers. No new service layers.

**Tech Stack:** PHP 8.4, Laravel 12, Pest, Laravel Gate/Policy.

**Findings addressed:** MINOR-P3-001 · MINOR-P3-003 · MINOR-P3-004

---

## File Map

| Action | File | Finding |
|--------|------|---------|
| Modify | `app/Domain/User/Models/UserProfile.php` | MINOR-P3-003 |
| Modify | `app/Http/Controllers/Api/MinorAccountController.php` | MINOR-P3-004 |
| Create | `app/Domain/Account/Policies/ChorePolicy.php` | MINOR-P3-001 |
| Create | `app/Domain/Account/Policies/RewardPolicy.php` | MINOR-P3-001 |
| Create | `app/Domain/Account/Policies/MinorCardPolicy.php` | MINOR-P3-001 |
| Modify | `app/Providers/AuthServiceProvider.php` (or Gate::policy calls) | MINOR-P3-001 |
| Modify | `app/Http/Controllers/Api/MinorChoreController.php` | MINOR-P3-001 |
| Modify | `app/Http/Controllers/Api/MinorRewardController.php` | MINOR-P3-001 |

---

## Task 1 — Hide date_of_birth from UserProfile Serialization (MINOR-P3-003)

**Files:**
- Modify: `app/Domain/User/Models/UserProfile.php`

### Context

`UserProfile` has `date_of_birth` in `$fillable` and `$casts` but no `$hidden` declaration. If this model is accidentally serialized (e.g., `->toArray()`, `->toJson()`, eager-loaded in an API response), the minor's date of birth (PII) is exposed. Adding `$hidden` prevents serialization without affecting query results or casts.

- [ ] **Step 1.1 — Add $hidden to UserProfile**

Open `app/Domain/User/Models/UserProfile.php`. After the `$fillable` property, add:

```php
protected $hidden = [
    'date_of_birth', // PII — never serialize to API responses; use explicit ->select() for age calculations
];
```

- [ ] **Step 1.2 — Verify no existing API tests break**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/ --filter=Minor --parallel --stop-on-failure
```

If any test asserts on `date_of_birth` being present in a JSON response (it shouldn't), that test is revealing a PII leak — fix the test, not the model.

- [ ] **Step 1.3 — Commit**

```bash
git add app/Domain/User/Models/UserProfile.php
git commit -m "fix(P3): hide date_of_birth from UserProfile serialization

Prevents the minor's date of birth (PII) from being accidentally
included in API JSON responses when UserProfile is serialized.
Explicit ->select(['date_of_birth']) still works for age calculations.

Fixes MINOR-P3-003.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 2 — Write Guardian-Side Audit Log for Permission Level Changes (MINOR-P3-004)

**Files:**
- Modify: `app/Http/Controllers/Api/MinorAccountController.php`

### Context

`updatePermissionLevel()` writes an `AccountAuditLog` entry to the **minor** account (`account_uuid = $account->uuid`). The **guardian** account has no corresponding record. Regulators reviewing the guardian's account history cannot see which permission changes they authorized.

The guardian's account UUID is available via `$account->parent_account_id` (which is the guardian's account UUID).

- [ ] **Step 2.1 — Read the existing audit log creation site**

```bash
grep -n "AccountAuditLog::create\|account_uuid.*actor\|actor_user_uuid" \
  app/Http/Controllers/Api/MinorAccountController.php | head -10
```

Note the exact line where `AccountAuditLog::create([...])` is called in `updatePermissionLevel()`.

- [ ] **Step 2.2 — Add a second audit log entry for the guardian's account**

After the existing `AccountAuditLog::create([...])` call in `updatePermissionLevel()`, add:

```php
// Dual-sided audit: also log on the guardian's account for their activity history
if ($account->parent_account_id !== null) {
    AccountAuditLog::create([
        'account_uuid'    => $account->parent_account_id,
        'actor_user_uuid' => $user->uuid,
        'action'          => 'minor_permission_level_changed',
        'metadata'        => [
            'minor_account_uuid' => $account->uuid,
            'old_value'          => $previousLevel,
            'new_value'          => $newPermissionLevel,
            'reason'             => $request->string('reason', 'Guardian updated permission level')->toString(),
        ],
        'created_at' => now(),
    ]);
}
```

The action name `minor_permission_level_changed` differs from `permission_level_changed` to make it searchable from either side.

- [ ] **Step 2.3 — Run the permission level tests**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/ --filter=PermissionLevel --parallel --stop-on-failure
```

Also run the full minor controller tests:

```bash
./vendor/bin/pest tests/Feature/Http/Controllers/Api/MinorAccountControllerTest.php --stop-on-failure
```

Expected: All pass.

- [ ] **Step 2.4 — Commit**

```bash
git add app/Http/Controllers/Api/MinorAccountController.php
git commit -m "fix(P3): add guardian-account audit log for permission level changes

updatePermissionLevel() now writes a second AccountAuditLog entry
to the guardian's account (parent_account_id) with action
'minor_permission_level_changed', enabling dual-sided compliance
reporting from either account's history.

Fixes MINOR-P3-004.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 3 — Standardize Authorization to Policy Pattern (MINOR-P3-001)

**Files:**
- Create: `app/Domain/Account/Policies/ChorePolicy.php`
- Create: `app/Domain/Account/Policies/RewardPolicy.php`
- Create: `app/Domain/Account/Policies/MinorCardPolicy.php`
- Modify: Relevant controllers

### Context

Some minor controllers use `$this->authorize()` via `AccountPolicy`, others use bare `abort(403)` or ad-hoc service calls. `MinorChoreController` (line 139–150 in the audit) was specifically cited for using `abort(403)` directly. Read the existing `AccountPolicy` as the pattern reference before writing new policies.

- [ ] **Step 3.1 — Read AccountPolicy and existing controllers**

```bash
cat app/Domain/Account/Policies/AccountPolicy.php | head -60
grep -n "abort.*403\|abort_unless\|authorize\|can(" \
  app/Http/Controllers/Api/MinorChoreController.php | head -20
grep -n "abort.*403\|abort_unless\|authorize\|can(" \
  app/Http/Controllers/Api/MinorRewardController.php 2>/dev/null | head -20
```

List every ad-hoc authorization pattern found.

- [ ] **Step 3.2 — Create ChorePolicy**

Create `app/Domain/Account/Policies/ChorePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account\Policies;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Services\AccountMembershipService;
use App\Models\User;

class ChorePolicy
{
    public function __construct(
        private readonly AccountMembershipService $membershipService,
        private readonly AccountPolicy $accountPolicy,
    ) {}

    public function view(User $user, Account $minorAccount): bool
    {
        return $this->accountPolicy->viewMinor($user, $minorAccount)
            || $this->membershipService->isGuardian($user, $minorAccount)
            || $this->membershipService->isMinorOwner($user, $minorAccount);
    }

    public function create(User $user, Account $minorAccount): bool
    {
        return $this->membershipService->isMinorOwner($user, $minorAccount);
    }

    public function approve(User $user, Account $minorAccount): bool
    {
        return $this->membershipService->isGuardian($user, $minorAccount);
    }

    public function reject(User $user, Account $minorAccount): bool
    {
        return $this->membershipService->isGuardian($user, $minorAccount);
    }
}
```

**Check `AccountMembershipService` for the exact method names** (`isGuardian`, `isMinorOwner`, or similar) before writing:

```bash
grep -n "public function.*guardian\|public function.*minor.*owner\|public function.*member" \
  app/Domain/Account/Services/AccountMembershipService.php | head -15
```

Adjust method names to match what actually exists.

- [ ] **Step 3.3 — Create RewardPolicy**

Create `app/Domain/Account/Policies/RewardPolicy.php` following the same pattern as `ChorePolicy`. Rewards are typically view/create from the child, and approve from the guardian — verify against `MinorRewardController` before deciding.

- [ ] **Step 3.4 — Create MinorCardPolicy**

Create `app/Domain/Account/Policies/MinorCardPolicy.php`. Card operations:
- `request()` — guardian only
- `approve()` — guardian only
- `deny()` — guardian only
- `freeze()` / `unfreeze()` — guardian only (already SCA-gated in `MinorCardController`)
- `view()` — guardian or minor owner

Model this on the `AccountPolicy` pattern.

- [ ] **Step 3.5 — Register the policies**

Open `app/Providers/AuthServiceProvider.php` (or wherever `Gate::policy()` registrations live). Add:

```php
Gate::policy(\App\Domain\Account\Models\MinorChore::class, \App\Domain\Account\Policies\ChorePolicy::class);
Gate::policy(\App\Domain\Account\Models\MinorReward::class, \App\Domain\Account\Policies\RewardPolicy::class);
Gate::policy(\App\Domain\CardIssuance\Models\Card::class, \App\Domain\Account\Policies\MinorCardPolicy::class);
```

Check the exact model class names:

```bash
find app/Domain/Account/Models -name "MinorChore*.php" -o -name "MinorReward*.php" | sort
```

- [ ] **Step 3.6 — Replace abort(403) calls in MinorChoreController**

For each `abort(403)` in `MinorChoreController`:

```php
// BEFORE
if (! $this->someCheck()) {
    abort(403);
}

// AFTER
$this->authorize('approve', $minorAccount);
```

Map each bare `abort(403)` to the correct policy method based on what the operation does.

- [ ] **Step 3.7 — Run authorization tests**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/ --filter=Chore,Reward,Card --parallel --stop-on-failure
```

- [ ] **Step 3.8 — Commit**

```bash
git add app/Domain/Account/Policies/ChorePolicy.php \
        app/Domain/Account/Policies/RewardPolicy.php \
        app/Domain/Account/Policies/MinorCardPolicy.php \
        app/Providers/AuthServiceProvider.php \
        app/Http/Controllers/Api/MinorChoreController.php \
        app/Http/Controllers/Api/MinorRewardController.php
git commit -m "fix(P3): standardize authorization to Policy pattern for chores, rewards, and cards

Replaces bare abort(403) calls with Gate/Policy via authorize().
ChorePolicy, RewardPolicy, and MinorCardPolicy follow the existing
AccountPolicy pattern for testability and auditability.

Fixes MINOR-P3-001.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 4 — Final Regression Pass

- [ ] **Step 4.1 — Full minor test suite**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/ --filter=Minor --parallel --stop-on-failure
```

- [ ] **Step 4.2 — PHPStan**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 4.3 — Code style**

```bash
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php
git add -u && git commit -m "style: apply php-cs-fixer after compliance and PII fixes

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Self-Review Checklist

- [x] MINOR-P3-003 (date_of_birth hidden) — Task 1
- [x] MINOR-P3-004 (guardian-side audit log) — Task 2
- [x] MINOR-P3-001 (policy standardization) — Task 3
- [x] Instructions to read existing patterns before writing new policies
- [x] No placeholder steps
