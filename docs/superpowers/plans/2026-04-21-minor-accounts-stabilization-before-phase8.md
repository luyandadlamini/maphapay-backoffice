# Minor Accounts Stabilization Before Phase 8 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Repair the minor-accounts foundation across backend and mobile so phase 8 can restart on a coherent, testable, and mergeable architecture.

**Architecture:** Do not patch phase 8 in place. First stabilize the canonical minor-account model, authorization rules, payload contracts, and transactional boundaries in the existing phase 1-7 implementation. Only after those gates pass should phase 8 be rebuilt on the real UUID-based account and points-ledger model. The halted phase 8 branch/worktree is reference material, not mergeable source of truth.

**Tech Stack:** Laravel 12, PHP 8.4, MySQL tenant + central DB, Pest, PHPStan, React Native (Expo), TypeScript, React Query, Vitest

---

## Stop/Go Rules

### Stop Rule

Do **not** continue phase 8 feature work until all of these are true:
- minor-account ownership semantics are unified
- backend access control for minor accounts is fixed and tested
- account and reward payload contracts are canonicalized across repos
- reward/chore/redemption mutations are transactional and idempotent where required
- mobile phase 6-8 screens are no longer calling nonexistent endpoints

### Go Rule

Phase 8 may resume only after:
- backend integration tests for minor identity, approvals, rewards, and chores pass
- mobile contract tests pass against actual backend payloads
- a replacement phase 8 backend API contract is approved and implemented on top of the existing UUID model

## Execution Lessons From Tasks 1-2

These are ground-truth constraints discovered while executing the first two stabilization tasks. Future agents should treat them as implementation rules, not optional advice.

### Canonical Model Rules

- Minor child identity is the tenant `accounts.user_uuid` on the minor account record.
- Guardian and co-guardian access come only from central `account_memberships.account_uuid`.
- Child access is valid only for a real minor account that has at least one active guardian or co-guardian membership.
- Do not invent `minor_account_uuid` on `account_memberships`. The schema does not support it.
- Do not add a second access model in controllers, tests, or mobile payload handling.

### Test And Harness Rules

- Before trusting an existing targeted test, verify it against the real migrations and model connection behavior.
- A valid red phase means failing on the intended business invariant, not on stale fixtures, missing `tenant_id`, wrong DB connection assumptions, or unrelated bootstrap side effects.
- Do not run the MySQL-backed targeted suites for this work in parallel. Stale PHPUnit or `php artisan test` workers can hold DB locks open and create false deadlocks.
- If tests hang, inspect running PHPUnit/artisan workers and the MySQL process list before changing application code.
- For controller authorization tests in this area, prefer a minimal harness. Disable unrelated middleware if the task is isolating controller auth behavior.
- The shared `Tests\TestCase` bootstrap can introduce unrelated role/account setup and lock contention. If it obscures the target invariant, use a narrower base test case for the targeted suite.

### Tenant Schema Rules For Tests

- In `testing`, tenant-backed models may resolve to the shared MySQL test database because `UsesTenantConnection` falls back to the default connection.
- Do not assume the test DB user can create tenant databases. Real tenancy initialization may fail with DB privilege errors.
- Do not run the full tenant migration directory into the shared test DB for minor-account controller tests. That can collide with foundational tenant tables such as `accounts`.
- For targeted minor rewards/chore controller tests, run only the exact tenant migration files required by that suite.

### Drift Warnings

- Comments and older tests in this area may describe behavior that is no longer canonical. Migrations and live model behavior take precedence.
- After Task 2, the controller source of truth is `app/Domain/Account/Services/MinorAccountAccessService.php`.
- Future tasks must check `AccountPolicy` and `ResolveAccountContext` for drift before adding new access logic elsewhere.

---

## Execution Order

1. Fix the minor-account identity model and access control.
2. Fix send-money approval ordering and idempotency.
3. Stabilize phase 3/4 rewards and chores around transactions and auditability.
4. Canonicalize backend-to-mobile account and reward contracts.
5. Disable or correct mobile calls to missing phase 6/7/8 endpoints.
6. Delete the parallel phase 8 backend assumptions and replace them with a real extension plan.
7. Rebuild phase 8 backend API surface on the stabilized model.
8. Rewire mobile phase 8 to the real API contract.
9. Run full backend/mobile contract and integration verification.

If any task in steps 1-4 fails, stop. Do not jump ahead to phase 8 rebuild.

---

## File Structure

### Backend files to modify

- `app/Http/Controllers/Api/MinorAccountController.php`
  Responsibility: canonical minor-account creation and permission updates
- `app/Http/Middleware/ResolveAccountContext.php`
  Responsibility: account access resolution for child/guardian/co-guardian
- `app/Policies/AccountPolicy.php`
  Responsibility: one real source of access truth for minor accounts
- `app/Http/Controllers/Api/Auth/MobileAuthController.php`
  Responsibility: auth payload contract
- `app/Http/Controllers/Api/Auth/LoginController.php`
  Responsibility: auth payload contract
- `app/Http/Controllers/Api/AccountController.php`
  Responsibility: account listing payload contract
- `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php`
  Responsibility: idempotent, trust-aware approval initiation
- `app/Http/Controllers/Api/MinorPointsController.php`
  Responsibility: reward API and correct authorization reuse
- `app/Http/Controllers/Api/MinorChoreController.php`
  Responsibility: chore API and correct authorization reuse
- `app/Domain/Account/Services/MinorRewardService.php`
  Responsibility: transactional reward redemption
- `app/Domain/Account/Services/MinorChoreService.php`
  Responsibility: transactional chore review
- `app/Domain/Account/Services/MinorPointsService.php`
  Responsibility: points ledger invariants and stable balance reads
- `app/Domain/Account/Services/MinorNotificationService.php`
  Responsibility: durable audit/notification hooks instead of debug-only logs
- `app/Domain/Account/Routes/api.php`
  Responsibility: canonical route surface for phases 3-8

### Backend files likely to create

- `app/Domain/Account/Services/MinorAccountAccessService.php`
  Responsibility: central access checks reused by controllers/policies
- `app/Http/Controllers/Api/MinorRewardsCatalogController.php`
  Responsibility: future stabilized phase 8 catalog/detail/submit routes
- `tests/Feature/Http/Controllers/Api/MinorRewardsCatalogControllerTest.php`
  Responsibility: phase 8 contract tests on the stabilized backend
- `tests/Feature/Contracts/MinorAccountMobileContractTest.php`
  Responsibility: backend payload contract snapshots for mobile

### Mobile files to modify

- `src/features/account/domain/types.ts`
  Responsibility: canonical role and account summary types
- `src/features/account/hooks/useAccountPermissions.ts`
  Responsibility: consume canonical role type, no duplicate source of truth
- `src/features/account/presentation/AccountSwitcherSheet.tsx`
  Responsibility: correct minor-account display only for supported backend payloads
- `src/features/minor-accounts/hooks/useFamilyMembers.ts`
  Responsibility: stop calling nonexistent endpoints or gate behind implemented API
- `src/features/minor-accounts/hooks/useSharedGoals.ts`
  Responsibility: same
- `src/features/minor-accounts/hooks/useLearningModules.ts`
  Responsibility: same
- `src/features/minor-accounts/hooks/useChoreSubmissions.ts`
  Responsibility: correct pending submissions contract
- `src/features/minor-accounts/hooks/useRewardsShop.ts`
  Responsibility: align with canonical rewards contract or retire in favor of phase 8 hooks
- `src/features/minor-accounts/hooks/usePendingRedemptions.ts`
  Responsibility: align with backend route contract
- `src/features/minor-accounts/hooks/useRedeemReward.ts`
  Responsibility: align with backend route contract
- `src/features/minor-accounts/hooks/useMinorRewardsCatalog.ts`
  Responsibility: final phase 8 catalog contract
- `src/features/minor-accounts/hooks/useMinorRewardDetail.ts`
  Responsibility: final phase 8 detail contract
- `src/features/minor-accounts/hooks/useSubmitRedemption.ts`
  Responsibility: final phase 8 submit contract
- `src/features/minor-accounts/domain/rewardTypes.ts`
  Responsibility: canonical phase 8 reward DTO
- `src/features/minor-accounts/domain/redemptionTypes.ts`
  Responsibility: canonical phase 8 redemption DTO
- `src/features/minor-accounts/presentation/KidDashboard.tsx`
  Responsibility: stop placeholder behavior and wire only supported widgets
- `src/features/minor-accounts/presentation/RewardDetailModal.tsx`
  Responsibility: compliant theme tokens and real API semantics
- `src/features/minor-accounts/presentation/RewardsDashboardWidget.tsx`
  Responsibility: compliant theme tokens and real API semantics
- `src/features/minor-accounts/components/RewardCard.tsx`
  Responsibility: compliant theme tokens and DTO usage
- `src/features/minor-accounts/components/CatalogFilterSheet.tsx`
  Responsibility: compliant theme tokens

### Mobile tests to modify/create

- `tests/features/minor-accounts/useSharedGoals.test.ts`
- `tests/features/minor-accounts/useChoreSubmissions.test.ts`
- `tests/features/minor-accounts/KidDashboard.test.ts`
- `tests/features/minor-accounts/screens.test.ts`
- `tests/features/minor-accounts/minor-contracts.test.ts`

These must become behavior/contract tests rather than source-string assertions.

---

## Task 1: Canonical Minor-Account Identity Model

**Files:**
- Modify: `app/Http/Controllers/Api/MinorAccountController.php`
- Modify: `app/Http/Middleware/ResolveAccountContext.php`
- Modify: `app/Policies/AccountPolicy.php`
- Test: `tests/Feature/MinorAccountIntegrationTest.php`
- Test: `tests/Feature/Http/Middleware/ResolveAccountContextTest.php`
- Test: `tests/Feature/Http/Policies/AccountPolicyTest.php`

- [ ] **Step 1: Write failing tests for one canonical child-access model**

Pre-step:
- inspect the real `accounts` and `account_memberships` schema before modifying tests
- if the targeted tests are stale relative to the canonical model, fix the tests first so the red phase fails on identity semantics rather than setup drift

Add or update tests to prove:
- creating a minor account does not require post-test mutation of `accounts.user_uuid`
- a child can access their own minor account through the chosen identity model
- guardian and co-guardian permissions are distinct and enforced

Example assertions:

```php
$response = $this->postJson('/api/accounts/minor', [...]);
$minor = Account::where('uuid', $uuid)->firstOrFail();

expect($minor->user_uuid)->toBe(/* canonical expected owner identity */);
expect($policy->viewMinor($childUser, $minor))->toBeTrue();
expect($policy->updateMinor($coGuardianUser, $minor))->toBeFalse();
```

- [ ] **Step 2: Run the targeted tests to verify failure**

Run:

```bash
php artisan test tests/Feature/MinorAccountIntegrationTest.php \
  tests/Feature/Http/Middleware/ResolveAccountContextTest.php \
  tests/Feature/Http/Policies/AccountPolicyTest.php
```

Expected:
- FAIL because the current model requires contradictory ownership assumptions
- not because of invalid membership fixtures, missing tenant columns, or test-time ownership patching

- [ ] **Step 3: Implement the canonical identity model**

Rules:
- one source of truth for who the child is
- one source of truth for guardian/co-guardian membership
- no controller or test may patch persisted ownership after creation

- [ ] **Step 4: Re-run the targeted tests**

Expected:
- PASS without any test-time ownership mutation

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MinorAccountController.php \
  app/Http/Middleware/ResolveAccountContext.php \
  app/Policies/AccountPolicy.php \
  tests/Feature/MinorAccountIntegrationTest.php \
  tests/Feature/Http/Middleware/ResolveAccountContextTest.php \
  tests/Feature/Http/Policies/AccountPolicyTest.php
git commit -m "fix(minor-accounts): unify minor account identity and access model"
```

---

## Task 2: Replace Fake Authorization With One Real Access Primitive

**Files:**
- Create: `app/Domain/Account/Services/MinorAccountAccessService.php`
- Modify: `app/Http/Controllers/Api/MinorPointsController.php`
- Modify: `app/Http/Controllers/Api/MinorChoreController.php`
- Test: `tests/Feature/Http/Controllers/Api/MinorRewardTest.php`
- Test: `tests/Feature/Http/Controllers/Api/MinorChoreTest.php`

- [ ] **Step 1: Write failing tests that prove guardian and child access through the real schema**

Pre-step:
- inspect the real `account_memberships` migration and `AccountMembership` model first
- verify the targeted tests are exercising the HTTP/controller path, not only service behavior
- if tests are stale, repair only what is necessary to align them with the stabilized Task 1 identity model and the real schema

Example assertions:

```php
$this->actingAs($guardian, ['read', 'write', 'delete'])
    ->getJson("/api/accounts/minor/{$uuid}/rewards")
    ->assertOk();

$this->actingAs($stranger, ['read', 'write', 'delete'])
    ->getJson("/api/accounts/minor/{$uuid}/rewards")
    ->assertForbidden();
```

- [ ] **Step 2: Run the targeted tests**

Run:

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorRewardTest.php \
  tests/Feature/Http/Controllers/Api/MinorChoreTest.php
```

Expected:
- FAIL because controllers currently query nonexistent `minor_account_uuid` membership columns
- not because of missing `tenant_id`, stale reward fixtures, unrelated role bootstrap, or wrong tenant-schema assumptions

- [ ] **Step 3: Implement `MinorAccountAccessService` and switch both controllers to it**

Rules:
- no direct schema invention inside controllers
- controller auth must use the actual `account_memberships` schema and canonical minor-account identity rules
- guardian/co-guardian access must query `account_memberships.account_uuid = {minor account uuid}`
- child access must rely on `accounts.user_uuid`, but only for a real minor account backed by guardian membership
- do not copy/paste new auth helpers into multiple controllers once the service exists

- [ ] **Step 4: Re-run the targeted tests**

Expected:
- PASS

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Account/Services/MinorAccountAccessService.php \
  app/Http/Controllers/Api/MinorPointsController.php \
  app/Http/Controllers/Api/MinorChoreController.php \
  tests/Feature/Http/Controllers/Api/MinorRewardTest.php \
  tests/Feature/Http/Controllers/Api/MinorChoreTest.php \
  docs/superpowers/plans/2026-04-21-minor-accounts-stabilization-before-phase8.md
git commit -m "fix(minor-accounts): centralize real minor account access"
```

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Account/Services/MinorAccountAccessService.php \
  app/Http/Controllers/Api/MinorPointsController.php \
  app/Http/Controllers/Api/MinorChoreController.php \
  tests/Feature/Http/Controllers/Api/MinorRewardTest.php \
  tests/Feature/Http/Controllers/Api/MinorChoreTest.php
git commit -m "fix(minor-accounts): centralize access control for rewards and chores"
```

---

## Task 3: Fix Send-Money Approval Ordering and Replay Safety

**Files:**
- Modify: `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php`
- Test: `tests/Feature/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreControllerTest.php`
- Test: `tests/Feature/Http/Controllers/Api/MinorSpendApprovalControllerTest.php`

- [ ] **Step 1: Write failing tests for retry-safe approval creation**

Prove:
- same idempotency key cannot create duplicate approvals
- trust-policy deny/step-up executes before approval creation

Example assertion:

```php
$this->withHeader('Idempotency-Key', 'minor-approval-1')
    ->postJson('/api/...send-money...', $payload)
    ->assertStatus(202);

$this->withHeader('Idempotency-Key', 'minor-approval-1')
    ->postJson('/api/...send-money...', $payload)
    ->assertStatus(200); // or replay-safe deterministic response

expect(MinorSpendApproval::count())->toBe(1);
```

- [ ] **Step 2: Run the targeted tests**

Run:

```bash
php artisan test tests/Feature/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreControllerTest.php \
  tests/Feature/Http/Controllers/Api/MinorSpendApprovalControllerTest.php
```

Expected:
- FAIL because approval creation happens before replay/trust checks

- [ ] **Step 3: Reorder logic so replay and trust checks happen before any approval insert**

- [ ] **Step 4: Re-run the targeted tests**

Expected:
- PASS with one approval row per logical initiation

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php \
  tests/Feature/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreControllerTest.php \
  tests/Feature/Http/Controllers/Api/MinorSpendApprovalControllerTest.php
git commit -m "fix(minor-accounts): make spend approvals replay-safe and trust-aware"
```

---

## Task 4: Make Reward and Chore Mutations Transactional

**Files:**
- Modify: `app/Domain/Account/Services/MinorRewardService.php`
- Modify: `app/Domain/Account/Services/MinorChoreService.php`
- Modify: `app/Domain/Account/Services/MinorPointsService.php`
- Test: `tests/Feature/Http/Controllers/Api/MinorRewardTest.php`
- Test: `tests/Feature/Http/Controllers/Api/MinorChoreTest.php`
- Create: `tests/Feature/Http/Controllers/Api/MinorRewardConcurrencyTest.php`

- [ ] **Step 1: Write failing tests for duplicate redemption and duplicate chore approval**

Prove:
- a reward cannot be oversold under concurrent or repeated redemption attempts
- a chore completion cannot be approved twice for duplicate points

- [ ] **Step 2: Run the targeted tests**

Run:

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorRewardTest.php \
  tests/Feature/Http/Controllers/Api/MinorChoreTest.php \
  tests/Feature/Http/Controllers/Api/MinorRewardConcurrencyTest.php
```

Expected:
- FAIL because current services have no transactions or repeat-call protection

- [ ] **Step 3: Add transactions, locking, and repeat-call guards**

Rules:
- deduction + redemption creation + stock decrement is atomic
- review update + points award is atomic
- refund/deduction actions are ledger-linked and repeat-safe

- [ ] **Step 4: Re-run the targeted tests**

Expected:
- PASS

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Account/Services/MinorRewardService.php \
  app/Domain/Account/Services/MinorChoreService.php \
  app/Domain/Account/Services/MinorPointsService.php \
  tests/Feature/Http/Controllers/Api/MinorRewardTest.php \
  tests/Feature/Http/Controllers/Api/MinorChoreTest.php \
  tests/Feature/Http/Controllers/Api/MinorRewardConcurrencyTest.php
git commit -m "fix(minor-accounts): make rewards and chores transactional"
```

---

## Task 5: Replace Debug-Only Notification Behavior With Durable Audit Hooks

**Files:**
- Modify: `app/Domain/Account/Services/MinorNotificationService.php`
- Modify: `app/Domain/Account/Services/MinorRewardService.php`
- Modify: `app/Domain/Account/Services/MinorChoreService.php`
- Test: `tests/Feature/MinorAccountPhase4IntegrationTest.php`

- [ ] **Step 1: Write failing tests that assert durable side effects for reward/chore lifecycle events**

- [ ] **Step 2: Run the targeted tests**

Run:

```bash
php artisan test tests/Feature/MinorAccountPhase4IntegrationTest.php
```

Expected:
- FAIL because notifications are debug logs only

- [ ] **Step 3: Implement durable notification or audit-event persistence using existing app patterns**

- [ ] **Step 4: Re-run the targeted tests**

Expected:
- PASS with persisted lifecycle traces

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Account/Services/MinorNotificationService.php \
  app/Domain/Account/Services/MinorRewardService.php \
  app/Domain/Account/Services/MinorChoreService.php \
  tests/Feature/MinorAccountPhase4IntegrationTest.php
git commit -m "fix(minor-accounts): persist child lifecycle notifications and audit hooks"
```

---

## Task 6: Canonicalize Backend-to-Mobile Account Payloads

**Files:**
- Modify: `app/Http/Controllers/Api/Auth/MobileAuthController.php`
- Modify: `app/Http/Controllers/Api/Auth/LoginController.php`
- Modify: `app/Http/Controllers/Api/AccountController.php`
- Create: `tests/Feature/Contracts/MinorAccountMobileContractTest.php`
- Modify: `src/features/account/domain/types.ts`
- Modify: `src/features/account/hooks/useAccountPermissions.ts`
- Modify: `src/features/account/presentation/AccountSwitcherSheet.tsx`
- Test: `tests/features/minor-accounts/minor-contracts.test.ts`

- [ ] **Step 1: Write failing backend contract tests for account payload shape**

Required fields for minor accounts:
- `account_uuid`
- `account_type`
- `role`
- `display_name`
- `account_tier`
- `permission_level`
- `parent_account_uuid`

- [ ] **Step 2: Run backend contract tests**

Run:

```bash
php artisan test tests/Feature/Contracts/MinorAccountMobileContractTest.php
```

Expected:
- FAIL because the payload omits required minor-account fields

- [ ] **Step 3: Fix backend payload transformers**

- [ ] **Step 4: Write failing mobile tests to consume the canonical payload without local role duplication**

- [ ] **Step 5: Run mobile contract tests**

Run:

```bash
npm test -- tests/features/minor-accounts/minor-contracts.test.ts
```

Expected:
- FAIL because mobile currently has split-brain role typing

- [ ] **Step 6: Fix mobile account types and permission consumption**

- [ ] **Step 7: Re-run backend and mobile contract tests**

Expected:
- PASS

- [ ] **Step 8: Commit**

```bash
git -C /Users/Lihle/Development/Coding/maphapay-backoffice add \
  app/Http/Controllers/Api/Auth/MobileAuthController.php \
  app/Http/Controllers/Api/Auth/LoginController.php \
  app/Http/Controllers/Api/AccountController.php \
  tests/Feature/Contracts/MinorAccountMobileContractTest.php
git -C /Users/Lihle/Development/Coding/maphapayrn add \
  src/features/account/domain/types.ts \
  src/features/account/hooks/useAccountPermissions.ts \
  src/features/account/presentation/AccountSwitcherSheet.tsx \
  tests/features/minor-accounts/minor-contracts.test.ts
git commit -m "fix(minor-accounts): align account payload contracts across backend and mobile"
```

---

## Task 7: Disable or Correct Unsupported Mobile Phase 6-7 Flows

**Files:**
- Modify: `src/features/minor-accounts/hooks/useFamilyMembers.ts`
- Modify: `src/features/minor-accounts/hooks/useSharedGoals.ts`
- Modify: `src/features/minor-accounts/hooks/useLearningModules.ts`
- Modify: `src/features/minor-accounts/hooks/useChoreSubmissions.ts`
- Modify: `src/features/minor-accounts/presentation/KidDashboard.tsx`
- Modify: `src/features/minor-accounts/presentation/FamilyDashboardScreen.tsx`
- Modify: `src/features/minor-accounts/presentation/InsightsCard.tsx`
- Test: `tests/features/minor-accounts/useSharedGoals.test.ts`
- Test: `tests/features/minor-accounts/useChoreSubmissions.test.ts`
- Test: `tests/features/minor-accounts/KidDashboard.test.ts`

- [ ] **Step 1: Write failing tests that prove unsupported flows are either gated or removed**

Rules:
- no hook may call a nonexistent backend route without an explicit feature gate
- no placeholder text should be treated as completed implementation

- [ ] **Step 2: Run the targeted mobile tests**

Run:

```bash
npm test -- tests/features/minor-accounts/useSharedGoals.test.ts \
  tests/features/minor-accounts/useChoreSubmissions.test.ts \
  tests/features/minor-accounts/KidDashboard.test.ts
```

Expected:
- FAIL because unsupported routes and placeholders are still wired as live features

- [ ] **Step 3: Implement one of two strategies per unsupported flow**

Allowed strategies:
- remove the flow from shipping UI
- feature-gate it behind an explicit “not available yet” state
- wire it to a real backend API if that API exists by this point

- [ ] **Step 4: Re-run the targeted tests**

Expected:
- PASS

- [ ] **Step 5: Commit**

```bash
git add src/features/minor-accounts/hooks/useFamilyMembers.ts \
  src/features/minor-accounts/hooks/useSharedGoals.ts \
  src/features/minor-accounts/hooks/useLearningModules.ts \
  src/features/minor-accounts/hooks/useChoreSubmissions.ts \
  src/features/minor-accounts/presentation/KidDashboard.tsx \
  src/features/minor-accounts/presentation/FamilyDashboardScreen.tsx \
  src/features/minor-accounts/presentation/InsightsCard.tsx \
  tests/features/minor-accounts/useSharedGoals.test.ts \
  tests/features/minor-accounts/useChoreSubmissions.test.ts \
  tests/features/minor-accounts/KidDashboard.test.ts
git commit -m "fix(minor-accounts): gate unsupported family and child flows"
```

---

## Task 8: Delete the Parallel Phase 8 Backend Assumptions

**Files:**
- Delete or replace from halted worktree:
  - `.claude/worktrees/beautiful-rubin-f84de0/app/Domain/Account/Models/MinorAccount.php`
  - `.claude/worktrees/beautiful-rubin-f84de0/app/Domain/Account/Services/MinorRedemptionService.php`
  - `.claude/worktrees/beautiful-rubin-f84de0/database/migrations/tenant/2026_04_20_099999_create_minor_rewards_table.php`
  - `.claude/worktrees/beautiful-rubin-f84de0/database/migrations/tenant/2026_04_20_100002_create_minor_redemption_orders_table.php`
  - `.claude/worktrees/beautiful-rubin-f84de0/database/migrations/tenant/2026_04_20_100004_create_minor_redemption_approvals_table.php`
- Modify: `docs/review/2026-04-21-minor-accounts-phases-1-8-audit.md`

- [ ] **Step 1: Write a short replacement design note inside the main repo**

Document:
- phase 8 must extend the existing UUID-based `Account`, `MinorReward`, `MinorRewardRedemption`, and `MinorPointsLedger`
- no `owner_id`
- no `points_balance`
- no integer-only parallel minor-account identity

- [ ] **Step 2: Remove the invalid phase 8 assumptions from the active execution path**

Rules:
- do not merge the halted worktree artifacts as-is
- keep only reference material that informs the rebuilt API contract

- [ ] **Step 3: Commit the stabilization note in the main repo**

```bash
git add docs/review/2026-04-21-minor-accounts-phases-1-8-audit.md
git commit -m "docs(minor-accounts): record phase 8 rebuild constraints"
```

---

## Task 9: Build the Stabilized Phase 8 Backend API Contract

**Files:**
- Create: `app/Http/Controllers/Api/MinorRewardsCatalogController.php`
- Create: `app/Http/Controllers/Api/MinorRedemptionOrdersController.php`
- Modify: `app/Domain/Account/Routes/api.php`
- Modify: `app/Domain/Account/Services/MinorRewardService.php`
- Create: `app/Domain/Account/Services/MinorRedemptionOrderService.php`
- Create: `tests/Feature/Http/Controllers/Api/MinorRewardsCatalogControllerTest.php`
- Create: `tests/Feature/Http/Controllers/Api/MinorRedemptionOrdersControllerTest.php`

- [ ] **Step 1: Write failing route and contract tests for the canonical phase 8 endpoints**

Required endpoints:
- `GET /api/accounts/minor/{uuid}/rewards/catalog`
- `GET /api/accounts/minor/{uuid}/rewards/{rewardId}`
- `POST /api/accounts/minor/{uuid}/redemptions/submit`
- `GET /api/accounts/minor/{uuid}/redemptions`
- `POST /api/accounts/minor/{uuid}/redemptions/{id}/approve`
- `POST /api/accounts/minor/{uuid}/redemptions/{id}/decline`

- [ ] **Step 2: Run the targeted tests**

Run:

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorRewardsCatalogControllerTest.php \
  tests/Feature/Http/Controllers/Api/MinorRedemptionOrdersControllerTest.php
```

Expected:
- FAIL because these controllers and routes do not yet exist on the stabilized model

- [ ] **Step 3: Implement the controllers and services on top of the existing UUID-based reward and points-ledger system**

Rules:
- no parallel integer-only minor-account model
- quantity-aware affordability checks
- configurable approval thresholds
- no refund unless a linked deduction exists
- approval/decline is idempotent and transactional

- [ ] **Step 4: Re-run the targeted tests**

Expected:
- PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MinorRewardsCatalogController.php \
  app/Http/Controllers/Api/MinorRedemptionOrdersController.php \
  app/Domain/Account/Routes/api.php \
  app/Domain/Account/Services/MinorRewardService.php \
  app/Domain/Account/Services/MinorRedemptionOrderService.php \
  tests/Feature/Http/Controllers/Api/MinorRewardsCatalogControllerTest.php \
  tests/Feature/Http/Controllers/Api/MinorRedemptionOrdersControllerTest.php
git commit -m "feat(minor-accounts): add stabilized phase 8 rewards and redemption APIs"
```

---

## Task 10: Rewire Mobile Phase 8 to the Real API Contract

**Files:**
- Modify: `src/features/minor-accounts/domain/rewardTypes.ts`
- Modify: `src/features/minor-accounts/domain/redemptionTypes.ts`
- Modify: `src/features/minor-accounts/hooks/useMinorRewardsCatalog.ts`
- Modify: `src/features/minor-accounts/hooks/useMinorRewardDetail.ts`
- Modify: `src/features/minor-accounts/hooks/useSubmitRedemption.ts`
- Modify: `src/features/minor-accounts/hooks/useMinorRedemptionOrders.ts`
- Modify: `src/features/minor-accounts/hooks/useParentRedemptionApprovals.ts`
- Modify: `src/features/minor-accounts/presentation/RewardsDashboardWidget.tsx`
- Modify: `src/features/minor-accounts/presentation/RewardsCatalogScreen.tsx`
- Modify: `src/features/minor-accounts/presentation/RewardDetailModal.tsx`
- Modify: `src/features/minor-accounts/components/RewardCard.tsx`
- Modify: `src/features/minor-accounts/components/CatalogFilterSheet.tsx`
- Test: `tests/features/minor-accounts/screens.test.ts`
- Create: `tests/features/minor-accounts/phase8-contracts.test.ts`

- [ ] **Step 1: Write failing mobile contract tests against the stabilized backend DTO shape**

Prove:
- hooks map backend snake_case fields correctly if needed
- no screen assumes fields that backend does not send
- no phase 8 route mismatch remains

- [ ] **Step 2: Run the targeted mobile tests**

Run:

```bash
npm test -- tests/features/minor-accounts/screens.test.ts \
  tests/features/minor-accounts/phase8-contracts.test.ts
```

Expected:
- FAIL because current hooks and types still assume a different contract

- [ ] **Step 3: Fix hooks, DTOs, and screens**

Rules:
- one contract mapping strategy
- no duplicate DTO authorities
- no `theme.colors.warning` or `theme.colors.success` unless the theme is extended formally
- no raw `rgba(...)` values in phase 8 minor-account UI

- [ ] **Step 4: Re-run the targeted mobile tests**

Expected:
- PASS

- [ ] **Step 5: Commit**

```bash
git add src/features/minor-accounts/domain/rewardTypes.ts \
  src/features/minor-accounts/domain/redemptionTypes.ts \
  src/features/minor-accounts/hooks/useMinorRewardsCatalog.ts \
  src/features/minor-accounts/hooks/useMinorRewardDetail.ts \
  src/features/minor-accounts/hooks/useSubmitRedemption.ts \
  src/features/minor-accounts/hooks/useMinorRedemptionOrders.ts \
  src/features/minor-accounts/hooks/useParentRedemptionApprovals.ts \
  src/features/minor-accounts/presentation/RewardsDashboardWidget.tsx \
  src/features/minor-accounts/presentation/RewardsCatalogScreen.tsx \
  src/features/minor-accounts/presentation/RewardDetailModal.tsx \
  src/features/minor-accounts/components/RewardCard.tsx \
  src/features/minor-accounts/components/CatalogFilterSheet.tsx \
  tests/features/minor-accounts/screens.test.ts \
  tests/features/minor-accounts/phase8-contracts.test.ts
git commit -m "fix(minor-accounts): align phase 8 mobile with stabilized rewards API"
```

---

## Task 11: Full Verification Gate

**Files:**
- Modify as needed based on failures from previous tasks

- [ ] **Step 1: Run backend minor-account verification suite**

Run:

```bash
php artisan test tests/Feature/MinorAccountIntegrationTest.php \
  tests/Feature/Http/Middleware/ResolveAccountContextTest.php \
  tests/Feature/Http/Policies/AccountPolicyTest.php \
  tests/Feature/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreControllerTest.php \
  tests/Feature/Http/Controllers/Api/MinorSpendApprovalControllerTest.php \
  tests/Feature/Http/Controllers/Api/MinorRewardTest.php \
  tests/Feature/Http/Controllers/Api/MinorChoreTest.php \
  tests/Feature/Http/Controllers/Api/MinorRewardsCatalogControllerTest.php \
  tests/Feature/Http/Controllers/Api/MinorRedemptionOrdersControllerTest.php \
  tests/Feature/Contracts/MinorAccountMobileContractTest.php
```

Expected:
- PASS

- [ ] **Step 2: Run backend static analysis and formatting**

Run:

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G
./vendor/bin/php-cs-fixer fix --dry-run --diff
```

Expected:
- PASS

- [ ] **Step 3: Run mobile targeted verification**

Run:

```bash
npm test -- tests/features/minor-accounts/minor-contracts.test.ts \
  tests/features/minor-accounts/phase8-contracts.test.ts \
  tests/features/minor-accounts/screens.test.ts
```

Expected:
- PASS

- [ ] **Step 4: Manual verification checklist**

Verify manually:
- child can access their own minor account without patched data
- guardian/co-guardian permissions match policy
- high-value spend creates one approval only
- reward redemption cannot overspend or double-refund
- mobile phase 8 catalog loads against real backend endpoints
- no phase 6/7 dead routes remain active in shipping UI

- [ ] **Step 5: Commit final stabilization pass**

```bash
git add .
git commit -m "chore(minor-accounts): complete stabilization gates before phase 8 resumes"
```

---

## Deliverables

When this plan is complete:
- phases 1-7 will no longer be structurally unsound
- phase 8 will have a real backend contract instead of a forked schema
- mobile will consume a real canonical contract
- reward and approval flows will be transactional and replay-safe
- the product will be in a state where phase 8 can continue without compounding known defects

## Resume Criteria For Phase 8

Phase 8 may resume only after Task 11 passes in full.

Do not resume if:
- any test still requires post-create ownership mutation
- any controller still contains ad hoc minor-account authorization logic
- any phase 8 hook targets a route that does not exist in the backend
- any redemption approval/decline path can create duplicate side effects
- any phase 8 UI still relies on unsupported theme tokens or raw hardcoded overlays
