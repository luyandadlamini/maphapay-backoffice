# Phase 12 Implementation Prompt: Minor Virtual Card (Rise Tier 13+)

## Context

You are implementing Phase 12 of the Minor Accounts feature - Virtual Card Support for Rise tier (ages 13-17). This enables Rise tier minors to have a virtual card for Apple Pay / Google Pay with parental controls.

**Reference Documents:**
- Spec: `docs/superpowers/specs/2026-04-24-minor-accounts-phase12-virtual-card-spec.md`
- Plan: `docs/superpowers/plans/2026-04-24-minor-accounts-phase12-virtual-card-plan.md`

---

## CRITICAL Implementation Rules (Read First)

### 1. Authorization Pattern
`MinorAccountAccessService` takes `User + Account` pairs. ALWAYS use:
```php
$user = $request->user(); // App\Models\User
$minorAccount = Account::where('uuid', $minorUuid)->firstOrFail();
$this->accessService->authorizeGuardian($user, $minorAccount); // throws AuthorizationException
```

### 2. Card Token Column
The `cards` table uses `issuer_card_token` (NOT `card_token`):
```php
Card::where('issuer_card_token', $cardToken) // CORRECT
```

### 3. Tier Verification
Use `$account->tier` - ALREADY EXISTS on accounts table (values: 'rise' or 'grow'):
```php
if ($account->tier !== 'rise') {
    throw new \InvalidArgumentException('Virtual cards are only available for Rise tier');
}
```

### 4. User Identification
Use `$user->uuid` for user identification:
```php
'requested_by_user_uuid' => $requester->uuid // CORRECT
```

### 5. Exception Pattern
DO NOT use `BusinessException` - use:
- `\InvalidArgumentException` for validation errors
- `\RuntimeException` for operational errors

---

## Implementation Steps

### Step 1: Create Database Migrations

Create three migration files:
1. `database/migrations/tenant/2026_04_24_xxxxxx_create_minor_card_limits_table.php`
2. `database/migrations/tenant/2026_04_24_xxxxxx_create_minor_card_requests_table.php`
3. `database/migrations/tenant/2026_04_24_xxxxxx_add_minor_account_uuid_to_cards_table.php`

Use the migration code from the plan document (Section 12.2.1 - 12.2.3).

### Step 2: Create Models

1. `app/Domain/Account/Models/MinorCardLimit.php` - with relationship to Account
2. `app/Domain/Account/Models/MinorCardRequest.php` - with relationship to Account

### Step 3: Update Existing Model

Add `minor_account_uuid` to `fillable` and add `minorAccount()` relationship in:
- `app/Domain/CardIssuance/Models/Card.php`

### Step 4: Create Constants

Create `app/Domain/Account/Constants/MinorCardConstants.php` with status constants and default limits.

### Step 5: Create Services

1. `app/Domain/Account/Services/MinorCardRequestService.php` - request/approve/deny logic
   - Uses `MinorAccountAccessService` with `User + Account` pattern
   - Uses `$account->tier` for tier check
   
2. `app/Domain/Account/Services/MinorCardService.php` - card creation
   - Uses `issuer_card_token` for Card queries
   - Creates limits from `minor_card_limits` table

### Step 6: Create API Controller

Create `app/Http/Controllers/Api/Account/MinorCardController.php`:
- `createRequest()` - uses `authorizeGuardian(User, Account)`
- `approveRequest()` - uses `authorizeGuardian(User, Account)`
- `denyRequest()` - uses `authorizeGuardian(User, Account)`
- `freeze()` - uses `authorizeGuardian(User, Account)`
- `unfreeze()` - uses `authorizeGuardian(User, Account)`
- `provision()` - passes `$user->uuid` to `getProvisioningData`
- `index()`, `show()` - with proper access checks

### Step 7: Add Routes

Add routes to `routes/api.php` under `/api/v1`:
```php
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    Route::get('minor-cards/requests', [MinorCardController::class, 'listRequests']);
    Route::post('minor-cards/requests', [MinorCardController::class, 'createRequest']);
    Route::get('minor-cards/requests/{id}', [MinorCardController::class, 'showRequest']);
    Route::post('minor-cards/requests/{id}/approve', [MinorCardController::class, 'approveRequest']);
    Route::post('minor-cards/requests/{id}/deny', [MinorCardController::class, 'denyRequest']);
    Route::get('minor-cards', [MinorCardController::class, 'index']);
    Route::get('minor-cards/{cardId}', [MinorCardController::class, 'show']);
    Route::post('minor-cards/{cardId}/freeze', [MinorCardController::class, 'freeze']);
    Route::delete('minor-cards/{cardId}/freeze', [MinorCardController::class, 'unfreeze']);
    Route::post('minor-cards/{cardId}/provision', [MinorCardController::class, 'provision']);
});
```

### Step 8: Create Scheduled Command

Create `app/Console/Commands/ExpireMinorCardRequests.php` to auto-expire pending requests after 72 hours.

### Step 9: Create Filament Resource

Create `app/Filament/Admin/Resources/MinorCardRequestResource.php` with:
- List page with filters
- View page
- ApproveAction - uses guardian check
- DenyAction - uses guardian check

### Step 10: Write Tests

Create test files:
1. `tests/Unit/Domain/Account/Services/MinorCardRequestServiceTest.php`
2. `tests/Unit/Domain/Account/Services/MinorCardServiceTest.php`
3. `tests/Feature/Http/Controllers/Api/MinorCardControllerTest.php`

Test critical paths:
- Tier check rejects 'grow' tier
- `authorizeGuardian` throws for non-guardian
- Card queries use `issuer_card_token`
- 403 returned for non-guardian on approve/deny/freeze

---

## Verification Commands

After implementation, run:
```bash
# Static analysis
./vendor/bin/phpstan analyse --memory-limit=2G

# Unit tests for new services
./vendor/bin/pest tests/Unit/Domain/Account/Services/MinorCardRequestServiceTest.php
./vendor/bin/pest tests/Unit/Domain/Account/Services/MinorCardServiceTest.php

# Integration tests
./vendor/bin/pest tests/Feature/Http/Controllers/Api/MinorCardControllerTest.php

# Regression
./vendor/bin/pest --filter=CardIssuance
./vendor/bin/pest --filter=MinorAccount
```

---

## Deliverables

When complete, provide:
1. All new files created
2. Modified files list
3. Test results
4. Static analysis results

If you encounter any issues with the implementation patterns, STOP and ask for clarification before proceeding.

---

## Reference: Checkpoint Questions

Before moving to each section, verify:

1. **Migration**: Does the table have the correct columns? Does it use tenant_id?
2. **Model**: Is `UsesTenantConnection` used? Are relationships defined?
3. **Service**: Does authorization use `authorizeGuardian(User, Account)`?
4. **Controller**: Are all guardian actions guarded?
5. **Tests**: Do tests verify 403 for non-guardians?