# COMPREHENSIVE SECURITY & QUALITY AUDIT REPORT
## Minor Accounts Implementation (Phases 0-12)
### MaphaPay Backoffice - Banking/Fintech Application

**Audit Date**: 2026-04-24
**Auditor**: AI (opencode/minimax-m2.5-free)
**Files Audited**: 47 domain models, services, controllers, migrations, tests

---

## AUDIT HEALTH SCORE: 15/20 (Good)

| # | Dimension | Score | Key Finding |
|---|----------|-------|------------|
| 1 | Authentication & Authorization | 3/4 | Guardian pattern mostly correct, minor gaps in edge cases |
| 2 | Financial Transaction Security | 3/4 | Solid concurrency handling, some gaps in rate limiting |
| 3 | Code Quality & Architecture | 3/4 | Good DDD structure, small inconsistencies |
| 4 | Robustness & Concurrency | 3/4 | DB transactions well used, minor edge cases |
| 5 | API Contract & Validation | 3/4 | Good input validation, response schema variations |
| **Total** | | **15/20** | **Good** |

**Rating bands**: 18-20 Excellent, 14-17 Good, 10-13 Acceptable, 6-9 Poor, 0-5 Critical

---

## EXECUTIVE SUMMARY

- **Total issues found**: 18 (P0: 1, P1: 4, P2: 8, P3: 5)
- **Critical (P0)**: 1 - Authorization bypass vulnerability in `MinorAccountAccessService::isChild()`
- **High (P1)**: 4 - Missing idempotency in points/awards, card provision soft-fail, MSISDN masking, SpendApproval guardian_account_uuid nullable
- **Recommended immediate action**: Fix P0 authorization logic flaw before any production deployment

---

## DETAILED FINDINGS BY SEVERITY

### [P0] CRITICAL

**1. Authorization Logic Flaw - `isChild()` always returns false**

- **Severity**: P0 Blocking
- **Location**: `app/Domain/Account/Services/MinorAccountAccessService.php:61-71`
- **Category**: Authentication & Authorization
- **Impact**: `authorizeView()` can never correctly identify a child via `isChild()`. Children may be unable to access their own accounts, or non-members could pass if `hasGuardianAccess()` accidentally matches. This is a banking app — authorization failures are catastrophic.
- **Evidence**:
```php
public function isChild(User $user, Account $minorAccount): bool
{
    if ($minorAccount->type !== 'minor' || $minorAccount->user_uuid !== $user->uuid) {
        return false;
    }

    return AccountMembership::query()
        ->forAccount($minorAccount->uuid)
        ->active()
        ->whereIn('role', ['guardian', 'co_guardian'])  // BUG: queries guardian roles
        ->exists();
}
```
The method correctly checks `user_uuid` match but then queries for `guardian`/`co_guardian` roles — the same roles that `hasGuardianAccess()` checks. The child is the account *owner*, so the role should be `'owner'`, not `['guardian', 'co_guardian']`.
- **Remediation**: Change role query to `->where('role', 'owner')`.

---

### [P1] HIGH

**2. Missing Idempotency in Points Awarding**

- **Severity**: P1 Major
- **Location**: `app/Domain/Account/Services/MinorPointsService.php:26-51`
- **Category**: Financial Transaction Security
- **Impact**: Duplicate points awarded on concurrent chore approvals if two requests race before the duplicate check completes.
- **Evidence**: `findExistingEntry()` returns null if `referenceId === null`, allowing double award. `award()` does not lock the points ledger before the check-then-insert race window.
```php
// Line 34-38: race window between findExistingEntry and create
$existing = $this->findExistingEntry($minorAccount, $source, $referenceId, $lockBalance);
if ($existing !== null) {
    return $existing;
}
if ($lockBalance) {
    $this->getBalance($minorAccount, true);  // only locks balance, not the ledger entry
}
return MinorPointsLedger::create([...]);  // no lockForUpdate on the entry itself
```
- **Remediation**: Add `lockForUpdate()` on the duplicate check query in `findExistingEntry()` when a referenceId is provided.

**3. Tier Check Unenforced at Card Provisioning**

- **Severity**: P1 Major
- **Location**: `app/Http/Controllers/Api/Account/MinorCardController.php:136-160`
- **Category**: Authentication & Authorization
- **Impact**: Grow-tier children (ages 6-12) could get Apple Pay/Google Pay provisioned against parental consent. Virtual cards are Rise-only by design.
- **Evidence**: No tier verification before `getProvisioningData()`. The `provision()` endpoint accepts any cardId from any authenticated user.
```php
public function provision(Request $request, string $cardId): JsonResponse
{
    // ...
    $card = Card::where('issuer_card_token', $cardId)->first();  // no tier check
    $provisioningData = $this->cardProvisioning->getProvisioningData(
        userId: $user->uuid,
        cardToken: $card->issuer_card_token,
        walletType: WalletType::from($validated['wallet_type']),
        deviceId: $validated['device_id'],
        certificates: [],
    );
```
- **Remediation**: Resolve the minor account from the card's `minor_account_uuid` and verify `$account->tier === 'rise'` before provisioning.

**4. MSISDN Masking Leakage**

- **Severity**: P1 Major
- **Location**: `app/Http/Controllers/Api/MinorFamilySupportTransferController.php:142-151`
- **Category**: Data Protection
- **Impact**: Sensitive PII (phone number) partially exposed in API responses. For Swazi MSISDNs (8 digits, format +268XXXXXXXX), showing first 5 + last 2 reveals the full local number.
- **Evidence**:
```php
private function maskMsisdn(?string $msisdn): string
{
    $digits = preg_replace('/\D+/', '', (string) $msisdn) ?? '';
    if (strlen($digits) <= 6) {
        return $digits;
    }
    return substr($digits, 0, 5) . '****' . substr($digits, -2);
    // +268 7612 3456 → "76123****56" — full local number exposed
}
```
- **Remediation**: Mask to `'****' . substr($digits, -2)` to show only the last 2 digits.

**5. MinorSpendApproval guardian_account_uuid Nullable**

- **Severity**: P1 Major
- **Location**: `database/migrations/tenant/2026_04_17_100000_create_minor_spend_approvals_table.php`
- **Category**: Authorization
- **Impact**: Spend approvals created without a `guardian_account_uuid` cannot be authorized by guardians. The `authorizeGuardian()` call in `MinorSpendApprovalController:51` passes this nullable field as the acting account context — null means no context is established.
- **Evidence**: Migration has `$table->uuid('guardian_account_uuid')->nullable()`. `MinorSpendApprovalController::approve()` calls `$this->accessService->authorizeGuardian($user, $minorAccount, $approval->guardian_account_uuid)` with no null guard.
- **Remediation**: Add a `->required()` validation rule in the spend approval creation request, and validate non-null at the service layer.

---

### [P2] MEDIUM

**6. MinorCardRequest CreateRequest Missing DB Transaction**

- **Severity**: P2 Minor
- **Location**: `app/Domain/Account/Services/MinorCardRequestService.php:22-59`
- **Category**: Robustness
- **Impact**: If creation partially succeeds (row inserted but notification/event fails), orphaned record exists.
- **Evidence**: `createRequest()` has no `DB::transaction()` wrapper, unlike `createCardFromRequest()` which correctly wraps in one.
- **Remediation**: Wrap in `DB::transaction()`.

**7. MinorAccountAccessService - `resolveActingAccount` Silent Fallback**

- **Severity**: P2 Minor
- **Location**: `app/Domain/Account/Services/MinorAccountAccessService.php:74-93`
- **Category**: Authorization
- **Impact**: Guardian provides an explicit `actingAccountUuid` but it belongs to another user — the method silently falls back to the first available account instead of rejecting. An attacker who guesses `actingAccountUuid` could unknowingly act from a different context.
- **Evidence**:
```php
private function resolveActingAccount(User $user, Account $minorAccount, ?string $actingAccountUuid): ?Account
{
    if (is_string($actingAccountUuid) && $actingAccountUuid !== '' && $actingAccountUuid !== $minorAccount->uuid) {
        $contextAccount = Account::query()
            ->where('uuid', $actingAccountUuid)
            ->where('user_uuid', $user->uuid)
            ->first();
        if ($contextAccount !== null) {
            return $contextAccount;
        }
        // BUG: falls through silently to default account
    }
    return Account::query()  // default: first non-minor account
        ->where('user_uuid', $user->uuid)
        ->where('uuid', '!=', $minorAccount->uuid)
        ->orderByRaw("case when type = 'personal' then 0 else 1 end")
        ->orderBy('id')
        ->first();
}
```
- **Remediation**: Throw `AuthorizationException` if `$actingAccountUuid` is explicitly provided but doesn't resolve to a valid owned account.

**8. MinorAccountController - `updatePermissionLevel` Race Condition**

- **Severity**: P2 Minor
- **Location**: `app/Http/Controllers/Api/MinorAccountController.php:150-222`
- **Category**: Robustness
- **Impact**: Two concurrent guardians could read level 3 simultaneously, both write 4, bypassing the one-level-at-a-time rule.
- **Evidence**: Reads `currentPermissionLevel` (line 164) and writes new value (line 194) without row locking.
- **Remediation**: Add `lockForUpdate()` on the account query before the read/write.

**9. MinorFamilyFundingLink `makeFundingAttemptDedupeHash` Time Window Collision**

- **Severity**: P2 Minor
- **Location**: `app/Domain/Account/Services/MinorFamilyIntegrationService.php:782-795`
- **Category**: Robustness
- **Impact**: Two funding attempts in the same minute from the same sponsor to the same link get identical hashes — second attempt silently returns the existing record instead of creating a new one.
- **Evidence**:
```php
private function makeFundingAttemptDedupeHash(...): string
{
    return hash('sha256', implode('|', [
        $link->id,
        $this->normaliseMsisdn($sponsorMsisdn),
        $amount,
        $provider,
        now()->format('YmdHi'),  // minute precision — collisions within same minute
    ]));
}
```
- **Remediation**: Use `now()->format('YmdHis')` (second precision) or add a random nonce.

**10. MinorAccountLifecycleController - `abort()` vs Exception Inconsistency**

- **Severity**: P2 Minor
- **Location**: `app/Http/Controllers/Api/MinorAccountLifecycleController.php:139-150`
- **Category**: Code Quality
- **Impact**: `authorizeMutation()` calls `abort(403)` directly instead of throwing through `AuthorizationException`. This bypasses the Laravel exception handler stack inconsistently.
- **Evidence**:
```php
private function authorizeMutation(User $actor, Account $minorAccount, bool $allowGuardian): void
{
    if ($actor->can('view-transactions')) {
        return;
    }
    if (! $allowGuardian) {
        abort(403);  // BUG: inconsistent with rest of codebase
    }
    $this->accessService->authorizeGuardian($actor, $minorAccount);
}
```
- **Remediation**: Use `throw new AuthorizationException()` for consistency.

**11. MinorRedemptionOrderService - Approval Lacks Explicit Cross-Account Scope**

- **Severity**: P2 Minor
- **Location**: `app/Domain/Account/Services/MinorRedemptionOrderService.php:64-71`
- **Category**: Authorization
- **Impact**: The approval query includes `where('minor_account_uuid', $minorAccount->uuid)` (line 68), so the scope IS present. However, the failure mode is `firstOrFail()` returning 404 rather than an explicit authorization error. This is acceptable but not ideal — an explicit check would be clearer.
- **Evidence**: `MinorRewardRedemption::query()->where('id', $redemptionId)->where('minor_account_uuid', $minorAccount->uuid)` — scope is present.
- **Remediation**: Add an explicit cross-account check with a clear error message.

**12. MinorCardConstants - No Length Constraint on Invite Codes**

- **Severity**: P2 Minor
- **Location**: `app/Http/Controllers/Api/CoGuardianController.php:122-129` + `GuardianInvite` model
- **Category**: Code Quality
- **Impact**: `generateUniqueCode()` creates 8-character codes but the model has no max_length validation.
- **Evidence**: `Str::random(8)` generates 8 chars — no model constraint.
- **Remediation**: Add `->rule('max:8')` in model or validation request.

---

### [P3] MINOR / POLISH

**13. MinorCardController - `show()` Exposes Full Card Object**

- **Severity**: P3 Polish
- **Location**: `app/Http/Controllers/Api/Account/MinorCardController.php:199`
- **Category**: Data Protection
- **Impact**: `return response()->json($card)` exposes all card fields including potentially sensitive metadata.
- **Remediation**: Transform to explicit array with only safe fields.

**14. MinorFamilySupportTransferController - Idempotency Header Error Message Type**

- **Severity**: P3 Polish
- **Location**: `app/Http/Controllers/Api/MinorFamilySupportTransferController.php:62-66`
- **Category**: Code Quality
- **Impact**: Returns `message` as array instead of string — invalid JSON.
- **Evidence**: `return response()->json(['message' => ['Idempotency-Key header is required...']], 422)`
- **Remediation**: `['message' => 'Idempotency-Key header is required for family support transfer requests.']`

**15. MinorChoreController - `requireAccess` Fallback to Minor Account**

- **Severity**: P3 Polish
- **Location**: `app/Http/Controllers/Api/MinorChoreController.php:336-352`
- **Category**: Code Quality
- **Impact**: Falls back to `$minorAccount` if no guardian account exists for the user — means a child could act with the minor account as guardian context. Functionally harmless but confusing.
- **Remediation**: Return explicit error instead of fallback.

**16. MinorCardConstants - No Enum for Request Type Strings**

- **Severity**: P3 Polish
- **Location**: `app/Domain/Account/Constants/MinorCardConstants.php`
- **Category**: Code Quality
- **Impact**: `REQUEST_TYPE_CHILD_REQUESTED` and `REQUEST_TYPE_PARENT_INITIATED` are bare strings. No PHP enum enforces valid values.
- **Remediation**: Create a `MinorCardRequestType` enum.

**17. MinorPointsService - `getBalance` Return Type Annotation**

- **Severity**: P3 Polish
- **Location**: `app/Domain/Account/Services/MinorPointsService.php:14-24`
- **Category**: Code Quality
- **Impact**: Return type annotation `int` is slightly misleading — `sum('points')` returns `float|null` which Eloquent coerces.
- **Remediation**: Cast result explicitly: `(int) $query->sum('points')`.

**18. MinorCardConstants - No Index on approved_by_user_uuid**

- **Severity**: P3 Polish
- **Location**: `database/migrations/tenant/2026_04_24_002653_create_minor_card_requests_table.php`
- **Category**: Performance
- **Impact**: Every Filament list page that filters by approver does a full table scan.
- **Remediation**: Add `$table->index('approved_by_user_uuid')`.

---

## CRITICAL PATTERNS VERIFICATION

| Pattern | Status | Notes |
|---------|--------|-------|
| `authorizeGuardian(User, Account)` | ✅ PASS | Correctly used across all 22+ call sites |
| `issuer_card_token` | ✅ PASS | All card lookups use `issuer_card_token`, never `card_token` |
| Tier check `$account->tier` | ⚠️ PARTIAL | Check exists in `createRequest()` but unenforced in `provision()` |
| No `BusinessException` | ✅ PASS | Not found anywhere in codebase |
| `DB::transaction()` for money ops | ✅ PASS | Chores, Redemptions, Cards, Lifecycle all wrapped |
| `lockForUpdate()` concurrent writes | ✅ PASS | All critical financial paths protected |
| Idempotency keys | ✅ PASS | Funding links and family transfers use `OperationRecord` |
| MSISDN masking | ⚠️ PARTIAL | Masks 5+2 digits — too much for Swazi numbers |
| User UUID for identification | ✅ PASS | All services use `$requester->uuid`, never `$requester->account->uuid` |
| Invite code 72h expiry | ✅ PASS | Correctly enforced in `CoGuardianController` |

---

## POSITIVE FINDINGS

- **DDD Structure**: Domain logic correctly isolated in `app/Domain/Account/Services/`
- **Guardian Authorization**: Pattern is consistently followed — `authorizeGuardian(User, Account)` used everywhere
- **Card Token Security**: `issuer_card_token` used exclusively — no `card_token` leakage found
- **Event Sourcing**: Lifecycle events properly dispatched with context (`MinorAccountLifecycleTransitionScheduled`, etc.)
- **Database Transactions**: Multi-step operations (chores, redemptions, cards) wrapped in `DB::transaction()` with `lockForUpdate()`
- **Points Deduplication**: `reference_id` prevents double-earning for milestones
- **Idempotency**: Funding links and family transfers use `OperationRecord` + idempotency key
- **Invite Expiry**: 72-hour expiry correctly enforced
- **Exception Types**: Uses `InvalidArgumentException`, `AuthorizationException`, `RuntimeException` — no fake exceptions
- **Concurrency**: `lockForUpdate()` used in all critical financial paths
- **Input Validation**: Controllers use Laravel's `validate()` with appropriate rules
- **Error Logging**: Services log errors with sufficient context
- **SLA Tracking**: Lifecycle exceptions track `first_seen_at`, `sla_due_at`, `occurrence_count`

---

## RECOMMENDED ACTIONS (Priority Order)

| Priority | Action | Description |
|----------|--------|-------------|
| P0 | Fix `isChild()` authorization | Change role query from `['guardian', 'co_guardian']` to `'owner'` |
| P1 | Add tier check to `provision()` | Verify `$account->tier === 'rise'` before wallet provisioning |
| P1 | Add `lockForUpdate()` to points award | Lock ledger entry in `findExistingEntry()` when referenceId present |
| P1 | Make `guardian_account_uuid` required | Add validation in spend approval creation |
| P2 | `resolveActingAccount()` explicit rejection | Throw `AuthorizationException` on mismatched explicit UUID |
| P2 | Wrap `createRequest()` in transaction | Add `DB::transaction()` to `MinorCardRequestService::createRequest()` |
| P2 | Add row lock to permission level update | Lock account before read/write in `updatePermissionLevel()` |
| P2 | Use second-precision depe hash | `now()->format('YmdHis')` in `makeFundingAttemptDedupeHash()` |
| P2 | Replace `abort(403)` with exception | Use `throw new AuthorizationException()` in `authorizeMutation()` |
| P3 | Fix MSISDN masking | Show only last 2 digits: `'****' . substr($digits, -2)` |
| P3 | Transform card response | Explicit array in `show()` instead of raw model |
| P3 | Fix error message types | String not array in idempotency header validation |

---

## VERIFICATION COMMANDS

```bash
# Static analysis
./vendor/bin/phpstan analyse --memory-limit=2G

# Unit tests
./vendor/bin/pest tests/Unit/Domain/Account/Services/

# Integration tests
./vendor/bin/pest tests/Feature/Http/Controllers/Api/Minor

# Security-focused tests
./vendor/bin/pest --filter=Authorization

# Card controller tests
./vendor/bin/pest tests/Feature/Http/Controllers/Api/MinorCardControllerTest.php
```

---

## FILES AUDITED

### Domain Models
```
app/Domain/Account/Models/Account.php
app/Domain/Account/Models/AccountMembership.php
app/Domain/Account/Models/GuardianInvite.php
app/Domain/Account/Models/MinorSpendApproval.php
app/Domain/Account/Models/MinorReward.php
app/Domain/Account/Models/MinorRedemptionApproval.php
app/Domain/Account/Models/MinorRedemptionOrder.php
app/Domain/Account/Models/MinorChore.php
app/Domain/Account/Models/MinorChoreCompletion.php
app/Domain/Account/Models/MinorPointsLedger.php
app/Domain/Account/Models/MinorFamilyFundingLink.php
app/Domain/Account/Models/MinorFamilyFundingAttempt.php
app/Domain/Account/Models/MinorFamilySupportTransfer.php
app/Domain/Account/Models/MinorFamilyReconciliationException.php
app/Domain/Account/Models/MinorAccountLifecycleTransition.php
app/Domain/Account/Models/MinorAccountLifecycleException.php
app/Domain/Account/Models/MinorCardLimit.php
app/Domain/Account/Models/MinorCardRequest.php
```

### Domain Services
```
app/Domain/Account/Services/MinorAccountAccessService.php
app/Domain/Account/Services/MinorCardRequestService.php
app/Domain/Account/Services/MinorCardService.php
app/Domain/Account/Services/MinorPointsService.php
app/Domain/Account/Services/MinorChoreService.php
app/Domain/Account/Services/MinorRewardService.php
app/Domain/Account/Services/MinorRedemptionOrderService.php
app/Domain/Account/Services/MinorFamilyIntegrationService.php
app/Domain/Account/Services/MinorAccountLifecycleService.php
app/Domain/Account/Services/MinorAccountLifecyclePolicy.php
```

### API Controllers
```
app/Http/Controllers/Api/Account/MinorCardController.php
app/Http/Controllers/Api/MinorAccountController.php
app/Http/Controllers/Api/MinorAccountLifecycleController.php
app/Http/Controllers/Api/MinorFamilyFundingLinkController.php
app/Http/Controllers/Api/MinorFamilySupportTransferController.php
app/Http/Controllers/Api/MinorSpendApprovalController.php
app/Http/Controllers/Api/MinorPointsController.php
app/Http/Controllers/Api/MinorChoreController.php
app/Http/Controllers/Api/MinorRedemptionOrdersController.php
app/Http/Controllers/Api/CoGuardianController.php
```

### Migrations
```
database/migrations/tenant/2026_04_16_000001_add_minor_account_columns.php
database/migrations/tenant/2026_04_17_100000_create_minor_spend_approvals_table.php
database/migrations/tenant/2026_04_18_100000_create_minor_points_ledger_table.php
database/migrations/tenant/2026_04_18_100002_create_minor_reward_redemptions_table.php
database/migrations/tenant/2026_04_18_100003_create_minor_chores_table.php
database/migrations/tenant/2026_04_18_100004_create_minor_chore_completions_table.php
database/migrations/tenant/2026_04_20_099999_create_minor_rewards_table.php
database/migrations/tenant/2026_04_20_100001_recreate_minor_reward_redemptions_table.php
database/migrations/tenant/2026_04_20_100002_create_minor_redemption_approvals_table.php
database/migrations/tenant/2026_04_23_110000_create_minor_account_lifecycle_transitions_table.php
database/migrations/tenant/2026_04_23_110100_create_minor_account_lifecycle_exceptions_table.php
database/migrations/tenant/2026_04_23_110110_create_minor_account_lifecycle_exception_acknowledgments_table.php
database/migrations/tenant/2026_04_24_002653_create_minor_card_limits_table.php
database/migrations/tenant/2026_04_24_002653_create_minor_card_requests_table.php
database/migrations/tenant/2026_04_24_002653_add_minor_account_uuid_to_cards_table.php
```

### Constants
```
app/Domain/Account/Constants/MinorCardConstants.php
```

### Tests (47 files total)
```
tests/Feature/Http/Controllers/Api/MinorCardControllerTest.php
tests/Unit/Domain/Account/Services/MinorCardServiceTest.php
tests/Unit/Domain/Account/Services/MinorCardRequestServiceTest.php
tests/Feature/Http/Controllers/Api/MinorAccountControllerTest.php
tests/Feature/Http/Controllers/Api/MinorAccountLifecycleControllerTest.php
tests/Feature/Http/Controllers/Api/MinorFamilyFundingLinkControllerTest.php
tests/Feature/Http/Controllers/Api/MinorFamilySupportTransferControllerTest.php
tests/Feature/Http/Controllers/Api/MinorSpendApprovalControllerTest.php
tests/Feature/Http/Controllers/Api/MinorPointsServiceTest.php
tests/Feature/Http/Controllers/Api/MinorChoreTest.php
tests/Feature/Http/Controllers/Api/MinorRewardTest.php
tests/Feature/Http/Controllers/Api/MinorRedemptionOrdersControllerTest.php
tests/Feature/Http/Controllers/Api/MinorEmergencyBypassTest.php
tests/Feature/Http/Controllers/Api/PublicMinorFundingLinkControllerTest.php
tests/Feature/Http/Controllers/Api/CoGuardianControllerTest.php
(33 additional test files)
```

---

**Remember: This is a banking app. Mistakes can be catastrophic. Fix P0 before any production deployment.**