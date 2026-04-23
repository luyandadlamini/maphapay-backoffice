# Minor Accounts Phase 12 Virtual Card Support (Rise Tier 13+) Spec

Date: 2026-04-24

## 1. Executive Summary

Phase 12 delivers virtual card issuance and management for Rise tier minor accounts (ages 13-17). The timing is appropriate because the existing CardIssuance domain is fully functional, Phase 10 lifecycle automation is in place, and merchant category blocking already works for minors. The primary user benefit is enabling Rise tier children to have a virtual card for online purchases and contactless payments via Apple Pay / Google Pay. Parental controls remain central: parents must approve issuance, spending limits enforced via new `minor_card_limits` table, cards can be frozen independently, and merchant category blocks are enforced at the card level.

## 2. Scope

### In Scope

- Virtual card issuance for minor accounts on Rise tier (age 13-17) only.
- Parent approval workflow (two patterns: parent-initiated or child-requested + parent-approved).
- Card spending limits via new `minor_card_limits` table (NOT account-level limits).
- Independent card freeze/unfreeze independent of account status.
- Apple Pay / Google Pay provisioning via existing `CardProvisioningService`.
- Merchant category blocklist enforcement at card level.
- Filament admin surface for card management and approval workflow.
- Card issuance requests tracking (`minor_card_requests` table).
- Card-to-minor account linking via `minor_account_uuid` on `cards` table.
- Scheduled job to auto-expire stale pending requests.

### Out of Scope

- Physical card issuance (virtual only).
- Card replacement or reissuance.
- International transaction controls (Phase 13+).
- Spending analytics.

## 3. Critical Implementation Details

### IMPORTANT: How to Derive Age and Tier

**DO NOT assume fields on accounts table.** This is a fintech app with strict data integrity:

1. **Tier**: Use `$account->tier` - ALREADY EXISTS on accounts table. Values: `'rise'` or `'grow'`.
2. **Age**: Get from `UserProfile` where `user_profiles.user_id = $account->user_id`, field `date_of_birth`.
3. **Limits**: Create NEW table `minor_card_limits` - limits are NOT stored on accounts.

### Tier Verification Pattern

```php
// CORRECT - verify tier from accounts table
$tier = $account->tier;
if ($tier !== 'rise') {
    throw new \InvalidArgumentException('Virtual cards are only available for Rise tier (ages 13+)');
}

// CORRECT - get age from UserProfile
$profile = \App\Domain\User\Models\UserProfile::query()
    ->where('user_id', $account->user_id)
    ->first();
$age = $profile?->date_of_birth 
    ? now()->diffInYears($profile->date_of_birth) 
    : null;
```

### Authorization Pattern

`MinorAccountAccessService` takes `User + Account` pairs:

```php
$user = $request->user(); // App\Models\User
$minorAccount = Account::where('uuid', $minorUuid)->firstOrFail();
$this->accessService->authorizeGuardian($user, $minorAccount); // throws AuthorizationException if not guardian
```

### Card Token Column

The `cards` table uses `issuer_card_token` (NOT `card_token`).

### Exception Pattern

**DO NOT use BusinessException** - it doesn't exist. Use:
- `\InvalidArgumentException` for validation errors
- `\RuntimeException` for operational errors
- `\Illuminate\Auth\Access\AuthorizationException` for auth errors

## 4. Data Model Changes

### New Tables

#### `minor_card_limits` table

```php
Schema::create('minor_card_limits', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->nullable();
    $table->uuid('minor_account_uuid')->unique(); // one-to-one with accounts
    $table->decimal('daily_limit', 12, 2)->default(2000.00);
    $table->decimal('monthly_limit', 12, 2)->default(10000.00);
    $table->decimal('single_transaction_limit', 12, 2)->default(1500.00);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    
    $table->foreign('minor_account_uuid')->references('uuid')->on('accounts')->onDelete('cascade');
});
```

#### `minor_card_requests` table

```php
Schema::create('minor_card_requests', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->nullable();
    $table->uuid('minor_account_uuid');
    $table->uuid('requested_by_user_uuid'); // User who requested
    $table->string('request_type'); // 'parent_initiated' | 'child_requested'
    $table->string('status')->default('pending_approval');
    $table->string('requested_network')->default('visa');
    $table->decimal('requested_daily_limit', 12, 2)->nullable();
    $table->decimal('requested_monthly_limit', 12, 2)->nullable();
    $table->decimal('requested_single_limit', 12, 2)->nullable();
    $table->text('denial_reason')->nullable();
    $table->uuid('approved_by_user_uuid')->nullable();
    $table->timestamp('approved_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
});
```

#### Add `minor_account_uuid` to `cards` table

```php
Schema::table('cards', function (Blueprint $table) {
    $table->uuid('minor_account_uuid')->nullable()->after('user_id');
    $table->foreign('minor_account_uuid')
          ->references('uuid')
          ->on('accounts')
          ->onDelete('cascade');
    $table->index(['minor_account_uuid', 'status']);
});
```

## 5. API Contract

### Endpoints (Sanctum authenticated)

| Method | Endpoint | Authorization |
|--------|----------|----------------|
| GET | `/api/v1/minor-cards/requests` | User is minor OR guardian |
| POST | `/api/v1/minor-cards/requests` | User is minor OR guardian |
| GET | `/api/v1/minor-cards/requests/{id}` | User is minor OR guardian |
| POST | `/api/v1/minor-cards/requests/{id}/approve` | Guardian ONLY via authorizeGuardian() |
| POST | `/api/v1/minor-cards/requests/{id}/deny` | Guardian ONLY |
| GET | `/api/v1/minor-cards` | User is minor OR guardian |
| GET | `/api/v1/minor-cards/{cardId}` | User is minor OR guardian |
| POST | `/api/v1/minor-cards/{cardId}/freeze` | Guardian ONLY |
| DELETE | `/api/v1/minor-cards/{cardId}/freeze` | Guardian ONLY |
| POST | `/api/v1/minor-cards/{cardId}/provision` | User is minor OR guardian |

## 6. Failure Modes

### Financial Integrity

- Limits stored in `minor_card_limits` table - enforced at JIT authorization.
- Account frozen check via existing `Account::frozen` attribute.
- Card frozen check via `Card::status === 'frozen'`.

### Authorization

- **ALL** guardian-only actions MUST call `authorizeGuardian($user, $minorAccount)` before executing.
- Non-guardian calls return 403 via `AuthorizationException`.
- Use `$user->uuid` not `$user->account` for user identification.

## 7. Verification Strategy

### Unit Tests

- Tier check: account.tier === 'rise' allows, 'grow' rejects
- Age derivation: use UserProfile.date_of_birth
- Limits: read from minor_card_limits table
- Authorization: authorizeGuardian throws for non-guardian
- Card token column: uses issuer_card_token

### Integration Tests

- Full approval flow with tier verification
- Guardian-only endpoints reject non-guardians with 403