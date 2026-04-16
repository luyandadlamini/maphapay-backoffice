# MaphaPay Minor Accounts — Phase 1 Backend: Handoff Prompt

> **For Codex or Claude:** This document is a complete, self-contained handoff to continue Phase 1 backend implementation of the MaphaPay Minor Accounts feature. Read every section before writing any code.

---

## 1. What This Is

MaphaPay is a fintech mobile wallet app (React Native + Expo) backed by a Laravel 11 + PostgreSQL monolith. We are building a **minor accounts feature** (ages 6–17) called MaphaPay Grow (6–12) and MaphaPay Rise (13–17). Parents create and manage child accounts with progressive spending autonomy that unlocks by age and behaviour.

**Backend repo:** `/Users/Lihle/Development/Coding/maphapay-backoffice`

---

## 2. What Has Already Been Done (DO NOT REDO)

### Task 1 — Database Schema ✅ COMPLETE
**Commit:** `9e517d12`

Added 4 columns to the existing `accounts` table via migration `database/migrations/2026_04_16_120000_create_minor_account_columns_on_accounts_table.php`:

```
accounts table additions:
- account_type   ENUM('personal','merchant','company','minor')  DEFAULT 'personal'
- permission_level  INTEGER nullable   (1–8, only set for minor accounts)
- account_tier   ENUM('grow','rise') nullable   (grow = 6–12, rise = 13–17)
- parent_account_id  UUID nullable, FK → accounts(uuid) CASCADE DELETE
```

Test: `tests/Feature/Database/CreateMinorAccountColumnsTest.php` ✅ PASSING

---

### Task 2 — Guardian Roles ✅ COMPLETE
**Commits:** `ff2814bf`, `68d36c96`

**Key Architecture Decision (important — do not reverse):**
The codebase already has an `account_memberships` table (migration `2026_04_15_100000`) used for ALL account relationships. Rather than creating a separate `guardian_memberships` table, we extended the existing `role` column to include guardian roles. This is the clean, Stripe/Revolut-style approach.

Migration: `database/migrations/2026_04_16_120100_add_guardian_roles_to_account_memberships.php`
- Adds `'guardian'` and `'co_guardian'` to the CHECK constraint on `account_memberships.role`
- Existing roles preserved: `owner`, `admin`, `finance_manager`, `maker`, `approver`, `viewer`
- Full role list now: `owner, admin, finance_manager, maker, approver, viewer, guardian, co_guardian`

Test: `tests/Feature/Database/AddGuardianRolesToAccountMembershipsTest.php` ✅ PASSING

---

## 3. Existing Codebase Patterns (MUST FOLLOW)

### Domain-Driven Architecture
Everything lives in `app/Domain/{Domain}/`:
- `app/Domain/Account/Models/` — Eloquent models
- `app/Domain/Account/Services/` — business logic
- `app/Domain/Account/Actions/` — single-responsibility action classes
- `app/Domain/Account/Routes/api.php` — routes for this domain
- `app/Http/Controllers/Api/` — thin HTTP controllers (call Services, return responses)
- `app/Policies/` — authorization policies

### Key Existing Files to Understand Before Writing Code

**Account Model:** `app/Domain/Account/Models/Account.php`
- Uses `UsesTenantConnection` trait (tenant-scoped DB connection)
- Primary key: `id` (int), also has `uuid` (UUID string) — relationships use `uuid`
- `account_number` auto-generated on create
- `user_uuid` is required on create

**AccountMembership Model:** `app/Domain/Account/Models/AccountMembership.php`
- Uses `central` connection (NOT tenant-scoped — memberships are on the central DB)
- Columns: `id`, `user_uuid`, `tenant_id`, `account_uuid`, `account_type`, `role`, `status`, `invited_by`, `joined_at`, `permissions_override`, `display_name`, `verification_tier`, `capabilities`
- Existing scopes: `active()`, `forUser($userUuid)`, `forAccount($accountUuid)`
- Role values (after Task 2): `owner | admin | finance_manager | maker | approver | viewer | guardian | co_guardian`

**AccountMembershipService:** `app/Domain/Account/Services/AccountMembershipService.php`
- `createOwnerMembership(User, tenantId, Account, displayName, extra)` — creates membership row
- `userHasAccessToAccount(User, accountUuid)` — checks active membership exists
- `getActiveMemberships(User)` — all memberships for a user
- `getMembershipForAccount(User, accountUuid)` — single membership

**Existing Routes:** `app/Domain/Account/Routes/api.php`
- All behind `auth:sanctum` + `account.context` middleware
- Pattern: `Route::post('/accounts/merchant', ...)`, `Route::post('/accounts/company', ...)`
- New minor account routes should follow this exact pattern

**Existing AccountController:** `app/Http/Controllers/Api/AccountController.php`
- Has `createMerchant()` and `createCompany()` methods — use these as reference for `createMinor()`

---

## 4. Phase 1 Status

Phase 1 backend implementation is now complete in this worktree. The original Tasks 3 through 10 have been implemented and verified with targeted tests.

Verification snapshot from this worktree:

- Command:
  `php artisan test tests/Feature/Http/Policies/AccountPolicyTest.php tests/Feature/Http/Controllers/Api/MinorAccountControllerTest.php tests/Feature/Http/Controllers/Api/CoGuardianControllerTest.php tests/Feature/Http/Middleware/ResolveAccountContextTest.php tests/Feature/MinorAccountIntegrationTest.php tests/Unit/Rules/ValidateMinorAccountPermissionTest.php`
- Result:
  `61 passed (135 assertions)`
- Notes:
  the middleware suite required a test-isolation fix so user factories in `ResolveAccountContextTest` always generate unique emails

Completed backend deliverables:

- `AccountPolicy` authorization for guardian, co-guardian, and child access
- `POST /api/accounts/minor` minor account creation
- `ValidateMinorAccountPermission` spending and blocked-category rule
- Guardian invite persistence via `guardian_invites`
- Co-guardian invite creation and acceptance endpoints
- `PUT /api/accounts/minor/{uuid}/permission-level`
- `ResolveAccountContext` support for guardian and child access to minor accounts
- Full workflow integration coverage
- Phase reference documentation in `docs/MINOR_ACCOUNTS_PHASE1.md`

If the next agent continues from here, they should treat Phase 1 as shipped and move to follow-up work only.

### Task 3: AccountPolicy Authorization
Status: `complete`
**File:** `app/Policies/AccountPolicy.php` (CREATE — does not exist yet)
**Test:** `tests/Feature/Http/Policies/AccountPolicyTest.php`

Add an Eloquent Policy with these methods:

```php
// Who can VIEW a minor account
viewMinor(User $user, Account $account): bool
// true if: user has active AccountMembership with role IN ('guardian','co_guardian') for this account
// OR user is the child (account.user_uuid === $user->uuid AND account.account_type === 'minor')

// Who can VIEW ANY minor accounts
viewAnyMinor(User $user): bool
// true if: user has any active AccountMembership with role = 'guardian' or 'co_guardian'

// Who can CREATE a minor account
createMinor(User $user): bool
// true if: user has at least one AccountMembership with role = 'owner' on a personal account

// Who can UPDATE a minor account (change limits, tier, settings)
updateMinor(User $user, Account $account): bool
// true if: user has active AccountMembership with role = 'guardian' (primary only — NOT co_guardian)

// Who can DELETE a minor account
deleteMinor(User $user, Account $account): bool
// true if: user has active AccountMembership with role = 'guardian' (primary only — NOT co_guardian)

// Co-guardians: can approve spending, top-up, manage chores — but NOT update/delete
// Children: can view their own account only
```

**Query pattern to use** (checks central DB AccountMembership):
```php
AccountMembership::query()
    ->forAccount($account->uuid)
    ->forUser($user->uuid)
    ->active()
    ->whereIn('role', ['guardian', 'co_guardian'])
    ->exists();
```

Register the policy in `app/Providers/AuthServiceProvider.php` (or `AppServiceProvider.php` if no Auth provider — check which exists).

---

### Task 4: MinorAccountController — POST /api/minor-accounts
Status: `complete`
**File:** `app/Http/Controllers/Api/MinorAccountController.php` (CREATE)
**Test:** `tests/Feature/Http/Controllers/Api/MinorAccountControllerTest.php`
**Route:** Add to `app/Domain/Account/Routes/api.php`

```php
Route::post('/accounts/minor', [MinorAccountController::class, 'store'])
    ->middleware(['api.rate_limit:mutation', 'scope:write']);
```

**Request body:**
```json
{
  "name": "Emma",
  "date_of_birth": "2014-03-15",
  "photo_id_path": "optional/path.jpg"
}
```

**Controller logic:**
1. Validate `date_of_birth` — child must be between 6 and 17 years old (inclusive)
2. Calculate age from DOB
3. Assign `account_tier`: age < 13 → `'grow'`, age >= 13 → `'rise'`
4. Assign `permission_level` by age:
   - age 6–7 → level 1
   - age 8–9 → level 2
   - age 10–11 → level 3
   - age 12–13 → level 4
   - age 14–15 → level 5
   - age 16 → level 6
   - age 17 → level 6 (level 7 is parent-granted only, not auto-assigned)
5. Create `Account` with `account_type='minor'`, `parent_account_id` = authenticated user's account uuid
6. Create `AccountMembership` with:
   - `user_uuid` = authenticated user's uuid
   - `account_uuid` = new minor account uuid
   - `role` = `'guardian'`
   - `status` = `'active'`
   - `account_type` = `'minor'`
   - `joined_at` = now()
7. Return 201 with account data + membership

Use `AccountMembershipService` for step 6 — extend it with a `createGuardianMembership()` method.

---

### Task 5: ValidateMinorAccountPermission Rule
Status: `complete`
**File:** `app/Rules/ValidateMinorAccountPermission.php` (CREATE)
**Test:** `tests/Unit/Rules/ValidateMinorAccountPermissionTest.php`

A validation Rule that enforces spending limits and merchant category blocks based on permission level. Used in existing send-money / transfer request validation.

**Daily and monthly limits by permission level:**
```
Level 1-2: view only — no spending allowed
Level 3-4: daily 500 SZL, monthly 5,000 SZL
Level 5:   daily 1,000 SZL, monthly 10,000 SZL
Level 6-7: daily 2,000 SZL, monthly 15,000 SZL
Level 8:   no limits (personal account)
```

**Rule constructor:** `__construct(Account $minorAccount, string $transactionType)`

**Rule `validate()` method:**
1. Reject if `permission_level` is 1 or 2 (view-only)
2. Check daily spend total — sum of today's transactions on this account
3. Check monthly spend total — sum of this month's transactions
4. Return error if either limit is exceeded

**Category blocks (hardcoded for now):**
- Blocked merchant categories for all minor accounts: `alcohol`, `tobacco`, `gambling`, `adult_content`
- Use `$transactionType` to check against blocked list

---

### Task 6: GuardianInvite System — Co-Parent Invites
Status: `complete`
**Files:**
- `app/Domain/Account/Models/GuardianInvite.php` (CREATE)
- `database/migrations/2026_04_16_130000_create_guardian_invites_table.php` (CREATE)
- `app/Http/Controllers/Api/CoGuardianController.php` (CREATE)
- `tests/Feature/Http/Controllers/Api/CoGuardianControllerTest.php`

**Migration schema:**
```
guardian_invites
- id: UUID primary key
- minor_account_uuid: UUID FK → accounts(uuid) CASCADE DELETE
- invited_by_user_uuid: UUID FK → users(uuid)
- code: CHAR(8) UNIQUE (alphanumeric invite code)
- expires_at: TIMESTAMP (72 hours from creation)
- claimed_at: TIMESTAMP nullable
- claimed_by_user_uuid: UUID nullable
- status: ENUM('pending','claimed','expired','revoked') DEFAULT 'pending'
- timestamps
```

**Endpoints:**

`POST /api/accounts/minor/{minorAccountUuid}/invite-co-guardian`
- Auth: primary guardian only (use `updateMinor` policy)
- Generates 8-char unique alphanumeric code
- Sets expiry to 72 hours from now
- Returns `{ "code": "ABC12345", "expires_at": "..." }`

`POST /api/guardian-invites/{code}/accept`
- Auth: any authenticated user
- Validates: code exists, not expired, not already claimed
- Creates AccountMembership with `role='co_guardian'` for the authenticated user
- Marks invite as claimed
- Returns 200 with membership data

---

### Task 7: UpdatePermissionLevel Endpoint
Status: `complete`
**File:** `app/Http/Controllers/Api/MinorAccountController.php` (ADD METHOD)
**Test:** Add to `tests/Feature/Http/Controllers/Api/MinorAccountControllerTest.php`
**Route:** `PUT /api/accounts/minor/{uuid}/permission-level`

**Request body:** `{ "permission_level": 5 }`

**Logic:**
- Auth: primary guardian only (policy check)
- Validate new level is 1–7 (level 8 = personal account, not settable here)
- Validate new level is not lower than current level (no demotion via this endpoint)
- Validate tier constraints:
  - Grow tier (6–12): max level 4
  - Rise tier (13–17): max level 7
- Update `accounts.permission_level`
- Return updated account

---

### Task 8: ResolveAccountContext Middleware (extend existing)
Status: `complete`
**Existing file:** Find and read the existing `account.context` middleware (referenced in routes)
**Test:** `tests/Feature/Http/Middleware/ResolveAccountContextTest.php`

Extend the existing `account.context` middleware to handle minor accounts:
- If the resolved account has `account_type='minor'`:
  - Verify the authenticated user has an active AccountMembership with role `guardian` or `co_guardian` for this account
  - OR verify the authenticated user IS the child (account.user_uuid === auth user uuid)
  - If neither, return 403
- If the resolved account is NOT minor, existing logic applies unchanged

---

### Task 9: Integration Test — Full Workflow
Status: `complete`
**File:** `tests/Feature/MinorAccountIntegrationTest.php`

Cover the complete parent-to-child workflow in a single test suite:
1. Parent creates minor account (age 10) → tier=grow, level=3
2. Minor account appears in parent's account list
3. Parent invites co-guardian → code generated
4. Co-guardian accepts code → membership created with role=co_guardian
5. Co-guardian can view minor account (policy check passes)
6. Co-guardian cannot update minor account (policy check fails)
7. Child attempts transaction within daily limit → succeeds
8. Child attempts transaction exceeding daily limit → fails validation
9. Parent updates permission level from 3 → 4 → succeeds
10. Co-guardian attempts to update permission level → 403

---

### Task 10: Documentation
Status: `complete`
**File:** `docs/MINOR_ACCOUNTS_PHASE1.md`

Write a clear reference doc covering:
- Architecture overview (unified account_memberships table, role design)
- All new endpoints with request/response examples
- Permission level matrix (level → age → daily/monthly limits)
- Guardian vs co_guardian permission differences
- Deployment commands for all new migrations
- Known deferred work (tier auto-transition, age-18 conversion, virtual cards)

---

## 5. Verification Summary

The following targeted suites pass in this worktree:

```bash
php artisan test tests/Feature/Http/Policies/AccountPolicyTest.php
php artisan test tests/Feature/Http/Controllers/Api/MinorAccountControllerTest.php
php artisan test tests/Feature/Http/Controllers/Api/CoGuardianControllerTest.php
php artisan test tests/Feature/Http/Middleware/ResolveAccountContextTest.php
php artisan test tests/Feature/MinorAccountIntegrationTest.php
php artisan test tests/Unit/Rules/ValidateMinorAccountPermissionTest.php
```

The full project suite was not run in this handoff session, so any follow-up agent should treat broader regression coverage as the next validation step rather than reopening Phase 1 implementation.

---

## 6. Role Semantics Reference

| Role | Who | Can Do |
|---|---|---|
| `guardian` | Primary parent | Everything: view, update limits, delete, invite co-guardians, approve spending |
| `co_guardian` | Secondary parent | View, approve spending, top-up balance, manage chores. CANNOT: update limits, delete, change level |
| (child) | The minor | View own account, complete chores, redeem points, spend within limits. Auth via parent's device (6–11) or invite code + PIN (12+) |

---

## 7. Key Design Constraints (DO NOT VIOLATE)

1. **NO separate guardian table** — guardian relationships live in `account_memberships` with `role='guardian'` or `role='co_guardian'`
2. **Account primary key is `uuid`** (not `id`) — all foreign keys reference `accounts.uuid`
3. **AccountMembership uses `central` DB connection** — it is NOT tenant-scoped
4. **Account model uses tenant connection** — it IS tenant-scoped via `UsesTenantConnection`
5. **No `any` TypeScript types** — n/a for backend, but keep PHP strictly typed (`declare(strict_types=1)`)
6. **Use `$request->user()`** not `auth()->user()` in controllers
7. **Routes go in** `app/Domain/Account/Routes/api.php` not in `routes/api.php`

---

## 8. Deployment Commands (Laravel Cloud)

After completing all tasks, run:

```bash
# Task 1 migration (already complete, run if fresh environment)
php artisan migrate --path=database/migrations/2026_04_16_120000_create_minor_account_columns_on_accounts_table.php --force

# Task 2 migration (already complete, run if fresh environment)
php artisan migrate --path=database/migrations/2026_04_16_120100_add_guardian_roles_to_account_memberships.php --force

# Task 6 migration (guardian_invites table)
php artisan migrate --path=database/migrations/2026_04_16_130000_create_guardian_invites_table.php --force
```

---

## 9. If You Hit a Blocker

- **Can't find a file?** Run `find /Users/Lihle/Development/Coding/maphapay-backoffice -name "*.php" | xargs grep -l "ClassName"` to locate it
- **Tests failing due to DB connection?** Check if the model uses `UsesTenantConnection` (tenant DB) or `'central'` connection — use the right one in test setup
- **Policy not triggering?** Verify it's registered in `AuthServiceProvider.php` or `AppServiceProvider.php`
- **Route 404?** Check `app/Domain/Account/Routes/api.php` is included in the main `routes/api.php` or bootstrap

---

## 10. Task Checklist

```
✅ Task 1: Database Schema (accounts table columns)
✅ Task 2: Guardian Roles (account_memberships role enum extended)
✅ Task 3: AccountPolicy (guardian, co_guardian, child authorization)
✅ Task 4: MinorAccountController POST /api/accounts/minor
✅ Task 5: ValidateMinorAccountPermission Rule (spending limits + category blocks)
✅ Task 6: GuardianInvite + CoGuardianController (invite system)
✅ Task 7: UpdatePermissionLevel endpoint
✅ Task 8: ResolveAccountContext middleware extension
✅ Task 9: Integration Test (full workflow)
✅ Task 10: Documentation
```

## 11. Next-Agent Starting Point

Phase 1 backend is complete. The next agent should not reopen these tasks unless they are fixing regressions.

Recommended next work:

1. Run a broader regression pass across adjacent account and transfer suites.
2. Coordinate with the mobile repo to consume the finalized response payloads and routes.
3. Start Phase 2 items such as tier auto-transition, age-18 conversion, and child self-onboarding.
