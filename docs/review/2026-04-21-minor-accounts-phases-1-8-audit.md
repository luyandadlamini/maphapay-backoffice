# Minor Accounts Initiative Audit: Phases 1-8

Date: 2026-04-21

Scope:
- Backend repo: `/Users/Lihle/Development/Coding/maphapay-backoffice`
- Mobile repo: `/Users/Lihle/Development/Coding/maphapayrn`
- Reference plan: `/Users/Lihle/.claude/plans/curious-toasting-kitten.md`
- Docs-local plan link: `docs/plans/curious-toasting-kitten.md`
- Phase 8 backend worktree: `claude/beautiful-rubin-f84de0`
- Phase 8 mobile branch: `feat/phase8-reward-screens`

Audit note:
- Phase 8 was originally excluded, then added back in after the halted Claude branch/worktree needed to be assessed as part of the full foundation review.

Companion stabilization plan:
- `docs/superpowers/plans/2026-04-21-minor-accounts-stabilization-before-phase8.md`

## Executive Summary

### Overall verdict

Phases 1-8 are not complete, not coherent, and not safe as a foundation for continued work.

This initiative has five structural failures:
- the minor account ownership model is internally contradictory
- approval/idempotency/trust enforcement is ordered incorrectly
- mobile phases 6-7 are built against backend APIs that do not exist
- cross-repo contracts drift badly enough that phase 8 is inheriting broken assumptions
- phase 8 backend work starts a parallel redemption architecture that conflicts with the existing model instead of extending it

### Is phase 8 building on a safe foundation?

No.

Phase 8 is not merely being built on unresolved defects. It also introduces fresh architectural drift in the halted branch. The work does not extend the current minor-account implementation cleanly. It forks it.

### Top systemic risks

- Broken child versus guardian ownership semantics
- Duplicate approval creation on retry paths
- Mobile relying on backend guarantees that do not exist
- Split-brain enums, roles, payloads, and reward contracts
- Financial-adjacent mutations without transactions or locking
- Parallel phase 8 backend schema that conflicts with the already-shipped phase 3/4 reward model

### Overall implementation quality rating

`3/10`

The work is not production-grade. Some features exist. The foundation does not hold.

## Findings By Severity

### Critical

#### 0. Phase 8 backend branch introduces a parallel rewards/redemptions architecture that conflicts with the existing system

Repos:
- backend

Files:
- `app/Domain/Account/Models/Account.php:19-33`
- `database/migrations/tenant/2026_04_18_100002_create_minor_reward_redemptions_table.php:12-19`
- `.claude/worktrees/beautiful-rubin-f84de0/app/Domain/Account/Models/MinorAccount.php:7-13`
- `.claude/worktrees/beautiful-rubin-f84de0/app/Domain/Account/Services/MinorRedemptionService.php:38-49`
- `.claude/worktrees/beautiful-rubin-f84de0/app/Domain/Account/Services/MinorRedemptionService.php:75-81`
- `.claude/worktrees/beautiful-rubin-f84de0/database/migrations/tenant/2026_04_20_099999_create_minor_rewards_table.php:12-39`
- `.claude/worktrees/beautiful-rubin-f84de0/database/migrations/tenant/2026_04_20_100002_create_minor_redemption_orders_table.php:12-27`

Evidence:
- The existing account model is UUID-centered and minor accounts are still `Account` records with `user_uuid`, `parent_account_id`, and `permission_level`.
- Existing reward redemptions already use `minor_account_uuid` and `minor_reward_id` UUID columns.
- The phase 8 branch introduces:
  - a `MinorAccount` alias class with no real behavior
  - integer-keyed `minor_redemption_orders`
  - integer-keyed `minor_redemption_approvals`
  - a migration that drops phase 4 reward columns and replaces them with a different phase 8 shape
  - service logic that assumes `owner_id` and `points_balance` exist on minor accounts

Why it matters:
- This is not extension. It is architectural fork.
- The branch is not preserving the existing minor-account and reward model. It is trying to replace it mid-flight.

Impact:
- Phase 8 backend work cannot be merged safely without either breaking earlier work or requiring a full model migration strategy that does not exist.
- Existing mobile/backend contracts drift even further.

Recommended fix direction:
- Stop the parallel schema.
- Define one canonical redemption/order model that extends the existing UUID-based reward system.
- Rebuild phase 8 backend on top of the real minor account and points ledger model.

Replacement design note for phase 8 rebuild:
- Treat the halted worktree as reference material only. It is not mergeable source of truth.
- Phase 8 must extend the existing tenant `Account` minor-account model and the existing UUID-based `MinorReward`, `MinorRewardRedemption`, and `MinorPointsLedger` records.
- Do not introduce `owner_id`, `points_balance`, or a second integer-only minor-account identity model.
- Approval, decline, catalog, and order APIs must be implemented against the stabilized UUID contract already used by phases 1-7.
- Any new redemption workflow must preserve ledger-linked deductions and remain compatible with the existing audit trail model.

#### 1. Minor account ownership model is internally contradictory

Repos:
- backend

Files:
- `app/Http/Controllers/Api/MinorAccountController.php:87-94`
- `app/Http/Middleware/ResolveAccountContext.php:83-105`
- `app/Policies/AccountPolicy.php:20-36`
- `tests/Feature/MinorAccountIntegrationTest.php:89-93`

Evidence:
- Minor account creation sets `accounts.user_uuid` to the parent user.
- `ResolveAccountContext` only synthesizes child access if `accounts.user_uuid === child_user_uuid`.
- `AccountPolicy::viewMinor()` also treats the child as the owner of the minor account.
- The integration test rewrites `user_uuid` after account creation to make the flow work.

Why it matters:
- Child identity is not modeled consistently.
- Authorization, onboarding, child-mode access, and age transition logic are built on conflicting assumptions.
- Auditability of who actually owns the account is corrupted.

Impact:
- Unsafe basis for child login, guardian delegation, and age-18 conversion.
- Hidden defects will surface as authorization bugs and lifecycle corruption.

Recommended fix direction:
- Define one canonical ownership model for minor accounts.
- Decide explicitly whether the child is represented by `accounts.user_uuid`, by membership, or by a dedicated child identity relation.
- Refactor controllers, middleware, policies, and tests to match that single model.

#### 2. Minor spend approval path bypasses idempotency and trust policy

Repos:
- backend

Files:
- `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php:192-230`
- `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php:263-309`

Evidence:
- For high-value minor spend, a pending approval is created and a `202` response is returned before idempotency replay checks.
- The same branch returns before mobile trust-policy evaluation.

Why it matters:
- Retry safety is broken.
- Security policy is branch-dependent.
- Correctness depends on the client behaving well.

Impact:
- Duplicate approvals can be created on retries.
- Trust policy can be bypassed on the pending-approval path.

Recommended fix direction:
- Run replay/idempotency validation before any approval record creation.
- Run trust-policy evaluation before any stateful side effect.
- Treat pending approval creation as a transactional, idempotent initiation outcome.

#### 3. Points and chore controller authorization is written against a nonexistent schema

Repos:
- backend

Files:
- `app/Http/Controllers/Api/MinorPointsController.php:228-234`
- `app/Http/Controllers/Api/MinorChoreController.php:346-349`
- `app/Http/Controllers/Api/MinorChoreController.php:377-380`
- `app/Domain/Account/Models/AccountMembership.php:10-44`
- `database/migrations/2026_04_15_100000_create_account_memberships_table.php:12-32`

Evidence:
- Both controllers query `account_memberships.minor_account_uuid`.
- `account_memberships` has no such column.
- The actual schema only has `account_uuid`, `account_type`, role, status, tenant, and user identity.

Why it matters:
- The authorization layer is not merely weak. It is structurally false.

Impact:
- Guardian/child access checks are invalid.
- Points and chore flows cannot be trusted as secure.

Recommended fix direction:
- Delete the custom fake authorization logic.
- Centralize minor-account access checks behind a real policy/service that matches the actual schema.

#### 4. Mobile phases 6-7 depend on backend endpoints that do not exist

Repos:
- backend
- mobile

Files:
- `app/Domain/Account/Routes/api.php:30-56`
- `src/features/minor-accounts/hooks/useSharedGoals.ts:9-16`
- `src/features/minor-accounts/hooks/useFamilyMembers.ts:9-16`
- `src/features/minor-accounts/hooks/useLearningModules.ts:9-16`
- `src/features/minor-accounts/hooks/useChoreSubmissions.ts:65-80`
- `src/features/minor-accounts/hooks/usePendingRedemptions.ts:20-28`

Evidence:
- Mobile calls:
  - `/api/accounts/minor/{uuid}/family-goals`
  - `/api/accounts/parent/{uuid}/children`
  - `/api/accounts/minor/{uuid}/learning-modules`
  - `/api/accounts/minor/{uuid}/chores/pending`
  - `/api/accounts/minor/{uuid}/redemptions`
- Backend routes expose none of those paths.

Why it matters:
- These phases are not implemented end-to-end.
- The product is being evaluated as if two repos are separate. They are not.

Impact:
- Phase 7 is materially absent.
- Phase 6 is partly shell UI over missing backend support.

Recommended fix direction:
- Stop treating mobile placeholders as completed feature work.
- Either build the missing backend APIs or remove/disable the dependent mobile flows.

#### 5. Backend account payload omits fields mobile minor flows require

Repos:
- backend
- mobile

Files:
- `app/Http/Controllers/Api/Auth/MobileAuthController.php:812-822`
- `app/Http/Controllers/Api/Auth/LoginController.php:402-414`
- `app/Http/Controllers/Api/AccountController.php:504-515`
- `src/features/account/domain/types.ts:1-14`
- `src/features/account/presentation/AccountSwitcherSheet.tsx:188-221`

Evidence:
- Mobile expects `parent_account_uuid`, `permission_level`, and `account_tier`.
- Backend membership transforms do not return those fields.

Why it matters:
- The mobile account switcher and child-mode UI are built on data the backend never supplies.

Impact:
- Child account display is incomplete and inconsistent.
- Minor account flows are brittle even before phase 8.

Recommended fix direction:
- Define a real account-membership DTO for mobile.
- Include minor-account fields explicitly and consistently in all auth/account payloads.

#### 5A. Phase 8 mobile calls endpoints the phase 8 backend branch never implemented

Repos:
- backend
- mobile

Files:
- `.claude/worktrees/beautiful-rubin-f84de0/app/Domain/Account/Routes/api.php:43-48`
- `src/features/minor-accounts/hooks/useMinorRewardsCatalog.ts:27-29`
- `src/features/minor-accounts/hooks/useMinorRewardDetail.ts:16-18`
- `src/features/minor-accounts/hooks/useSubmitRedemption.ts:12-15`

Evidence:
- Mobile phase 8 calls:
  - `/api/accounts/minor/{childUuid}/rewards/catalog`
  - `/api/accounts/minor/{childUuid}/rewards/{rewardId}`
  - `/api/accounts/minor/{childUuid}/redemptions/submit`
- The backend phase 8 worktree route file still only exposes the old phase 4 reward routes:
  - `/accounts/minor/{uuid}/rewards`
  - `/accounts/minor/{uuid}/rewards/{rewardId}/redeem`
  - `/accounts/minor/{uuid}/rewards/redemptions`
- No phase 8 controllers or route wiring were found in the worktree.

Why it matters:
- The phase 8 frontend and backend are not even speaking about the same API surface.

Impact:
- The halted work is non-integrable in its current state.

Recommended fix direction:
- Freeze UI endpoint work until the backend route contract is defined and implemented.
- Do not add more phase 8 mobile surface area until the backend API exists.

### High

#### 6. Reward redemption and chore approval are non-transactional

Repos:
- backend

Files:
- `app/Domain/Account/Services/MinorRewardService.php:17-57`
- `app/Domain/Account/Services/MinorChoreService.php:96-138`

Evidence:
- Reward redemption deducts points, creates a redemption, patches ledger linkage, and decrements stock without a transaction or locking.
- Chore approval updates completion state and awards points without a transaction.

Why it matters:
- These are stateful, value-bearing workflows.
- Partial updates and concurrent mutation are not handled.

Impact:
- Double-spend points
- oversell inventory
- split-brain completion state

Recommended fix direction:
- Wrap these flows in database transactions.
- Add row-level locking or optimistic concurrency where appropriate.
- Make repeated submissions and approvals idempotent.

#### 7. Phase drift is severe and mostly undocumented

Repos:
- backend
- mobile

Files:
- `/Users/Lihle/.claude/plans/curious-toasting-kitten.md:521-528`
- `docs/superpowers/plans/2026-04-18-minor-accounts-phase4-points-chores.md:1-29`
- `docs/superpowers/plans/HANDOFF-minor-accounts-phase2.md:66-88`

Evidence:
- Original Phase 4 is backend family features.
- Repo “Phase 4” was redefined to points/rewards/chores.
- The handoff for Phase 2 claims age transition, age-18 conversion, Level 7 takeover, and freeze cascade are in scope.
- Those lifecycle features were not found in the implementation.

Why it matters:
- Scope was not merely rescheduled. It was substituted.
- That breaks plan traceability and falsely inflates completion claims.

Impact:
- Family-feature groundwork is missing.
- Lifecycle debt remains hidden while downstream phases proceed.

Recommended fix direction:
- Restore phase traceability.
- Mark unimplemented plan items explicitly as not done.
- Stop renumbering unrelated work as if it satisfied the approved plan.

#### 8. Mobile role model is split-brain

Repos:
- mobile

Files:
- `src/features/account/domain/types.ts:4-13`
- `src/features/account/hooks/useAccountPermissions.ts:4-48`

Evidence:
- `AccountSummary.role` excludes `guardian`, `co_guardian`, and `child`.
- `useAccountPermissions` defines a second wider role union and casts into it.

Why it matters:
- There is no canonical role contract.
- Permission logic drifts from account typing immediately.

Impact:
- UI behavior can diverge from actual backend roles.
- Type safety is performative, not real.

Recommended fix direction:
- Define one canonical role type in the account domain.
- Make permission calculation consume that single type.

#### 9. Sensitive notifications are not persisted

Repos:
- backend

Files:
- `app/Domain/Account/Services/MinorNotificationService.php:37-48`

Evidence:
- “Notifications” for chore assignment, approval, rejection, points, and reward redemption are just debug logs.

Why it matters:
- Child-control workflows need durable traceability.

Impact:
- No operational audit trail for user-facing lifecycle events.
- Support and reconciliation become guesswork.

Recommended fix direction:
- Persist notifications or emit durable domain events tied to the affected minor account.

#### 10. Approval authority is broader than the stored approval target

Repos:
- backend

Files:
- `app/Http/Controllers/Api/MinorSpendApprovalController.php:111-120`
- `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php:209-219`

Evidence:
- Approval records store `guardian_account_uuid`.
- Approval action checks only whether the user is any guardian or co-guardian of the minor account.
- It does not verify alignment with the intended approving guardian account.

Why it matters:
- Stored approval-routing metadata is ignored.

Impact:
- Approval ownership is ambiguous.
- Audit history cannot cleanly explain who was supposed to act versus who acted.

Recommended fix direction:
- Decide whether any guardian can act or whether the approval is explicitly targeted.
- Enforce that decision in code and record the acting guardian account on decision.

#### 10A. Phase 8 redemption approval flow is non-idempotent and can double-apply

Repos:
- backend

Files:
- `.claude/worktrees/beautiful-rubin-f84de0/app/Domain/Account/Services/MinorRedemptionService.php:119-149`

Evidence:
- `approveRedemption()` does not verify that the order is in `awaiting_approval`.
- It does not verify that the approval record is still `pending`.
- It updates approval, deducts points, queues merchant work, and updates status with no transaction and no repeat-call protection.

Why it matters:
- A repeated approval call can deduct points again and create duplicate queue entries.

Impact:
- Points double-deduction
- duplicate merchant fulfillment attempts
- corrupted order state

Recommended fix direction:
- Add status guards and a transaction.
- Treat approval as a one-time state transition with idempotent re-entry behavior.

#### 10B. Phase 8 decline flow can mint points that were never deducted

Repos:
- backend

Files:
- `.claude/worktrees/beautiful-rubin-f84de0/app/Domain/Account/Services/MinorRedemptionService.php:157-193`

Evidence:
- `declineRedemption()` always awards a `redemption_refund`.
- It does not verify that points were previously deducted.
- High-value redemptions sit in `awaiting_approval` with no deduction yet.

Why it matters:
- Declining an awaiting-approval order can create a free points credit.

Impact:
- Direct financial/game-economy corruption.

Recommended fix direction:
- Only refund if a prior deduction exists and is linked to that redemption.
- Record deduction state explicitly and gate refunds on it.

#### 10C. Phase 8 eligibility validation ignores quantity and configurable thresholds

Repos:
- backend

Files:
- `.claude/worktrees/beautiful-rubin-f84de0/app/Domain/Account/Services/MinorRedemptionService.php:41-61`
- `.claude/worktrees/beautiful-rubin-f84de0/app/Domain/Account/Services/MinorRewardService.php:80-116`
- `docs/superpowers/prompts/2026-04-20-minor-accounts-phase8-agent-prompt.md:59-63`

Evidence:
- Eligibility validation checks `reward->price_points` against current points, not `price_points * quantity`.
- Approval requirement is hardcoded to `>250`.
- The phase 8 brief explicitly says approval should be driven by `redemption_approval_threshold`.

Why it matters:
- A child can pass pre-checks for a quantity they cannot actually afford.
- Parent policy is hardcoded instead of configurable.

Impact:
- Invalid pending approvals
- inconsistent UX
- policy drift

Recommended fix direction:
- Validate total redemption cost, not unit cost.
- Move threshold logic to explicit parent settings or backend policy config.

#### 10D. Phase 8 mobile continues the contract drift with new camelCase DTOs and route assumptions

Repos:
- backend
- mobile

Files:
- `src/features/minor-accounts/domain/rewardTypes.ts:4-17`
- `src/features/minor-accounts/domain/redemptionTypes.ts:14-58`
- `src/features/minor-accounts/hooks/useMinorRewardsCatalog.ts:27-29`
- `src/features/minor-accounts/hooks/useMinorRewardDetail.ts:16-18`
- `src/features/minor-accounts/hooks/useSubmitRedemption.ts:12-15`
- `.claude/worktrees/beautiful-rubin-f84de0/app/Domain/Account/Services/MinorRewardService.php:181-194`
- `.claude/worktrees/beautiful-rubin-f84de0/app/Domain/Account/Services/MinorRedemptionService.php:83-110`

Evidence:
- Mobile phase 8 types expect camelCase fields like `pricePoints`, `imageUrl`, `partnerId`, `requiresApproval`, `expiresAt`.
- Backend phase 8 services return snake_case fields and a materially different route surface.
- No mapper layer exists in the hooks.

Why it matters:
- Phase 8 repeats the same cross-repo DTO drift that already existed in phases 1-7.

Impact:
- More brittle UI code
- more adapter debt
- higher merge risk

Recommended fix direction:
- Define a canonical phase 8 API contract before further UI work.
- Add explicit response mapping if backend naming cannot change.

#### 10E. Phase 8 mobile violates its own implementation rules

Repos:
- mobile

Files:
- `src/features/minor-accounts/presentation/RewardDetailModal.tsx:63-64`
- `src/features/minor-accounts/presentation/RewardDetailModal.tsx:80-81`
- `src/features/minor-accounts/presentation/RewardDetailModal.tsx:140-141`
- `src/features/minor-accounts/presentation/RewardsDashboardWidget.tsx:120`
- `src/features/minor-accounts/components/CatalogFilterSheet.tsx:77`
- `docs/superpowers/prompts/2026-04-20-minor-accounts-phase8-agent-prompt.md:46-49`
- `docs/superpowers/prompts/2026-04-20-minor-accounts-phase8-agent-prompt.md:63-64`

Evidence:
- Phase 8 prompt says no hardcoded `rgba` and warns that `warning` and `success` must be replaced with standard tokens or added deliberately.
- The implementation uses raw `rgba(...)` overlays and references `theme.colors.warning` / `theme.colors.success`.

Why it matters:
- The work does not even meet its own stated engineering constraints.

Impact:
- Theme inconsistency and likely runtime/design drift

Recommended fix direction:
- Replace raw overlay colors with real theme tokens.
- Use supported Material Design tokens or formally extend the theme first.

### Medium

#### 11. Mobile parent and child dashboards contain dead flows and placeholders

Repos:
- mobile

Files:
- `src/features/account/presentation/AccountSwitcherSheet.tsx:27-32`
- `src/features/account/presentation/AccountSwitcherSheet.tsx:100-109`
- `src/features/minor-accounts/presentation/KidDashboard.tsx:74-90`
- `src/features/minor-accounts/presentation/KidDashboard.tsx:219-231`
- `src/features/minor-accounts/presentation/FamilyDashboardScreen.tsx:68-88`
- `src/features/minor-accounts/presentation/InsightsCard.tsx:10-35`

Evidence:
- “Create Child Account” routes to `/(modals)/create-child-account`, which does not exist.
- Retry logic calls `window.location.reload` inside React Native code.
- Parent review modal is a placeholder.
- Family goals are a placeholder.
- Insights are static strings, not backed by data.

Why it matters:
- This is not shippable dashboard work.

Impact:
- UX claims completion that the code does not substantiate.

Recommended fix direction:
- Remove dead routes.
- Replace placeholders with disabled states or real implementation.
- Stop counting static mock UI as delivered functionality.

#### 12. Reward contracts drift badly across repos

Repos:
- backend
- mobile

Files:
- `app/Http/Controllers/Api/MinorPointsController.php:95-120`
- `app/Http/Controllers/Api/MinorPointsController.php:141-151`
- `src/features/minor-accounts/domain/rewardTypes.ts:4-34`
- `src/features/minor-accounts/hooks/useRewardsShop.ts:5-33`
- `src/features/minor-accounts/hooks/useRedeemReward.ts:12-41`

Evidence:
- Mobile expects `current_points`, nested `data.redemption`, numeric `id`, `pricePoints`, `isFeatured`, `imageUrl`.
- Backend returns different field names, different route shapes, and different payload structure.

Why it matters:
- Phase 8 is being forced to bridge pre-existing contract debt.

Impact:
- Higher regression risk and duplicated mapper logic.

Recommended fix direction:
- Freeze and define a canonical reward API contract before extending it further.

#### 13. Tests do not prove the system

Repos:
- backend
- mobile

Files:
- `tests/features/minor-accounts/useSharedGoals.test.ts:1-62`
- `tests/features/minor-accounts/useChoreSubmissions.test.ts:1-105`
- `tests/features/minor-accounts/KidDashboard.test.ts:1-110`
- `tests/Feature/MinorAccountIntegrationTest.php:34-159`

Evidence:
- Mobile tests are largely source-string assertions.
- Backend integration test rewrites stored ownership to make the workflow pass.

Why it matters:
- The tests are compensating for broken design or merely checking that files contain text.

Impact:
- False confidence.

Recommended fix direction:
- Replace source-text assertions with behavior tests.
- Add contract, authorization, idempotency, and concurrency tests.

#### 13A. Phase 8 tests continue the source-reading anti-pattern

Repos:
- mobile

Files:
- `tests/features/minor-accounts/screens.test.ts:1-320`

Evidence:
- The screen tests read source files from disk and assert that strings exist.
- They do not render the components, exercise hooks, or validate integration behavior.

Why it matters:
- Phase 8 increases UI complexity while retaining fake test coverage.

Impact:
- Very low confidence in real screen behavior

Recommended fix direction:
- Replace file-string assertions with component tests and hook-mocking tests.

## Phase-By-Phase Audit

### Pre-Phase: Update Existing Infrastructure

Planned scope:
- Update `AccountSummary`, `AccountRole`, `useAccountPermissions`, `AccountSwitcherSheet`
- Design child authentication model

Implemented scope:
- `AccountSummary` now allows `account_type = 'minor'`
- `AccountSwitcherSheet` includes minor-account display logic
- `useAccountPermissions` includes minor-facing permissions

Missing or partial work:
- Canonical role typing is not fixed
- Child authentication model is not actually settled in the scanned code
- Payload support for minor-specific fields is missing from backend

Deviations:
- Instead of extending one canonical role model, the mobile code created a second role authority

Correctness concerns:
- UI permissions are partly frontend-invented rather than backend-derived

Architecture concerns:
- Split-brain account role typing

Reuse/reinvention concerns:
- Reinvented `AccountRole` locally in a hook instead of fixing the domain type

Cross-repo concerns:
- Backend account payloads do not satisfy mobile’s expected fields

Risk level:
- `High`

### Phase 1: Backend Core

Planned scope:
- Account model, permissions, guardian relationships

Implemented scope:
- Minor account creation
- Guardian/co-guardian role usage via `account_memberships`
- Co-guardian invite and accept flows
- Permission-level update endpoint

Missing or partial work:
- Child identity model is not coherent
- Guardian relationship semantics are inconsistent with child access semantics

Deviations:
- The implementation rejects the original `minor_guardians` table idea, which is valid
- The replacement architecture was not carried through consistently

Correctness concerns:
- Child ownership and guardian access logic conflict

Architecture concerns:
- Core model ambiguity infects policies and middleware

Reuse/reinvention concerns:
- Tests compensate for broken design instead of proving it

Cross-repo concerns:
- Mobile minor-mode assumptions require fields and semantics phase 1 does not expose cleanly

Risk level:
- `Critical`

### Phase 2: Backend Controls

Planned scope:
- Limits, blocks, approval workflow, emergency allowance

Implemented scope:
- Limit validation
- Approval threshold behavior
- Emergency allowance endpoint
- Coarse approval lifecycle

Missing or partial work:
- Hand-off scope around Grow-to-Rise transition, age-18 conversion, Level 7 takeover, and freeze cascade was not found

Deviations:
- Documented scope and implemented scope diverge badly

Correctness concerns:
- Approval creation occurs before idempotency and trust checks

Architecture concerns:
- Approval branch bypasses core system guarantees

Reuse/reinvention concerns:
- Approval logic stores guardian routing metadata but does not enforce it consistently

Cross-repo concerns:
- Mobile cannot safely assume backend-controlled guarantees on approval state

Risk level:
- `Critical`

### Phase 3: Backend Rewards and Chores

Planned scope:
- Extend rewards and add chore system

Implemented scope:
- Points ledger
- Reward catalog and redemption records
- Chore definitions and completion records
- API controllers and services

Missing or partial work:
- Durable notifications/audit trace
- Strong concurrency and retry protection

Deviations:
- “Controllers are thin” is only partly true; controller auth is custom and wrong

Correctness concerns:
- Broken auth
- Non-transactional redemption and approval flows

Architecture concerns:
- Authorization logic leaks into controllers and is detached from real policy/schema

Reuse/reinvention concerns:
- Custom access checks should have reused a single access primitive

Cross-repo concerns:
- Reward endpoints and payloads do not align with mobile expectations

Risk level:
- `Critical`

### Phase 4: Backend Family Features

Planned scope:
- Extend SavingsPocket and GroupPocket for family and teen goals

Implemented scope:
- No evidence of the planned family-goals backend

Missing or partial work:
- Family goals
- Parent-to-children endpoint
- Learning modules API support
- Teen savings-group extension

Deviations:
- Repo-level “Phase 4” was reassigned to points/rewards/chores work

Correctness concerns:
- None to evaluate meaningfully because the planned scope is effectively absent

Architecture concerns:
- Phase tracking became untrustworthy

Reuse/reinvention concerns:
- Existing savings/group abstractions were not visibly extended as the plan required

Cross-repo concerns:
- Mobile family hooks target backend APIs that do not exist

Risk level:
- `Critical`

### Phase 5: Mobile Parent Dashboard

Planned scope:
- Create child, manage children, controls

Implemented scope:
- Some account-switcher and minor-account display work
- Some control-oriented hook and UI scaffolding

Missing or partial work:
- Create-child modal flow is dead
- Parent-child list endpoint is missing from backend

Deviations:
- UI exists ahead of usable backend support

Correctness concerns:
- Parent control screens cannot rely on stable backend semantics

Architecture concerns:
- Mobile logic is tightly coupled to payload assumptions the backend does not honor

Reuse/reinvention concerns:
- Frontend permission matrix duplicates authority the backend should own

Cross-repo concerns:
- Backend/mobile contract mismatch makes the parent dashboard brittle

Risk level:
- `High`

### Phase 6: Mobile Child Dashboard

Planned scope:
- Level bar, spending insights, chores, points

Implemented scope:
- Child dashboard screen shell
- Chore count display
- Pending redemptions card integration point

Missing or partial work:
- Level progression bar not substantiated by backend contract
- Spending insights are static copy
- Parent review modal is placeholder
- Retry behavior is not React Native-safe

Deviations:
- A UI shell is being treated as feature completion

Correctness concerns:
- Dashboard depends on backend ownership and rewards contracts that are already unstable

Architecture concerns:
- Presentation logic is compensating for absent or static data

Reuse/reinvention concerns:
- Static insights are a fake substitute for a real data-backed module

Cross-repo concerns:
- Reward and child-state payloads drift from backend

Risk level:
- `High`

### Phase 7: Mobile Family Features

Planned scope:
- Goals, siblings, learning modules

Implemented scope:
- Hooks and screens exist for family goals, siblings, and learning modules

Missing or partial work:
- The backend APIs those hooks call do not exist
- Shared goals UI is placeholder text

Deviations:
- Mobile implemented speculative client code without backend contract completion

Correctness concerns:
- None of the key family flows are proven end-to-end

Architecture concerns:
- This phase is client fantasy over absent server support

Reuse/reinvention concerns:
- Existing savings/group abstractions from the plan were not visibly reused

Cross-repo concerns:
- Severe route and payload mismatch

Risk level:
- `Critical`

### Phase 8: Rewards and Shop

Planned scope:
- Child rewards dashboard
- reward catalog browser
- redemption flow
- order history and tracking
- parent approvals and controls
- merchant integration
- backend APIs, notifications, and tests

Implemented scope:
- Backend halted branch created partial models, migrations, and services for a new redemption/order path
- Mobile added hooks, domain types, and a set of rewards UI screens/components

Missing or partial work:
- No backend controllers for phase 8
- No route wiring for the mobile phase 8 endpoints
- No parent approvals API surface
- No merchant fulfillment controller/service entrypoints
- No order history screen in the current mobile diff
- No KidDashboard tab integration in the current mobile branch diff

Deviations:
- Backend phase 8 work did not extend the existing UUID-based reward/redemption model
- Mobile phase 8 work moved ahead assuming a new API contract that backend never exposed

Correctness concerns:
- Redemption approval flow can double-apply
- Decline flow can refund points that were never deducted
- Eligibility check ignores quantity
- Threshold is hardcoded instead of config/policy-driven

Architecture concerns:
- Backend phase 8 is a parallel system, not an extension
- Mobile phase 8 compounds DTO drift instead of fixing it

Reuse/reinvention concerns:
- Existing `MinorReward` / `MinorRewardRedemption` lineage was not cleanly reused
- A `MinorAccount` alias was introduced instead of using the real account model coherently

Cross-repo concerns:
- Mobile endpoints do not exist in the backend phase 8 worktree
- Mobile expects camelCase DTOs while backend services produce snake_case payloads

Risk level:
- `Critical`

## Unmerged Branch Review

### Backend branch: `claude/minor-accounts-phase3`

Verdict:
- Do not merge this branch wholesale.
- Salvage selected work only after the ownership and access model is repaired.

What this branch gets right:
- It replaces debug-log pseudo-notifications with persisted notification records via `MinorNotification` and `MinorNotificationService`.
- It adds a plausible webhook path with `MinorWebhook`, `MinorWebhookService`, and `DeliverMinorWebhookJob`.
- It adds useful guardian analytics endpoints for spending summary, category breakdown, and transaction history.

What is still broken:
- It inherits the same contradictory child-account ownership model as `main`. Minor account creation still writes the guardian into `accounts.user_uuid`, not the child.
- Its main integration test does not prove the real system. It manually inserts a child-owned account row and then mocks `AccountPolicy`, which bypasses the actual authorization defect instead of verifying it.
- The branch adds more lifecycle surfaces on top of the broken core. That increases blast radius without fixing identity, ownership, or policy truth first.

Evidence:
- `.claude/worktrees/minor-accounts-phase3/app/Http/Controllers/Api/MinorAccountController.php:90-99`
- `.claude/worktrees/minor-accounts-phase3/tests/Feature/MinorAccountPhase3IntegrationTest.php:65-118`
- `.claude/worktrees/minor-accounts-phase3/app/Domain/Account/Services/MinorNotificationService.php:18-88`
- `.claude/worktrees/minor-accounts-phase3/app/Domain/Account/Services/MinorWebhookService.php:15-30`
- `.claude/worktrees/minor-accounts-phase3/app/Domain/Account/Jobs/DeliverMinorWebhookJob.php:44-97`
- `.claude/worktrees/minor-accounts-phase3/app/Http/Controllers/Api/MinorAccountAnalyticsController.php:29-137`
- `.claude/worktrees/minor-accounts-phase3/database/migrations/tenant/2026_04_17_200001_create_minor_notifications_table.php:12-21`

Disposition:
- Keep as a temporary salvage branch only.
- Extract notifications, webhooks, and analytics deliberately.
- Delete the branch after extraction. It is not a merge target.

Risk level:
- `High`

### Mobile branch: `claude/angry-shockley-968ccf`

Verdict:
- Do not merge this branch.
- Reuse UI ideas selectively only after backend contracts are stabilized.

What this branch gets right:
- It contains presentable parent-dashboard UI and a more complete child-management flow shell.
- It has reusable visual work for spending-limits and co-guardian screens.

What is still broken:
- The branch invents a parallel `/api/minor-accounts` API surface that the backend does not expose for create/detail/limits/freeze/top-up/co-guardian flows.
- It invents DTOs that do not match the backend. The create-child flow submits `display_name`, `daily_limit_szl`, and `monthly_limit_szl`, while the current backend controller expects `name` and derives tier and permission level itself.
- The home-screen logic is obsolete. It still renders “Kid Mode is coming in Phase 6,” which is already stale relative to the current mainline.
- It still contains hardcoded color values in the home tab file, so even as UI work it is not internally clean.

Evidence:
- `/Users/Lihle/Development/Coding/maphapayrn/.claude/worktrees/angry-shockley-968ccf/src/features/minor-accounts/api/useMinorAccounts.ts:15-33`
- `/Users/Lihle/Development/Coding/maphapayrn/.claude/worktrees/angry-shockley-968ccf/src/features/minor-accounts/api/useMinorAccountMutations.ts:13-113`
- `/Users/Lihle/Development/Coding/maphapayrn/.claude/worktrees/angry-shockley-968ccf/src/features/minor-accounts/domain/types.ts:15-70`
- `/Users/Lihle/Development/Coding/maphapayrn/.claude/worktrees/angry-shockley-968ccf/src/features/minor-accounts/presentation/CreateChildAccountScreen.tsx:166-175`
- `/Users/Lihle/Development/Coding/maphapayrn/.claude/worktrees/angry-shockley-968ccf/src/app/(tabs)/index.tsx:88-128`
- `/Users/Lihle/Development/Coding/maphapayrn/.claude/worktrees/angry-shockley-968ccf/src/app/(tabs)/index.tsx:127-153`
- `.claude/worktrees/minor-accounts-phase3/app/Domain/Account/Routes/api.php:30-45`
- `app/Http/Controllers/Api/MinorAccountController.php:79-117`

Disposition:
- Keep only if you intend to harvest UI structure.
- Do not treat it as valid application work.
- Delete it after extracting any reusable presentation code. It is not a merge target.

Risk level:
- `High`

## Reinvention Report

### Where the implementation reinvented existing solutions

- Mobile created a second `AccountRole` source of truth in `useAccountPermissions.ts` instead of fixing the canonical domain type.
- Mobile created a parallel minor reward contract in `rewardTypes.ts` instead of extending a shared reward model or adding a deliberate mapper layer.
- Backend points/chore controllers wrote custom access logic instead of reusing one correct authorization primitive.
- Phase 8 backend created a parallel redemption/order architecture instead of extending the UUID-based phase 3/4 reward system.
- Repo-level planning artifacts redefined phase numbering and scope instead of preserving traceability to the approved plan.

### What should have been reused instead

- One canonical account role/type model
- One canonical backend-to-mobile account membership payload
- One real guardian/child access primitive
- Existing savings/group abstractions for planned family features
- The existing minor reward and points-ledger model, extended deliberately rather than replaced

### What the canonical abstraction should be

- `MinorAccountIdentity`: child identity, guardian relationships, and ownership semantics
- `MinorAccountAccessPolicy`: one place for guardian/co-guardian/child access rules
- `MinorAccountSummaryDTO`: backend-defined payload for mobile account switching and mode selection
- `MinorRewardContract`: one API contract with explicit mobile mapping only if necessary
- `MinorRedemptionLifecycle`: one canonical order/approval/refund model tied to the actual points ledger

### Consolidation and refactor opportunities

- Collapse mobile role typing into one domain type
- Normalize reward DTOs before phase 8 extends them further
- Delete the parallel phase 8 branch schema assumptions around `owner_id`, `points_balance`, and integer-only minor identity
- Delete controller-level fake auth and replace it with shared access checks
- Realign docs and phase labels to the original plan

## Test Gap Report

### Critical missing tests

- Idempotent retry behavior for high-value spend approval creation
- Concurrency on reward redemption with limited stock
- Concurrency and repeated submission on chore completion review
- Child access flow without test-time mutation of `accounts.user_uuid`
- Grow-to-Rise transition
- Age-18 conversion
- Level 7 takeover request
- Parent freeze/delete cascade to child accounts

### Weak tests

- Mobile tests that assert source-file strings instead of runtime behavior
- Backend tests that patch persisted records to force broken workflows into a passing state

### Important scenarios not proven

- End-to-end parent creates child, child accesses account, guardian/co-guardian perform allowed actions
- Mobile account payload compatibility across login and account refresh flows
- Cross-repo reward redemption contract compatibility
- Phase 8 approval retry, double-submit, decline-before-deduct, and quantity validation behavior

### Manual verification still required

- `Needs verification`: whether any hidden branch or disconnected module implements lifecycle features not exposed by current routes and major entry points
- Runtime backend verification was not completed in this audit environment because the configured test MySQL endpoint on `127.0.0.1:3307` was unavailable

## Remediation Plan

### Must fix before phase 8 depends on it further

- Repair the minor account ownership and child identity model
- Reorder send-money approval logic so idempotency and trust enforcement happen before approval creation
- Replace controller-level fake auth with one real minor-account access primitive
- Normalize backend/mobile reward and account payload contracts
- Stop mobile from depending on missing family and lifecycle endpoints
- Scrap or redesign the phase 8 parallel backend schema before any merge
- Fix phase 8 approval/decline logic before any continued implementation

### Should fix soon

- Add transactions and locking to reward redemption and chore approval flows
- Persist notifications or domain events for child-control lifecycle actions
- Align approval decision rules with stored guardian routing intent
- Remove or disable dead mobile routes and placeholders

### Cleanup and consolidation work

- Collapse duplicate mobile role types
- Remove AI-shaped thin wrappers and duplicate permission logic
- Realign phase docs and handoff docs to the approved plan and actual implementation status
- Collapse phase 8 contracts onto one DTO strategy instead of snake_case/camelCase drift

### Recommended integration and contract tests

- Parent creates child, child logs in or accesses child mode, guardian/co-guardian permissions verified
- Minor approval retry tests with identical and conflicting idempotency keys
- Concurrent reward redemption under low stock
- Contract snapshot tests for account payloads and reward payloads across backend/mobile
- Family-feature route availability and shape tests before enabling family UI
- Phase 8 order approval/decline idempotency tests
- Phase 8 quantity-aware affordability tests
- Phase 8 route-contract tests for catalog/detail/submit flows

## Final Verdict

### Are phases 1-8 actually complete?

No.

### Are they production-ready?

No.

### Is the implementation structurally sound?

No.

### Can phase 8 safely build on this foundation?

No.

### Top blockers

1. Contradictory minor-account ownership and child identity semantics
2. Approval creation before idempotency and trust enforcement
3. Missing backend support for major mobile phase 6-7 flows
4. Cross-repo contract drift in account and reward payloads
5. Non-transactional state mutation in rewards and chores
6. Phase 8 backend branch forks the existing model instead of extending it
