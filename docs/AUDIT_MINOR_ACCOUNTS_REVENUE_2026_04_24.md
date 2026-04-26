# MaphaPay Engineering Audit — Minor Accounts & Revenue/Profit
**Date:** 2026-04-24
**Audited by:** Claude Code (4× parallel agents)
**Scope:** Minor Accounts domain + Revenue/Profit domain — backoffice repo + mobile repo (cross-repo consistency)
**Repos audited:**
- Backoffice: `maphapay-backoffice` (Laravel 12 / PHP 8.4)
- Mobile: `maphapayrn` (React Native)

---

## 1. Executive Summary

### Overall Readiness Score: 41 / 100

### Go / No-Go Recommendation: NO-GO

The codebase demonstrates strong architectural discipline (DDD, event sourcing, multi-tenancy, CQRS) and the revenue domain is production-safe for its current MVP scope. However, the Minor Accounts domain has **multiple P0 issues that can cause irreversible data loss or allow financial bypass** before the system sees high volume. These must be resolved before any expanded minor-account rollout.

### Top 5 Risks

| # | Risk | Severity |
|---|------|----------|
| 1 | `onDelete('cascade')` on `parent_account_id` silently destroys all child accounts and financial records when a parent account is deleted, with no lifecycle event or audit trail | P0 |
| 2 | Age validation performed only at account creation — a child who turns 18 retains minor-account privileges until a scheduled job runs (up to 24h+ gap) | P0 |
| 3 | Race condition: funding attempt deduplication uses an unlocked SELECT before INSERT — two concurrent requests with the same hash both proceed past the guard | P0 |
| 4 | `forceFill()->save()` on `MinorFamilySupportTransfer` and `MinorFamilyFundingAttempt` bypasses the event store — state changes are unrecorded, breaking audit trail and aggregate consistency | P1 |
| 5 | Mobile has zero surface for the guardian-approval workflow (HTTP 202 for high-value minor spends) and zero card management UI — features exist in backoffice but are unreachable from mobile | P1 |

---

## 2. Findings by Severity

### Severity Classification

- **P0 — Critical:** Data corruption / security breach / major financial misstatement. Fix immediately.
- **P1 — High:** Likely production incident or serious business risk. Fix before release.
- **P2 — Medium:** Important correctness/maintainability risk. Fix before high-volume production.
- **P3 — Low:** Cleanup / quality improvement. Schedule for tech debt sprint.

---

### P0 — Critical

---

#### MINOR-P0-001 — Cascade Delete Destroys Child Accounts Silently

**Domain:** Minor / Data
**Confidence:** High

**Description:**
`onDelete('cascade')` on `accounts.parent_account_id` means deleting a parent account silently destroys all associated minor accounts, their cards, spend approvals, points, and lifecycle history. No lifecycle transition is triggered, no event is sourced, and no guardian is notified. Financial transaction records referencing the deleted accounts become orphaned.

**Evidence:**
- `database/migrations/2026_04_24_002504_add_fk_constraints_to_minor_tables.php:14–17` — `->onDelete('cascade')` on `accounts.parent_account_id`
- `app/Domain/Account/Services/MinorAccountLifecycleService.php` — no observer or hook prevents the cascade

**Why it matters:**
A single erroneous parent account deletion wipes all associated minor accounts irreversibly. The event store has no record of the closures. Recovery requires a full database restore.

**Recommended fix:**
1. Change FK to `->onDelete('restrict')`
2. Add `Account::deleting()` observer that aborts if the account has active minor children
3. Require an explicit lifecycle transition (`status: closed`) before a parent account may be deleted

**Owner:** Backoffice

---

#### MINOR-P0-002 — Age Validation Not Enforced at Runtime

**Domain:** Minor / Security
**Confidence:** High

**Description:**
Age eligibility (6–17) is validated only at account creation (`MinorAccountController::store()`). There is no runtime enforcement on subsequent operations. A child who turns 18 continues to operate under minor-account restrictions until the scheduled `EvaluateMinorAccountLifecycleTransitions` command runs — which has no documented or verified cron schedule in `kernel.php`.

**Evidence:**
- `app/Http/Controllers/Api/MinorAccountController.php:64–75` — age check at creation only
- `app/Domain/Account/Services/MinorAccountLifecyclePolicy.php:72–100` — `evaluateAdultTransition()` is schedule-driven only
- `app/Console/Commands/EvaluateMinorAccountLifecycleTransitions.php` — not verifiably registered in `kernel.php`

**Why it matters:**
An 18-year-old can continue operating under minor spend limits and guardian visibility. Regulatory compliance for financial products serving minors typically requires real-time age enforcement.

**Recommended fix:**
1. Add `MinorAccountLifecyclePolicy::isEligibleForMinorOperations(Account $account): bool` using `date_of_birth`
2. Call as a guard on every sensitive minor-account operation (spending, card ops, transfers)
3. Register `EvaluateMinorAccountLifecycleTransitions` in `kernel.php` — recommend every 15 minutes

**Owner:** Backoffice

---

#### MINOR-P0-003 — Race Condition in Funding Attempt Deduplication

**Domain:** Minor / Data
**Confidence:** High

**Description:**
Funding attempt deduplication is an unlocked SELECT followed by a conditional INSERT. Two concurrent requests with the same dedupe hash both pass the guard and both create funding attempts, resulting in double-funding of the minor account.

**Evidence:**
- `app/Domain/Account/Services/MinorFamilyIntegrationService.php:532–538`:
```php
$existing = MinorFamilyFundingAttempt::query()
    ->where('dedupe_hash', $dedupeHash)
    ->first();
if ($existing !== null) {
    return $existing;
}
// no lock — concurrent request passes here too
```

**Why it matters:**
Under concurrent load (sponsor double-tap, network retry), a minor account receives duplicate funding. Direct financial loss.

**Recommended fix:**
Wrap in a transaction with a pessimistic lock:
```php
DB::transaction(function () use ($dedupeHash, ...) {
    $existing = MinorFamilyFundingAttempt::query()
        ->where('dedupe_hash', $dedupeHash)
        ->lockForUpdate()
        ->first();
    if ($existing !== null) return $existing;
    // proceed to create
});
```
Alternatively, add a unique database index on `dedupe_hash` and handle `UniqueConstraintViolationException`.

**Owner:** Backoffice

---

#### MINOR-P0-004 — Orphaned Compliance Records on Transition Deletion

**Domain:** Minor / Data
**Confidence:** High

**Description:**
`onDelete('setNull')` on `minor_account_lifecycle_exceptions.transition_id` nullifies the FK reference when a lifecycle transition is deleted. No application-level observer prevents this deletion. Lifecycle exceptions lose context of what triggered them, producing orphaned compliance records.

**Evidence:**
- `database/migrations/2026_04_24_002504_add_fk_constraints_to_minor_tables.php:51–54`
- No `MinorAccountLifecycleTransition::deleting()` observer found

**Why it matters:**
Lifecycle exceptions document compliance-critical events (KYC failures, age-boundary triggers, guardian continuity). An exception with a null `transition_id` is an unresolvable compliance record.

**Recommended fix:**
Add an observer on `MinorAccountLifecycleTransition::deleting()` that blocks deletion when any `MinorAccountLifecycleException` references it. Or implement soft deletes on transitions so historical references remain valid.

**Owner:** Backoffice

---

### P1 — High

---

#### MINOR-P1-001 — No SCA on Emergency Allowance Setting

**Domain:** Minor / Security
**Confidence:** High

**Description:**
`setEmergencyAllowance()` in `MinorAccountController` (lines 243–270) modifies the child's emergency spending cap without any SCA/MFA verification. A compromised guardian session can immediately raise the child's spending limit.

**Evidence:**
- `app/Http/Controllers/Api/MinorAccountController.php:243–270` — only `accountPolicy->updateMinor()` checked; no SCA call
- Compare: `MinorCardController::freeze()` requires SCA — inconsistent

**Recommended fix:**
Add `$this->scaVerificationService->verify($request)` before `save()`. Treat emergency allowance changes as a high-risk mutation.

**Owner:** Backoffice

---

#### MINOR-P1-002 — No SCA on Co-Guardian Invite Creation

**Domain:** Minor / Security
**Confidence:** High

**Description:**
Co-guardian invite creation (`CoGuardianController`) has no SCA requirement. A compromised guardian session can add an unauthorized co-guardian to a child account, granting full read access to the minor's financial data.

**Evidence:**
- `app/Http/Controllers/Api/CoGuardianController.php:25–48` — only `accountPolicy->updateMinor()` checked

**Recommended fix:**
Require SCA verification before issuing co-guardian invite codes. Log the action to the account audit trail.

**Owner:** Backoffice

---

#### MINOR-P1-003 — forceFill()->save() Bypasses Event Sourcing

**Domain:** Minor / Data
**Confidence:** High

**Description:**
`MinorFamilyIntegrationService` uses `forceFill()->save()` for state mutations on `MinorFamilySupportTransfer` and `MinorFamilyFundingAttempt`. These mutations bypass the Spatie event store — no events are dispatched, no audit trail is produced, and aggregate consistency is broken.

**Evidence:**
- `app/Domain/Account/Services/MinorFamilyIntegrationService.php` — `forceFill([...])->save()` at lines ~150, ~200, ~250 on transfer and funding attempt models

**Why it matters:**
The event store is the system of record. Silent mutations break aggregate consistency, event replay, and regulatory audit requirements.

**Recommended fix:**
Dispatch domain events before (or instead of) direct model saves. If these are projection-only models (not aggregates), document this explicitly and ensure audit logging via `AdminActionGovernance` or `ActivityLog`.

**Owner:** Backoffice

---

#### MINOR-P1-004 — Mass Assignment Unguarded on 10+ Models

**Domain:** Minor / Security
**Confidence:** High

**Description:**
10+ models use `$guarded = []`, equivalent to no mass-assignment protection. Any `fill()` or `create()` call with user-supplied data can overwrite `status`, `decided_at`, `amount`, and other financial/audit fields.

**Evidence:**
- `app/Domain/Account/Models/Account.php:148` — `public $guarded = []`
- `app/Domain/Account/Models/MinorSpendApproval.php:34` — `public $guarded = []`
- `app/Domain/Account/Models/MinorPointsLedger.php:29` — `protected $guarded = []`
- `app/Domain/Account/Models/MinorFamilyReconciliationException.php` — `protected $guarded = []`
- `app/Domain/Account/Models/GuardianInvite.php` — `protected $guarded = []`
- Plus 5+ additional models

**Recommended fix:**
Global sweep: replace `$guarded = []` with explicit `$fillable = [...]` arrays on every model listing only safe attributes.

**Owner:** Backoffice

---

#### MINOR-P1-005 — updatePermissionLevel() Lacks Idempotency — Double Points on Retry

**Domain:** Minor / Data
**Confidence:** High

**Description:**
`updatePermissionLevel()` in `MinorAccountController` (lines 151–235) does not enforce idempotency keys. Network retries execute the endpoint twice, awarding minor account points twice.

**Evidence:**
- `app/Http/Controllers/Api/MinorAccountController.php:151–235` — no `Idempotency-Key` header checked
- `app/Http/Controllers/Api/MinorAccountController.php:213–222` — points awarded on every call

**Recommended fix:**
Require a client-provided `Idempotency-Key` header. Store a `(account_uuid, idempotency_key)` record with a unique index to detect and short-circuit replays.

**Owner:** Backoffice

---

#### MINOR-P1-006 — Race Condition in Tier Advance (No Pessimistic Lock)

**Domain:** Minor / Data
**Confidence:** High

**Description:**
`MinorAccountLifecycleService::executeTierAdvance()` evaluates the account's current tier, then persists the transition without a pessimistic lock. A concurrent operation modifying the account between evaluation and persistence can produce double-advances or corrupted tier state.

**Evidence:**
- `app/Domain/Account/Services/MinorAccountLifecycleService.php:281–306` — no `lockForUpdate()` during evaluation-to-persistence

**Recommended fix:**
Wrap the evaluate+persist sequence in `DB::transaction()` with `Account::query()->where('uuid', $uuid)->lockForUpdate()->first()`.

**Owner:** Backoffice

---

#### MINOR-P1-007 — Guardian Approval Workflow and Card Management Missing on Mobile

**Domain:** Cross-Repo / Mobile
**Confidence:** High

**Description:**
The mobile app has no handling for the HTTP 202 "approval required" response that the backoffice returns when a minor's send-money request exceeds the guardian-configured threshold. Guardians cannot approve high-value transfers from mobile. There is also zero mobile UI for card request/approval/freeze workflows despite full backoffice support.

**Evidence:**
- `app/Http/Controllers/API/Compatibility/SendMoney/SendMoneyStoreController.php:174–289` — returns HTTP 202 + `MinorSpendApproval` record above threshold
- `app/Http/Controllers/Api/Account/MinorCardController.php:56–121` — full card lifecycle (request, approve, deny, freeze)
- Mobile `src/features/send-money/api/useSendMoney.ts:69–87` — no HTTP 202 branch
- No mobile hooks, screens, or modals for approval workflow or card management found

**Why it matters:**
Guardians cannot perform their compliance role (approving/denying high-value child spends) from mobile. This is a complete workflow gap.

**Recommended fix:**
1. Add HTTP 202 detection in `useSendMoney.ts`
2. Implement pending spend approvals list screen (guardian view)
3. Implement approve/deny action with confirmation
4. Implement card request, card approval/denial, and card freeze/unfreeze screens

**Owner:** Mobile

---

#### MINOR-P1-008 — Public Funding Link Tokens Not Cryptographically Hashed

**Domain:** Minor / Security
**Confidence:** Medium

**Description:**
Public funding link tokens are validated only for minimum length (≥ 32 chars). There is no verification of cryptographic entropy and tokens appear to be stored in plaintext. If tokens are weakly or sequentially generated, enumeration is possible.

**Evidence:**
- `app/Http/Controllers/Api/PublicMinorFundingLinkController.php:24` — `if (strlen($token) < 32)` only
- No token hashing found in funding link creation path

**Recommended fix:**
1. Generate tokens using `Str::random(64)` (cryptographically secure)
2. Store only `hash('sha256', $token)` in the database
3. Compare hashed values at lookup time

**Owner:** Backoffice

---

#### REVENUE-P1-001 — SMS Pricing Uses Float ceil() Instead of bcmath

**Domain:** Revenue
**Confidence:** High

**Description:**
`SmsPricingService` uses `ceil()` on a float multiplication result to convert USD to atomic USDC units. This causes systematic upward rounding on every SMS pricing calculation. The correct approach (`bcmul()`) is already used by `X402PricingService` — two incompatible rounding strategies coexist in the same codebase.

**Evidence:**
- `app/Domain/SMS/Services/SmsPricingService.php:31–34`:
```php
$atomicUsdc = (string) max(1000, (int) ceil($totalUsd * 1_000_000));
```
- `app/Domain/X402/Services/X402PricingService.php:81–85` — correctly uses `bcmul($usdPrice, '1000000', 0)`

**Recommended fix:**
Replace with `bcmul((string) $totalUsd, '1000000', 0)` to match the X402 approach. Add unit tests for rounding edge cases (sub-cent amounts, rate boundary values).

**Owner:** Backoffice

---

### P2 — Medium

---

#### MINOR-P2-001 — Spend Approval Expiry Races with Guardian Approval

**Domain:** Minor / Data
**Confidence:** Medium

**Description:**
`ExpireMinorSpendApprovals` command runs without pessimistic locking. A guardian approval racing with the expiry command can result in an approval being simultaneously `cancelled` (by command) and `approved` (by guardian).

**Evidence:**
- `app/Console/Commands/ExpireMinorSpendApprovals.php` — simple UPDATE without lock
- `MinorSpendApprovalController::approve()` — no `lockForUpdate()` found

**Recommended fix:**
Wrap both the expiry update and the approval action in `DB::transaction()` with `lockForUpdate()` on the spend approval record.

**Owner:** Backoffice

---

#### MINOR-P2-002 — 12+ Business-Critical Values Hardcoded in PHP

**Domain:** Minor / Config
**Confidence:** High

**Description:**
Business-critical thresholds are hardcoded in PHP and cannot be adjusted without a code deployment.

**Evidence:**

| Value | Location |
|-------|----------|
| Age range 6–17 | `app/Http/Controllers/Api/MinorAccountController.php:68` |
| Tier boundary age 13 | `app/Http/Controllers/Api/MinorAccountController.php:78` |
| Permission levels 1–6 | `app/Http/Controllers/Api/MinorAccountController.php:281–290` |
| Spending limits 50K–1.5M | `app/Rules/ValidateMinorAccountPermission.php:89–97` |
| Blocked merchant categories | `app/Rules/ValidateMinorAccountPermission.php:14–19` |
| Emergency allowance max 100,000 | `app/Http/Controllers/Api/MinorAccountController.php:252` |
| Card limit multiplier (30 days) | `app/Domain/Account/Models/MinorCardLimit.php:70–73` |

**Recommended fix:**
Consolidate into `config/minor_family.php` with `env()` overrides. Anti-abuse thresholds already in config demonstrate the correct pattern.

**Owner:** Backoffice

---

#### MINOR-P2-003 — No Formal State Machine on Lifecycle Transitions

**Domain:** Minor / Data
**Confidence:** High

**Description:**
Lifecycle state transitions have no formal validation. A `BLOCKED` transition can become `COMPLETED`, or a `COMPLETED` transition can regress to `PENDING`. No database constraint or model-level guard prevents invalid paths.

**Evidence:**
- `app/Domain/Account/Models/MinorAccountLifecycleTransition.php` — no state machine enforcement
- `app/Domain/Account/Services/MinorAccountLifecycleService.php` — `executeTransition()` does not validate `state_previous`

**Recommended fix:**
Define a valid transition map: `PENDING → COMPLETED`, `PENDING → BLOCKED`. Enforce in a model `saving()` observer or the service layer.

**Owner:** Backoffice

---

#### MINOR-P2-004 — Mobile Feature Gates Hard-Coded, No Server-Side Override

**Domain:** Cross-Repo / Mobile
**Confidence:** High

**Description:**
Mobile minor-account feature gates are entirely client-side booleans. Features enabled/disabled in the backoffice are invisible to mobile without an app rebuild. `pendingChoreSubmissions` is gated off but the implementation code exists and is callable.

**Evidence:**
- `src/features/minor-accounts/domain/featureGates.ts` — hard-coded booleans (all false except `parentMode: true`)
- No remote config or feature flag endpoint found in mobile

**Recommended fix:**
Implement a `GET /api/feature-flags` endpoint that returns the current state per account type. Mobile consumes this at session start and caches it with a short TTL.

**Owner:** Mobile + Backoffice

---

#### MINOR-P2-005 — Mobile Never Displays Account Lifecycle State

**Domain:** Cross-Repo / Mobile
**Confidence:** High

**Description:**
Mobile account detail screen shows `account_type` but never queries or displays `lifecycle_status`. Operations on suspended or closed accounts are blocked at the API but the mobile UI provides no feedback.

**Evidence:**
- `app/Domain/Account/Models/MinorAccountLifecycleTransition.php` — states: `created`, `active`, `suspended`, `closed`
- Mobile account detail screen — `account_type` only; no lifecycle state field

**Recommended fix:**
Include `lifecycle_status` in the account detail API response. Mobile should block operations and display contextual messaging for `suspended` and `closed` states.

**Owner:** Mobile + Backoffice

---

#### MINOR-P2-006 — Card Limit Multiplier Uses Hardcoded 30 Days (February Incorrect)

**Domain:** Minor / Data
**Confidence:** High

**Description:**
`MinorCardLimit::validateHierarchy()` uses `$this->single_transaction_limit * 30` to check against the monthly limit. For February (28/29 days) this check is incorrect and may allow limits that exceed what the month permits.

**Evidence:**
- `app/Domain/Account/Models/MinorCardLimit.php:70–73`

**Recommended fix:**
Replace `30` with `now()->daysInMonth` or `config('minor.monthly_days', 30)`.

**Owner:** Backoffice

---

#### REVENUE-P2-001 — Revenue Dashboard Uses Server Timezone (No Tenant Timezone Support)

**Domain:** Revenue / Data
**Confidence:** High

**Description:**
All analytics date calculations use server timezone via `Carbon::now()`, `startOfDay()`, `endOfDay()`. MTD and custom date range calculations are misaligned for tenants in non-UTC timezones.

**Evidence:**
- `app/Domain/Analytics/Services/WalletRevenueActivityMetrics.php:79–80`
- `app/Filament/Admin/Pages/RevenuePerformanceOverview.php:131`
- No `tenant('timezone')` config usage found in Analytics domain

**Recommended fix:**
Add tenant timezone configuration. Wrap all `Carbon::now()` calls in analytics with `Carbon::now(tenant('timezone') ?? 'UTC')`. Convert date range boundaries before database queries.

**Owner:** Backoffice

---

#### REVENUE-P2-002 — Revenue Target Deletions Not Audited

**Domain:** Revenue / Data
**Confidence:** High

**Description:**
Create and update operations on revenue targets are audited via `RevenueTargetAudit::recordSaved()`. The bulk delete action performs a hard delete with no audit hook. Finance teams cannot track who deleted targets or when.

**Evidence:**
- `app/Filament/Admin/Resources/RevenueTargetResource/Pages/ListRevenueTargets.php` — bulk delete action
- `app/Filament/Admin/Support/RevenueTargetAudit.php` — only `recordSaved()` method; no `recordDeleted()`

**Recommended fix:**
Override the delete action to call `RevenueTargetAudit::recordDeleted($target, $actor)` before destruction. Add soft deletes to `revenue_targets` to preserve history.

**Owner:** Backoffice

---

#### SECURITY-P2-001 — 26 PHPStan Baseline Files Suppressing Level 8 Violations

**Domain:** Security / Testing
**Confidence:** High

**Description:**
26 separate PHPStan baseline files suppress an unknown number of type errors at the configured Level 8. Type errors in financial and security-sensitive code mask real bugs.

**Evidence:**
- `phpstan.neon` — `includes:` list contains `phpstan-baseline.neon`, `phpstan-baseline-level6.neon`, `phpstan-baseline-level7.neon`, `phpstan-baseline-level8.neon`, and 22 additional baseline files

**Recommended fix:**
Establish a baseline reduction roadmap targeting zero baselines in 90 days. Prioritise eliminating baselines in the Minor and Revenue domains first.

**Owner:** Backoffice

---

### P3 — Low

---

#### MINOR-P3-001 — Inconsistent Authorization Patterns Across Minor Controllers

**Domain:** Minor / Architecture
**Confidence:** High

**Description:**
Some controllers use `AccountPolicy` via `abort_unless()`, others use ad-hoc service method calls or bare `abort(403)`. There are no policies for `Chore`, `Reward`, or `Card` operations — access is checked via service calls, making authorization harder to audit.

**Evidence:**
- `app/Http/Controllers/Api/MinorAccountLifecycleController.php:139–150` — bare `abort(403)`
- Compare with `MinorAccountController.php` which correctly uses Policy pattern

**Recommended fix:**
Standardise on `$this->authorize()` / `abort_unless(policy->method())`. Create `ChorePolicy`, `RewardPolicy`, `CardPolicy`.

**Owner:** Backoffice

---

#### MINOR-P3-002 — Chore Submission Errors Swallowed Silently on Mobile

**Domain:** Cross-Repo / Mobile
**Confidence:** High

**Description:**
Mobile chore submission hooks throw on `!response.data.success` but do not capture or display the `errors` map from the API response. Users see a generic failure with no actionable detail.

**Evidence:**
- `src/features/minor-accounts/hooks/useChoreSubmissions.ts:11–24` — silent throw
- `app/Http/Controllers/Api/MinorChoreController.php:251` — returns detailed `errors` map

**Recommended fix:**
Capture `response.data.errors` in the hook and surface it via toast or inline error display.

**Owner:** Mobile

---

#### REVENUE-P3-001 — Cache::flush() Clears Entire Cache on Fee Save

**Domain:** Revenue / Performance
**Confidence:** High

**Description:**
`RevenuePricingPage` calls `Cache::flush()` when fee settings are saved, clearing the entire application cache rather than only pricing-related keys.

**Evidence:**
- `app/Filament/Admin/Pages/RevenuePricingPage.php:214`

**Recommended fix:**
Use `Cache::tags(['revenue', 'pricing'])->flush()`. Redis cache driver is already in use and supports tagging.

**Owner:** Backoffice

---

#### REVENUE-P3-002 — Revenue Target Form Allows Mismatched Stream/Currency

**Domain:** Revenue / Data
**Confidence:** High

**Description:**
The revenue target form allows setting a ZAR target for a USDC stream or vice versa. No cross-field validation exists between `stream_code` and `currency`.

**Evidence:**
- `app/Filament/Admin/Resources/RevenueTargetResource.php` — form schema has no `afterStateUpdated` cross-validation

**Recommended fix:**
Add a custom form rule that validates the currency is appropriate for the selected stream code.

**Owner:** Backoffice

---

#### MINOR-P3-003 — date_of_birth PII May Be Serialized in API Responses

**Domain:** Minor / Security
**Confidence:** Medium

**Description:**
`date_of_birth` is not declared in `$hidden` on the `UserProfile` model. If the model is serialized in an API response, the minor's date of birth (PII) is exposed.

**Evidence:**
- `app/Http/Controllers/Api/MinorAccountController.php:96–105` — `UserProfile` includes `date_of_birth`
- No `$hidden` declaration found for this field on `UserProfile`

**Recommended fix:**
Add `protected $hidden = ['date_of_birth']` to `UserProfile`, or use explicit `->select()` in all queries that return user profiles to mobile clients.

**Owner:** Backoffice

---

#### MINOR-P3-004 — No Audit Log on Guardian Account for Permission Level Changes

**Domain:** Minor / Compliance
**Confidence:** High

**Description:**
When a guardian changes a minor's permission level, the audit log is written only to the minor account context. There is no corresponding entry on the guardian's account, making dual-sided compliance reporting incomplete.

**Evidence:**
- `app/Http/Controllers/Api/MinorAccountController.php:198–208` — logs to minor `AccountAuditLog` only

**Recommended fix:**
Also write an audit entry to the guardian account with the same action metadata.

**Owner:** Backoffice

---

## 3. End-to-End Flow Verification Matrix

| Flow | Mobile Touchpoint | Backoffice Touchpoint | Status |
|------|------------------|-----------------------|--------|
| Minor account creation | `CreateMinorAccountScreen` | `MinorAccountController::store()` | ⚠️ Partial — age validation at creation only |
| Guardian authorization | `useAccountContext` (isGuardian) | `MinorAccountAccessService::hasGuardianAccess()` | ✅ Pass |
| Send money (under limit) | `useSendMoney.ts` | `SendMoneyStoreController` + `ValidateMinorAccountPermission` | ✅ Pass |
| Send money (over limit → approval) | `useSendMoney.ts` | Returns HTTP 202 + `MinorSpendApproval` | ❌ Fail — mobile has no 202 handler |
| Guardian approves spend | Missing on mobile | `MinorSpendApprovalController` | ❌ Fail — no mobile surface |
| Card request | Missing on mobile | `MinorCardController::createRequest()` | ❌ Fail — no mobile surface |
| Card approval (guardian) | Missing on mobile | `MinorCardController::approveRequest()` | ❌ Fail — no mobile surface |
| Card freeze | Missing on mobile | `MinorCardController::freeze()` (SCA-gated) | ❌ Fail — no mobile surface |
| Emergency allowance set | `MinorAccountEditScreen` | `MinorAccountController::setEmergencyAllowance()` | ⚠️ Partial — no SCA on backoffice |
| Co-guardian invite | Guardian settings screen | `CoGuardianController::store()` | ⚠️ Partial — no SCA |
| Family funding (public link) | External sponsor | `PublicMinorFundingLinkController` | ⚠️ Partial — token not hashed; dedupe race condition |
| Chore submission (child) | `useChoreSubmissions` | `MinorChoreController::submitCompletion()` | ⚠️ Partial — errors not surfaced to user |
| Chore approval (guardian) | `useApproveChore` | `MinorChoreController::approveCompletion()` | ✅ Pass |
| Minor lifecycle tier advance | Not exposed | `MinorAccountLifecycleService::executeTierAdvance()` | ⚠️ Partial — race condition |
| Age-18 transition | Not exposed | `EvaluateMinorAccountLifecycleTransitions` | ⚠️ Partial — schedule unverified; not real-time |
| Parent account deletion | Account close flow | FK cascade | ❌ Fail — destroys child accounts silently |
| Revenue target CRUD | N/A | `RevenueTargetResource` | ✅ Pass (deletion audit gap: P2) |
| Revenue dashboard | N/A | `RevenuePerformanceOverview` + metrics service | ✅ Pass (timezone caveat) |
| Revenue anomaly scan | N/A | `revenue:scan-anomalies` command | ✅ Pass (narrow scope) |
| Profit/margin dashboard | N/A | `RevenueProfitabilityPage` (null port) | ✅ Pass — correctly deferred |
| Fee pricing governance | N/A | `RevenuePricingPage` + audit trail | ✅ Pass |

**Legend:** ✅ Pass | ⚠️ Partial (risk present) | ❌ Fail (gap or confirmed bug)

---

## 4. Test Coverage Assessment

### What Exists

- 50+ test files for Minor domain (unit, feature, integration, schema)
- `MinorAccountIntegrationTest.php` (152 lines) — basic creation
- `MinorAccountPhase2IntegrationTest.php` (409 lines) — spend approval workflow
- `MinorAccountPhase4IntegrationTest.php` (340 lines) — rewards
- `MinorFamilyFundingPolicyTest.php`, `MinorCardLimitTest.php` — unit tests
- `WalletRevenueActivityMetricsTest.php` — caching, windowing, stub mode
- `RevenueAnomalyScanCommandTest.php` — anomaly detection basics
- `RevenueTargetResourceTest.php` — access control
- `RevenueProfitabilityAndUnitEconomicsPageTest.php` — null/stub states
- `X402PricingServiceTest.php` — bcmath precision

### High-Priority Missing Tests

| Missing Test | Related Finding |
|--------------|-----------------|
| Age-18 runtime enforcement — can an 18-year-old still use minor features? | MINOR-P0-002 |
| Concurrent funding attempt creation with same dedupe hash | MINOR-P0-003 |
| Parent account deletion cascades to child accounts | MINOR-P0-001 |
| `setEmergencyAllowance()` without SCA should be rejected | MINOR-P1-001 |
| `updatePermissionLevel()` called twice — second call is idempotent, no double-points | MINOR-P1-005 |
| Tier advance with concurrent account modification | MINOR-P1-006 |
| `forceFill()->save()` paths — verify events are / are not emitted | MINOR-P1-003 |
| `MinorCardController` — concurrent approval for same card request | MINOR-P1-006 |
| Lifecycle invalid transitions (BLOCKED→COMPLETED, COMPLETED→PENDING) | MINOR-P2-003 |
| Revenue target deletion — verify audit record created | REVENUE-P2-002 |
| SMS pricing rounding — sub-cent amounts, float edge cases | REVENUE-P1-001 |
| Date window reversal and DST edge cases in metrics service | REVENUE-P2-001 |
| Revenue target with mismatched stream/currency combination | REVENUE-P3-002 |
| Mobile: HTTP 202 response from send-money triggers approval UI | MINOR-P1-007 |

---

## 5. Release Gate Checklist

### Must Fix Before Release

- [ ] **MINOR-P0-001** — Change `parent_account_id` FK to `onDelete('restrict')` + add deletion guard
- [ ] **MINOR-P0-002** — Add runtime age enforcement + register lifecycle eval job in `kernel.php`
- [ ] **MINOR-P0-003** — Add `lockForUpdate()` or unique index to funding attempt dedupe check
- [ ] **MINOR-P0-004** — Add observer blocking lifecycle transition deletion when exceptions reference it
- [ ] **MINOR-P1-001** — Add SCA to `setEmergencyAllowance()`
- [ ] **MINOR-P1-002** — Add SCA to co-guardian invite creation
- [ ] **MINOR-P1-003** — Audit all `forceFill()->save()` in `MinorFamilyIntegrationService`
- [ ] **MINOR-P1-004** — Replace `$guarded = []` with explicit `$fillable` on all models
- [ ] **MINOR-P1-005** — Add idempotency key enforcement to `updatePermissionLevel()`
- [ ] **MINOR-P1-006** — Add pessimistic lock to tier advance evaluation
- [ ] **MINOR-P1-007** — Implement mobile guardian approval workflow + HTTP 202 handling
- [ ] **MINOR-P1-008** — Cryptographically generate + hash public funding link tokens
- [ ] **REVENUE-P1-001** — Replace float `ceil()` with `bcmul()` in SMS pricing

### Safe to Defer (Post-Launch)

- [ ] **MINOR-P2-001** — Pessimistic lock on spend approval expiry
- [ ] **MINOR-P2-002** — Externalize hardcoded limits to config
- [ ] **MINOR-P2-003** — Formal state machine transition validation
- [ ] **MINOR-P2-004** — Server-side feature flags for mobile
- [ ] **MINOR-P2-005** — Mobile lifecycle state display
- [ ] **MINOR-P2-006** — February-aware card limit multiplier
- [ ] **REVENUE-P2-001** — Tenant timezone configuration
- [ ] **REVENUE-P2-002** — Revenue target deletion audit trail + soft deletes
- [ ] **SECURITY-P2-001** — PHPStan baseline reduction roadmap
- [ ] All P3 items

### Post-Release Monitoring

- Monitor `MinorFamilyFundingAttempt` for duplicate `dedupe_hash` records
- Alert on any `minor_account_lifecycle_exceptions` with `transition_id IS NULL`
- Monitor `ExpireMinorSpendApprovals` — ensure no timing conflict with guardian approval
- Monitor `EvaluateMinorAccountLifecycleTransitions` cadence — alert if last run > 30 minutes ago
- Monitor SMS pricing amounts for systematic ceiling rounding at boundary values
- Run `revenue:scan-anomalies --notify` on a daily cron; verify Filament notifications delivered

---

## 6. Prioritized Remediation Plan

### 24-Hour Actions (P0 blockers)

1. **Change `parent_account_id` FK to `onDelete('restrict')`** + write migration + add `Account::deleting()` observer *(MINOR-P0-001)*
2. **Add `lockForUpdate()` to funding attempt dedupe check** or add unique index on `dedupe_hash` *(MINOR-P0-003)*
3. **Add observer to `MinorAccountLifecycleTransition`** preventing deletion when exceptions reference it *(MINOR-P0-004)*
4. **Register `EvaluateMinorAccountLifecycleTransitions` in `kernel.php`** — every 15 minutes *(MINOR-P0-002 partial)*

### 7-Day Actions (P1 — before production traffic on minor features)

5. **Add runtime age gate** to all minor-account sensitive operations via shared policy method *(MINOR-P0-002 complete)*
6. **Add SCA to `setEmergencyAllowance()` and `CoGuardianController::store()`** *(MINOR-P1-001, MINOR-P1-002)*
7. **Audit and remediate `forceFill()->save()` patterns** in `MinorFamilyIntegrationService` *(MINOR-P1-003)*
8. **Global sweep: replace `$guarded = []` with `$fillable`** on all models *(MINOR-P1-004)*
9. **Add idempotency key to `updatePermissionLevel()`** *(MINOR-P1-005)*
10. **Add pessimistic lock to `executeTierAdvance()`** *(MINOR-P1-006)*
11. **Fix SMS pricing: replace `ceil()` with `bcmul()`** *(REVENUE-P1-001)*
12. **Cryptographic token generation + hashing for public funding links** *(MINOR-P1-008)*
13. **Mobile: implement HTTP 202 handling and guardian approval + card management screens** *(MINOR-P1-007)*

### 30-Day Hardening

14. **Config externalization** — move all 12+ hardcoded values to `config/minor_family.php` *(MINOR-P2-002)*
15. **Formal lifecycle state machine** with valid transition map enforcement *(MINOR-P2-003)*
16. **Tenant timezone support** in analytics domain *(REVENUE-P2-001)*
17. **Revenue target deletion audit trail** + soft deletes *(REVENUE-P2-002)*
18. **PHPStan baseline reduction plan** — eliminate baselines in Minor and Revenue domains first *(SECURITY-P2-001)*
19. **Server-side feature flags** for mobile minor account features *(MINOR-P2-004)*
20. **Test suite expansion** — all missing tests from Section 4 *(Coverage gaps)*
21. **`ChorePolicy`, `RewardPolicy`, `CardPolicy`** — replace ad-hoc auth with Laravel Gate/Policy *(MINOR-P3-001)*
22. **`date_of_birth` PII** — declare `$hidden` or use explicit `select()` in API-facing queries *(MINOR-P3-003)*

---

## 7. Finding Index

| ID | Severity | Domain | Title |
|----|----------|--------|-------|
| MINOR-P0-001 | P0 | Minor / Data | Cascade Delete Destroys Child Accounts Silently |
| MINOR-P0-002 | P0 | Minor / Security | Age Validation Not Enforced at Runtime |
| MINOR-P0-003 | P0 | Minor / Data | Race Condition in Funding Attempt Deduplication |
| MINOR-P0-004 | P0 | Minor / Data | Orphaned Compliance Records on Transition Deletion |
| MINOR-P1-001 | P1 | Minor / Security | No SCA on Emergency Allowance Setting |
| MINOR-P1-002 | P1 | Minor / Security | No SCA on Co-Guardian Invite Creation |
| MINOR-P1-003 | P1 | Minor / Data | forceFill()->save() Bypasses Event Sourcing |
| MINOR-P1-004 | P1 | Minor / Security | Mass Assignment Unguarded on 10+ Models |
| MINOR-P1-005 | P1 | Minor / Data | updatePermissionLevel() Lacks Idempotency |
| MINOR-P1-006 | P1 | Minor / Data | Race Condition in Tier Advance |
| MINOR-P1-007 | P1 | Cross-Repo | Guardian Approval Workflow Missing on Mobile |
| MINOR-P1-008 | P1 | Minor / Security | Public Funding Link Tokens Not Hashed |
| REVENUE-P1-001 | P1 | Revenue | SMS Pricing Uses Float ceil() Instead of bcmath |
| MINOR-P2-001 | P2 | Minor / Data | Spend Approval Expiry Races with Guardian Approval |
| MINOR-P2-002 | P2 | Minor / Config | 12+ Business-Critical Values Hardcoded |
| MINOR-P2-003 | P2 | Minor / Data | No Formal State Machine on Lifecycle Transitions |
| MINOR-P2-004 | P2 | Cross-Repo | Mobile Feature Gates Hard-Coded |
| MINOR-P2-005 | P2 | Cross-Repo | Mobile Never Displays Account Lifecycle State |
| MINOR-P2-006 | P2 | Minor / Data | Card Limit Multiplier Uses Hardcoded 30 Days |
| REVENUE-P2-001 | P2 | Revenue / Data | Dashboard Uses Server Timezone Only |
| REVENUE-P2-002 | P2 | Revenue / Data | Revenue Target Deletions Not Audited |
| SECURITY-P2-001 | P2 | Security / Testing | 26 PHPStan Baseline Files Suppressing Level 8 |
| MINOR-P3-001 | P3 | Minor / Architecture | Inconsistent Authorization Patterns |
| MINOR-P3-002 | P3 | Cross-Repo | Chore Submission Errors Swallowed on Mobile |
| MINOR-P3-003 | P3 | Minor / Security | date_of_birth PII May Be Serialized in API Responses |
| MINOR-P3-004 | P3 | Minor / Compliance | No Audit Log on Guardian Account for Permission Changes |
| REVENUE-P3-001 | P3 | Revenue / Performance | Cache::flush() Clears Entire Cache on Fee Save |
| REVENUE-P3-002 | P3 | Revenue / Data | Revenue Target Form Allows Mismatched Stream/Currency |

**Total:** 4× P0 · 9× P1 · 9× P2 · 6× P3 = **28 findings**
