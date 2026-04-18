# MaphaPay Minor Accounts — Phase 2 Backend: Handoff Prompt

> **For Codex or Claude:** This document is the Phase 2 continuation prompt for the MaphaPay Minor Accounts feature. Read the full file before making changes.

---

## 1. What This Is

MaphaPay is building a family-oriented minor accounts system for ages 6–17 under two branded tiers:

- `MaphaPay Grow` for ages 6–12
- `MaphaPay Rise` for ages 13–17

Phase 1 backend is already complete. Phase 2 should **extend** the shipped backend without reopening settled architecture decisions.

**Backend repo:** `/Users/Lihle/Development/Coding/maphapay-backoffice`

**Original feature plan:** `/Users/Lihle/.claude/plans/curious-toasting-kitten.md`

**Phase 1 reference doc:** `docs/MINOR_ACCOUNTS_PHASE1.md`

**Phase 1 completion commit:** `5e681132`

---

## 2. Phase 1 Is Already Shipped

Do not rebuild or redesign these:

- Minor accounts are stored in the tenant-scoped `accounts` table.
- Guardian relationships use the existing central `account_memberships` table.
- Roles already in use:
  - `guardian`
  - `co_guardian`
- Phase 1 endpoints already exist for:
  - creating minor accounts
  - inviting co-guardians
  - accepting co-guardian invites
  - updating permission level
- Minor spending validation already exists via `ValidateMinorAccountPermission`.
- Minor context resolution already exists in `ResolveAccountContext`.

### Critical Architecture Constraint

The original Claude plan proposed a separate `minor_guardians` table. That design is now obsolete for this codebase.

**Do not create `minor_guardians`.**

Use `account_memberships` consistently for all guardian/co-guardian relationships.

---

## 3. Source Material You Must Reconcile

Use these in order of authority for implementation:

1. Current codebase reality in this repo
2. `docs/MINOR_ACCOUNTS_PHASE1.md`
3. `5e681132` and the Phase 1 implementation
4. `/Users/Lihle/.claude/plans/curious-toasting-kitten.md`

If the original Claude plan conflicts with shipped Phase 1 architecture, preserve Phase 1 architecture and adapt the Phase 2 design to it.

---

## 4. Phase 2 Scope

Phase 2 should implement the highest-value backend work that the original plan deferred or identified as unresolved, while staying consistent with the Phase 1 code.

### In Scope

1. **Automatic Grow → Rise tier transition**
2. **Age-18 conversion from `minor` to `personal`**
3. **Level 7 early takeover request flow**
4. **Parent/guardian freeze cascade behavior**
5. **Stronger documentation and integration coverage for the above**

### Explicitly Out of Scope

- Physical card issuance
- Full child authentication / PIN login backend
- Chore management system
- Points/rewards engine work
- External family funding links
- Savings groups and family goals
- Merchant integrations

Those can be planned later, but do not expand this phase to include them unless required by an existing dependency in the code.

---

## 5. Existing Patterns You Must Follow

### Domain structure

- `app/Domain/{Domain}/Models`
- `app/Domain/{Domain}/Services`
- `app/Domain/{Domain}/Actions`
- `app/Domain/{Domain}/Routes/api.php`
- `app/Http/Controllers/Api`
- `app/Policies`

### Important implementation rules

- Keep `declare(strict_types=1)` on all new PHP files.
- Use `$request->user()` in controllers.
- Keep routes in `app/Domain/Account/Routes/api.php`.
- `AccountMembership` stays on the `central` connection.
- `Account` stays tenant-scoped.
- Use `accounts.uuid` for all account-level foreign key relationships.
- Do not introduce a parallel guardian table or duplicate relationship model.

---

## 6. Phase 2 Tasks

Use TDD for each task: write a failing test first, watch it fail, implement minimally, and rerun.

### Task 1: Tier Transition Service

**Goal:** automatically move eligible minors from `grow` to `rise` when they turn 13.

**Create:**

- `app/Domain/Account/Actions/TransitionMinorAccountTier.php`
- `tests/Unit/Domain/Account/Actions/TransitionMinorAccountTierTest.php`

**Behavior:**

- For a minor account:
  - if age is now 13 or older
  - and `account_tier === 'grow'`
  - then transition to `account_tier = 'rise'`
- Preserve the existing permission level unless an explicit rule says it must increase.
- If current permission level is below the minimum implied by age 13, raise it to `4`.
- Record enough metadata or logging for auditability using existing app patterns.

**Constraints:**

- This action must be idempotent.
- It must not affect non-minor accounts.
- It must not demote permission levels.

---

### Task 2: Scheduled Command for Tier Transitions

**Goal:** run tier transitions automatically.

**Create:**

- `app/Console/Commands/ProcessMinorAccountTransitionsCommand.php`
- tests for the command

**Behavior:**

- Scan minor accounts that are still `grow`
- Detect which children are now 13+
- Call `TransitionMinorAccountTier`
- Emit clear console output and summary counts

**Also update:**

- the Laravel scheduler registration if this project uses scheduled commands centrally

---

### Task 3: Age-18 Conversion Action

**Goal:** convert a minor account into a personal account when the child reaches 18.

**Create:**

- `app/Domain/Account/Actions/ConvertMinorToPersonalAccount.php`
- `tests/Unit/Domain/Account/Actions/ConvertMinorToPersonalAccountTest.php`

**Behavior:**

- For eligible minor accounts where child age is 18 or older:
  - set `account_type = 'personal'`
  - clear minor-only fields that no longer apply:
    - `parent_account_id = null`
    - `account_tier = null`
    - `permission_level = null` or `8` only if current codebase semantics require it
- Remove guardian/co-guardian access by updating `account_memberships`
- Create an owner membership for the former child if needed

**Important:**

- Preserve balance and transaction history
- Preserve `uuid`
- Keep the action idempotent

If the codebase already assumes personal accounts must have an `owner` membership, enforce that.

---

### Task 4: Conversion Eligibility Guard

**Goal:** prevent unsafe conversion when prerequisites are not satisfied.

The original plan mentions adult KYC before full takeover. If the current backend already has a usable verification flag or KYC status on the user, use it.

**Implement one of these approaches, based on actual codebase support:**

- If adult KYC state is available:
  - block conversion until required KYC is complete
  - add a status or error path
- If adult KYC state is not yet implemented cleanly:
  - introduce a conservative conversion readiness check interface
  - default to a clearly documented placeholder rule
  - do not invent a large new KYC subsystem in this phase

**Deliverable:**

- tests that prove conversion behavior for ready vs not-ready accounts
- explicit documentation of the chosen rule

---

### Task 5: Level 7 Early Takeover Request

The original plan explicitly corrects Level 7:

- Level 7 is **not age-derived**
- It is a parent-granted early takeover state for a 17-year-old

**Create:**

- route(s) in `app/Domain/Account/Routes/api.php`
- controller methods in a minor-account or takeover-specific controller
- tests

**Suggested endpoints:**

- `POST /api/accounts/minor/{uuid}/takeover-request`
- `POST /api/accounts/minor/{uuid}/grant-takeover`

**Behavior:**

- Child requests takeover:
  - only allowed for minors who are eligible by age and tier
- Guardian grants takeover:
  - only primary guardian may grant
- Granting takeover sets `permission_level = 7`
- Co-guardian cannot grant takeover

Do not auto-convert to personal account here. This is still a minor account state.

---

### Task 6: Parent Freeze/Delete Cascade Handling

The original plan identified this as a critical gap.

**Implement backend behavior for this rule:**

- if the primary guardian’s qualifying parent account becomes unavailable for governance purposes
  - affected minor accounts should be frozen
  - minor access should not silently remain active

Because account deletion/freeze flows may already exist elsewhere, integrate with the current account lifecycle patterns instead of inventing a parallel mechanism.

**Minimum acceptable Phase 2 behavior:**

- introduce a reusable action or service that can freeze a minor account due to guardian governance loss
- test it directly
- wire it into the most realistic existing hook point available in this repo

If delete/freeze orchestration is too broad to fully integrate safely in this phase, implement the reusable action and document the remaining hook-in point explicitly.

---

### Task 7: Phase 2 Documentation

**Create or update:**

- `docs/MINOR_ACCOUNTS_PHASE2.md`

Document:

- Grow → Rise transition rules
- Age-18 conversion rules
- Level 7 takeover request/grant behavior
- Guardian-freeze cascade behavior
- Any KYC gating assumptions used
- Commands, routes, and verification commands
- Deferred work that remains after Phase 2

---

## 7. Testing Requirements

At minimum, add:

- unit tests for transition and conversion actions
- feature tests for takeover routes
- integration coverage for:
  - age 12 → 13 tier change
  - age 17 takeover request + guardian approval
  - age 18 conversion path

Run targeted tests and report exact commands and results.

If a broader suite is practical, run adjacent account/minor suites too.

---

## 8. Verification Commands

Use these as the default Phase 2 verification baseline, adjusting filenames if you split tests differently:

```bash
php artisan test tests/Unit/Domain/Account/Actions/TransitionMinorAccountTierTest.php
php artisan test tests/Unit/Domain/Account/Actions/ConvertMinorToPersonalAccountTest.php
php artisan test --filter=takeover
php artisan test --filter=minor
```

If you add a command:

```bash
php artisan test --filter=ProcessMinorAccountTransitionsCommand
```

Also run formatting / quality checks appropriate to changed files.

---

## 9. Design Decisions You Must Preserve

1. No separate `minor_guardians` table
2. Guardian/co-guardian relationships stay in `account_memberships`
3. `co_guardian` can view and participate in child support flows, but cannot perform guardian-only governance changes
4. Level 7 is parent-granted, not age-derived
5. Age-18 conversion is a real account transition, not a new account creation flow

---

## 10. Deliverable Checklist

```
⬜ Task 1: TransitionMinorAccountTier action
⬜ Task 2: Scheduled command for tier transitions
⬜ Task 3: ConvertMinorToPersonalAccount action
⬜ Task 4: Conversion readiness / KYC gate
⬜ Task 5: Level 7 takeover request and guardian grant flow
⬜ Task 6: Guardian freeze/delete cascade handling
⬜ Task 7: Phase 2 documentation
```

---

## 11. If You Hit a Conflict

If the original Claude plan conflicts with shipped Phase 1 implementation:

- preserve shipped Phase 1 architecture
- adapt the Phase 2 design
- document the reconciliation in the final summary

Do not “fix” Phase 1 by reintroducing old design assumptions.

