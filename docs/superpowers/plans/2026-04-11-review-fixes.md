# Implementation Review Fixes — 2026-04-11

Source: Full implementation review of all completed tracker sections in
`docs/superpowers/plans/2026-04-07-implementation-tracker.md`.

These are the concrete gaps and fixes identified. Implement them end to end.
Do not skip any item. After all fixes are applied, run the verification
commands in each section and confirm they pass before marking the item done.

---

## Fix 1 — `AccountService::freeze()`/`unfreeze()` bypass the domain event stream

### Severity
HIGH — correctness / event-sourcing contract

### Problem

`app/Domain/Account/Services/AccountService.php` lines 169–186:

```php
public function freeze(mixed $uuid): void
{
    $accountUuid = __account_uuid($uuid);
    \App\Domain\Account\Models\Account::where('uuid', $accountUuid)
        ->update(['frozen' => true]);
}

public function unfreeze(mixed $uuid): void
{
    $accountUuid = __account_uuid($uuid);
    \App\Domain\Account\Models\Account::where('uuid', $accountUuid)
        ->update(['frozen' => false]);
}
```

Both methods mutate the `accounts.frozen` column directly. This is wrong in an
event-sourced system. The domain already has the full pipeline:

- `LedgerAggregate::freezeAccount(string $reason, ?string $authorizedBy)` — records the event
- `AccountFrozen` / `AccountUnfrozen` — `ShouldBeStored` events in `app/Domain/Account/Events/`
- `AccountProjector::onAccountFrozen` / `onAccountUnfrozen` — delegates to `FreezeAccount` /
  `UnfreezeAccount` actions which do the actual `update(['frozen' => true/false])`

The bypass means:
1. No event is written to the `stored_events` table for the `LedgerAggregate` aggregate root.
2. Aggregate replay will **silently lose** all freeze/unfreeze state — projections replayed from
   the event stream will never see the DB mutation done by `AccountService`.
3. The `$reason` and `$authorizedBy` metadata required by `AccountFrozen` / `AccountUnfrozen`
   is never captured.

There are TODO comments in the file acknowledging this gap (lines 167, 179).

### The existing callers

The primary callers are in `AccountResource.php` (the Filament admin resource). Search for:

```bash
rg -n "accountService->freeze\|accountService->unfreeze\|AccountService.*freeze\|freeze.*AccountService" app
```

Look also for any Artisan commands, console schedules, or queue jobs that call
`AccountService::freeze()` / `unfreeze()` directly. Each call site must be updated to pass a
`$reason` string.

### Fix

Replace the direct model mutations in `AccountService` with aggregate-routed calls:

```php
public function freeze(mixed $uuid, string $reason = 'backoffice_action', ?string $authorizedBy = null): void
{
    $accountUuid = __account_uuid($uuid);

    LedgerAggregate::retrieve($accountUuid)
        ->freezeAccount(reason: $reason, authorizedBy: $authorizedBy)
        ->persist();
}

public function unfreeze(mixed $uuid, string $reason = 'backoffice_action', ?string $authorizedBy = null): void
{
    $accountUuid = __account_uuid($uuid);

    LedgerAggregate::retrieve($accountUuid)
        ->unfreezeAccount(reason: $reason, authorizedBy: $authorizedBy)
        ->persist();
}
```

Remove the TODO comments. The `LedgerAggregate` import is already at the top of the file
(line 7: `use App\Domain\Account\Aggregates\LedgerAggregate;`).

The `FreezeAccount` / `UnfreezeAccount` projector actions already do the DB write
(`update(['frozen' => true/false])`), so the end-state of `accounts.frozen` is identical.
The difference is that the event is now stored and the aggregate root UUID is the account UUID
so replay works correctly.

### Call site updates in `AccountResource`

Search for all places `AccountService::freeze()` / `unfreeze()` are called and add a `$reason`
string. The Filament action context gives you the admin user identity. Pass it as `$authorizedBy`:

```php
// Example — inside a Filament action closure where $actor is the auth user:
$this->accountService->freeze(
    $record->uuid,
    reason: 'admin_freeze',
    authorizedBy: auth()->user()?->email,
);
```

### Tests to write / update

1. Assert that after `AccountService::freeze($uuid)`, a `stored_events` record exists with:
   - `event_class = AccountFrozen::class`
   - `aggregate_uuid = $accountUuid`
2. Assert that `accounts.frozen = true` for the affected record (existing projection path).
3. Assert that replaying the aggregate from stored events restores the `frozen = true` state
   without the direct mutation (regression test for the original bug).
4. Mirror for `unfreeze()`.
5. Update any existing tests that stub/mock `AccountService::freeze()` to reflect the new
   signature (`$reason`, `$authorizedBy` parameters).

### Verification commands

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse app/Domain/Account/Services/AccountService.php \
  app/Domain/Account/Aggregates/LedgerAggregate.php \
  app/Domain/Account/Projectors/AccountProjector.php \
  app/Domain/Account/Actions/FreezeAccount.php \
  app/Domain/Account/Actions/UnfreezeAccount.php \
  --memory-limit=2G

./vendor/bin/pest tests/Feature/Domain/Account/ --stop-on-failure
```

---

## Fix 2 — `BackofficeWorkspaceAccess` missing `'support'` workspace case

### Severity
HIGH — architectural consistency / silent-denial risk under extension

### Problem

`app/Support/Backoffice/BackofficeWorkspaceAccess.php` lines 20–27:

```php
return match ($workspace) {
    'platform_administration' => ...,
    'finance'                 => ...,
    'compliance'              => ...,
    default => false,         // <-- 'support' falls here and always returns false
};
```

`UserResource` (line 55) declares `$backofficeWorkspace = 'support'` and uses the
`HasBackofficeWorkspace` trait. Currently `UserResource` bypasses `canAccess()` by using its
own permission methods (`userCanViewUsers()`, `userCanRequestFreezeActions()`), so no live
request is broken today.

But the contract is inconsistent:
- `BackofficeWorkspaceAccess` is the workspace authority service.
- `'support'` is a declared workspace but `canAccess('support')` silently denies **everyone**
  including `super-admin`.
- Any future resource that calls `BackofficeWorkspaceAccess::canAccess('support')` as a gate
  will silently block all users with no error message — a regression that is hard to trace.

### UserResource's permission logic (reference for the fix)

From `app/Filament/Admin/Resources/UserResource.php`:

```php
public static function userCanViewUsers(): bool
{
    $user = auth()->user();
    return $user !== null && ($user->can('view-users') || $user->hasRole('super-admin'));
}

public static function userCanRequestFreezeActions(): bool
{
    $user = auth()->user();
    return $user !== null && ($user->can('freeze-users') || $user->hasRole('super-admin'));
}
```

The `'support'` workspace should be accessible to users who have `view-users` permission or
`super-admin`. This mirrors what `UserResource` already enforces.

### Fix

Add the `'support'` case to `BackofficeWorkspaceAccess::canAccess()`:

```php
return match ($workspace) {
    'platform_administration' => method_exists($user, 'hasRole') && $user->hasRole('super-admin'),
    'finance' => (method_exists($user, 'hasRole') && $user->hasRole('super-admin'))
        || (method_exists($user, 'can') && $user->can('approve-adjustments')),
    'compliance' => (method_exists($user, 'hasRole') && $user->hasRole('super-admin'))
        || (method_exists($user, 'hasRole') && $user->hasRole('compliance-manager')),
    'support' => (method_exists($user, 'hasRole') && $user->hasRole('super-admin'))
        || (method_exists($user, 'can') && $user->can('view-users')),
    default => false,
};
```

The `'view-users'` permission is the minimum support-hub access gate, matching
`UserResource::userCanViewUsers()` (minus the null check, which the outer `$user === null`
guard at line 16–18 already handles).

### Tests to write / update

Add a test to `BackofficeWorkspaceAccessTest` (or the nearest governance test file) that:

1. A user with `'view-users'` permission passes `canAccess('support')` → `true`.
2. A user with `'super-admin'` role passes `canAccess('support')` → `true`.
3. A user with no relevant permissions is denied `canAccess('support')` → `false`.
4. A user with only `'freeze-users'` (but not `'view-users'`) is denied → `false`. (Freeze
   is a narrower right; the gate is about workspace visibility, not action rights.)

### Verification commands

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse \
  app/Support/Backoffice/BackofficeWorkspaceAccess.php \
  --memory-limit=2G

./vendor/bin/pest tests/Feature/Backoffice/ --stop-on-failure
```

---

## Fix 3 — `CorporateActionPolicy::classify()` is bypassed in `submitApprovalRequest()`

### Severity
MEDIUM — policy enforcement dead code

### Problem

`app/Domain/Corporate/Services/CorporateActionPolicy.php`:

```php
public function classify(string $actionType, User $requester, Team $team): string
{
    return self::GOVERNED_ACTION_TYPES[$actionType] ?? 'blocked';
}
```

`classify()` returns `'blocked'` for any `$actionType` not in `GOVERNED_ACTION_TYPES`. This is
the policy gate that prevents unknown action types from entering the approval pipeline.

But `submitApprovalRequest()` (lines 35–57) does **not** call `classify()`. It creates a
`CorporateActionApprovalRequest` record for any `$actionType` string the caller passes in,
including completely unrecognized values.

The only caller, `CorporatePayoutBatchService::submitForApproval()` (line 133), happens to pass
`'treasury_affecting'` which is a valid type — so no live bug today. But the guard is silent
dead code. A future caller could pass anything and the `'blocked'` protection would never fire.

### Fix

Enforce `classify()` inside `submitApprovalRequest()` before creating the record:

```php
public function submitApprovalRequest(
    User $requester,
    Team $team,
    string $actionType,
    string $targetType,
    string $targetIdentifier,
    array $evidence = [],
): CorporateActionApprovalRequest {
    // Enforce classification: throw for unknown/blocked action types.
    $classification = $this->classify($actionType, $requester, $team);

    if ($classification === 'blocked') {
        throw new InvalidArgumentException(
            "Action type '{$actionType}' is not a governed corporate action type."
        );
    }

    $profile = $team->resolveCorporateProfile();

    /** @var CorporateActionApprovalRequest $request */
    $request = CorporateActionApprovalRequest::query()->create([
        'corporate_profile_id' => $profile->id,
        'action_type'          => $actionType,
        'action_status'        => 'pending',
        'requester_id'         => $requester->id,
        'target_type'          => $targetType,
        'target_identifier'    => $targetIdentifier,
        'evidence'             => $evidence === [] ? null : $evidence,
    ]);

    return $request;
}
```

The `InvalidArgumentException` import is already at the top of the file.

### Tests to write / update

Add to `CorporateActionPolicyTest` (or the nearest corporate policy test file):

1. Calling `submitApprovalRequest()` with an unrecognized `$actionType` throws
   `InvalidArgumentException` with a message containing the unknown type.
2. Calling `submitApprovalRequest()` with `'treasury_affecting'` still succeeds (regression).
3. Calling `submitApprovalRequest()` with `'membership_change'` still succeeds.

### Verification commands

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse \
  app/Domain/Corporate/Services/CorporateActionPolicy.php \
  app/Domain/Corporate/Services/CorporatePayoutBatchService.php \
  --memory-limit=2G

./vendor/bin/pest tests/Feature/Corporate/ --stop-on-failure
```

---

## Fix 4 — Dead code in `finalizeAtomically()` catch block

### Severity
LOW — code clarity / misleading logic

### Problem

`app/Domain/AuthorizedTransaction/Services/AuthorizedTransactionManager.php` lines 330–345:

```php
} catch (Throwable $e) {
    $currentStatus = AuthorizedTransaction::query()
        ->whereKey($txn->id)
        ->value('status');

    if ($currentStatus === AuthorizedTransaction::STATUS_COMPLETED) {
        DB::table('authorized_transactions')
            ->where('id', $txn->id)
            ->update([
                'status' => AuthorizedTransaction::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'updated_at' => now(),
            ]);
    }

    throw $e;
}
```

The `DB::transaction()` closure at line 288 updates status to `STATUS_COMPLETED` at line 293.
If the closure throws **after** that update, the entire DB transaction is rolled back — the
status reverts to `STATUS_PENDING`. The catch block then re-reads status from the DB, which is
`STATUS_PENDING` after rollback. The `STATUS_COMPLETED` branch is therefore unreachable.

The intent was likely to mark a transaction `FAILED` if the handler threw after a successful
atomic claim. But the `DB::transaction()` wrapper means a handler exception rolls back the
entire transaction, including the `STATUS_COMPLETED` set, so status is always `PENDING` in the
catch and the `COMPLETED` check never fires.

This is misleading: a reader would assume the `COMPLETED → FAILED` transition is live, but it
is never executed.

### Fix

Remove the unreachable branch. The correct behavior (PENDING after handler failure, retryable)
is already exercised and tested. The catch block should simply re-throw:

```php
} catch (Throwable $e) {
    throw $e;
}
```

Or, if error logging is desired before re-throw, log it — but do **not** add the
`STATUS_COMPLETED → STATUS_FAILED` transition without restructuring the DB transaction scope
to allow the status update to survive outside the rolled-back transaction.

If the future intent is to mark a failed transaction as non-retryable (FAILED) after the
handler throws, that requires moving the atomic claim **outside** the `DB::transaction()` scope,
which is a larger architectural change and should be a separate tracked item.

### Verification commands

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse \
  app/Domain/AuthorizedTransaction/Services/AuthorizedTransactionManager.php \
  --memory-limit=2G

./vendor/bin/pest tests/Feature/Http/Controllers/Api/Compatibility/SendMoney/ --stop-on-failure
```

---

## Fix 5 — Correct the Section 3 tracker note at line 152

### Severity
LOW — documentation accuracy

### Problem

The tracker note at line 152 of
`docs/superpowers/plans/2026-04-07-implementation-tracker.md` contains the claim:

> "Compliance worker verified `AmlScreeningResource` and `DataSubjectRequestResource` already
> fully governed (no changes needed). Support/user worker verified `UserResource` and `ViewUser`
> already fully governed with narrow PHPStan fixes only."

This is factually wrong. Commit `ce885513` ("feat: complete section 3 backoffice governance")
shows 182 lines changed in `AmlScreeningResource.php`, 191 lines changed in
`DataSubjectRequestResource.php`, and 380 lines changed in `UserResource.php`. These resources
were **not** pre-existing governed surfaces — they were hardened **as part of Section 3**.

This matters because:
- Future reviewers reading the tracker may skip those resources assuming they predate Section 3.
- Any blame/archaeological analysis will be incorrect.
- Downstream agents processing the tracker may over-trust the "already governed" claim.

### Fix

Update the note in the tracker to accurately reflect what happened:

Replace the affected sentence:

> "Compliance worker verified `AmlScreeningResource` and `DataSubjectRequestResource` already
> fully governed (no changes needed). Support/user worker verified `UserResource` and `ViewUser`
> already fully governed with narrow PHPStan fixes only."

With:

> "Compliance worker hardened `AmlScreeningResource` and `DataSubjectRequestResource` as part
> of this Section 3 slice (governance was not pre-existing). Support/user worker hardened
> `UserResource` and `ViewUser` as part of this Section 3 slice, with PHPStan type fixes also
> applied. All four surfaces are now governed via commit `ce885513`."

---

## Execution Order

Implement in this order to avoid test interference:

1. **Fix 5** (tracker note) — documentation only, zero code risk, do first.
2. **Fix 2** (`BackofficeWorkspaceAccess`) — additive, no behavior change for live surfaces,
   safe to apply before other fixes.
3. **Fix 3** (`CorporateActionPolicy`) — additive guard inside `submitApprovalRequest()`,
   tests must still pass.
4. **Fix 4** (`finalizeAtomically`) — simple removal of dead code branch, low risk.
5. **Fix 1** (`AccountService` domain events) — **most impactful**, do last so the others are
   stable before you touch the account domain. Requires writing tests to confirm event emission
   and aggregate replay before and after. Make sure the projector is not running asynchronously
   in tests (check `phpunit.xml` queue driver is `sync`).

---

## Final Verification — Run All After All Fixes Applied

```bash
# Code style
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php

# Static analysis — include all changed files
XDEBUG_MODE=off vendor/bin/phpstan analyse \
  app/Domain/Account/Services/AccountService.php \
  app/Domain/Account/Aggregates/LedgerAggregate.php \
  app/Support/Backoffice/BackofficeWorkspaceAccess.php \
  app/Domain/Corporate/Services/CorporateActionPolicy.php \
  app/Domain/Corporate/Services/CorporatePayoutBatchService.php \
  app/Domain/AuthorizedTransaction/Services/AuthorizedTransactionManager.php \
  --memory-limit=2G

# Test suites covering changed areas
./vendor/bin/pest \
  tests/Feature/Domain/Account/ \
  tests/Feature/Backoffice/ \
  tests/Feature/Corporate/ \
  tests/Feature/Http/Controllers/Api/Compatibility/SendMoney/ \
  --parallel --stop-on-failure
```

All four test suites must pass before marking this plan complete.

---

## Residual Risks (No Fix Required Now — Track Separately)

These are not defects in completed work but known gaps for future phases:

| Risk | Where | Action |
|---|---|---|
| Approval request reviewer UI does not exist | `AdminActionApprovalRequest` records created by all Section 3 governance, but no Filament resource to review/approve/reject them | Add to Section 5 or a dedicated "Governance Operations" phase tracker row |
| `CorporateTreasuryBoundary` has no runtime callers | `app/Domain/Corporate/Services/CorporateTreasuryBoundary.php` fully implemented but not wired into any payment/transfer flow | Track enforcement as part of card/payroll/expense phase; update tracker row wording from "enforce" to "define model" |
| iOS App Attest native collection unimplemented | Mobile RN repo — backend enforcement gate is disabled by default (`mobile.attestation.enabled = false`) | Track separately with Xcode toolchain upgrade path |
