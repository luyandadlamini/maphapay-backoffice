# Company Account Creation - Backend Implementation Spec

## Context

MaphaPay needs Company accounts for team/enterprise use with approval workflows. The backend already supports:
- `personal` accounts (default on signup)
- `merchant` accounts (via `POST /api/accounts/merchant`)
- `account_type: 'company'` in the `account_memberships` table

We need to implement the full backend stack following the merchant pattern with best practices.

## Architecture Pattern

Following Laravel best practices and the existing `MerchantAccountService` pattern:

```
Controller (validation + auth) → Service (business logic + transactions) → Model (persistence)
```

### Key Design Decisions

1. **Transaction Wrapping**: All database operations wrapped in `DB::transaction()` to ensure atomicity
2. **Duplicate Prevention**: Use `unique` constraints + application-level checks
3. **KYC Gate**: Require identity verification before company account creation (same as merchant)
4. **Audit Logging**: Record all company account creation events
5. **Input Sanitization**: Use existing `NoControlCharacters` and `NoSqlInjection` rules

## API Contract

### Endpoint
```
POST /api/accounts/company
```

### Headers
- `Authorization: Bearer {token}`
- `Accept: application/json`

### Request Body
```json
{
  "company_name": "string (required, max 255)",
  "registration_number": "string (optional, max 50)",
  "industry": "string (required, max 100)",
  "company_size": "string (required, enum: small|medium|large|enterprise)",
  "settlement_method": "string (required, enum: maphapay_wallet|mobile_money|bank)",
  "address": "string (optional, max 500)",
  "description": "string (optional, max 1000)"
}
```

### Validation Rules
- `company_name`: required, string, max 255, sanitized
- `registration_number`: nullable, string, max 50, alphanumeric + dashes
- `industry`: required, string, max 100
- `company_size`: required, in:small,medium,large,enterprise
- `settlement_method`: required, in:maphapay_wallet,mobile_money,bank
- `address`: nullable, string, max 500
- `description`: nullable, string, max 1000

### Success Response (201)
```json
{
  "success": true,
  "data": {
    "account_uuid": "uuid",
    "tenant_id": "string",
    "account_type": "company",
    "display_name": "string",
    "role": "owner",
    "verification_tier": "unverified",
    "capabilities": ["can_receive_payments"]
  }
}
```

### Error Responses
- **403**: No personal account exists
- **403**: KYC not approved
- **409**: Company account already exists
- **422**: Validation errors

## Implementation Components

### 1. Database Migration (Tenant)
Create `database/migrations/tenant/YYYY_MM_DD_create_account_profiles_company_table.php`:

```php
Schema::create('account_profiles_company', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('account_uuid');
    $table->string('company_name');
    $table->string('registration_number')->nullable();
    $table->string('industry');
    $table->string('company_size'); // small, medium, large, enterprise
    $table->string('settlement_method');
    $table->string('address')->nullable();
    $table->text('description')->nullable();
    $table->timestamp('verified_at')->nullable();
    $table->timestamps();
    
    $table->foreign('account_uuid')->references('uuid')->on('accounts')->cascadeOnDelete();
    $table->index('account_uuid');
});
```

### 2. Model
Create `app/Domain/Account/Models/AccountProfileCompany.php`:

```php
class AccountProfileCompany extends Model
{
    use HasUuids, UsesTenantConnection;
    
    protected $table = 'account_profiles_company';
    
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_uuid', 'uuid');
    }
}
```

### 3. Service
Create `app/Domain/Account/Services/CompanyAccountService.php`:

```php
class CompanyAccountService
{
    public function __construct(
        private readonly AccountMembershipService $membershipService,
        private readonly AccountService $accountService,
    ) {}
    
    /**
     * @return array{account: Account, profile: AccountProfileCompany, membership: AccountMembership}
     */
    public function createForUser(User $user, string $tenantId, array $profileData): array
    {
        // 1. Check for existing company account
        $existingCompany = AccountMembership::query()
            ->forUser($user->uuid)
            ->where('account_type', 'company')
            ->active()
            ->exists();
            
        if ($existingCompany) {
            throw ValidationException::withMessages([
                'company' => ['You already have a company account.'],
            ]);
        }
        
        $account = null;
        $profile = null;
        
        try {
            DB::transaction(function () use ($user, $profileData, &$account, &$profile): void {
                // 2. Create account via event-sourced path
                $accountUuid = $this->accountService->createDirect(
                    new AccountDTO(
                        name: $profileData['company_name'],
                        userUuid: $user->uuid,
                    )
                );
                
                $account = Account::query()->where('uuid', $accountUuid)->firstOrFail();
                
                // 3. Set company-specific fields
                $account->update([
                    'display_name' => $profileData['company_name'],
                    'type' => 'company',
                    'verification_tier' => 'unverified',
                    'capabilities' => ['can_receive_payments'],
                ]);
                
                // 4. Create company profile
                $profile = AccountProfileCompany::query()->create([
                    'account_uuid' => $account->uuid,
                    'company_name' => $profileData['company_name'],
                    'registration_number' => $profileData['registration_number'] ?? null,
                    'industry' => $profileData['industry'],
                    'company_size' => $profileData['company_size'],
                    'settlement_method' => $profileData['settlement_method'],
                    'address' => $profileData['address'] ?? null,
                    'description' => $profileData['description'] ?? null,
                ]);
            });
            
            // 5. Create membership in central DB (outside tenant transaction)
            $membership = $this->membershipService->createOwnerMembership(
                $user,
                $tenantId,
                $account,
                $profileData['company_name'],
                [
                    'verification_tier' => 'unverified',
                    'capabilities' => ['can_receive_payments'],
                ],
            );
            
            // 6. Audit log
            AccountAuditLog::create([
                'account_uuid' => $account->uuid,
                'actor_user_uuid' => $user->uuid,
                'action' => 'account.created',
                'metadata' => ['company_name' => $profileData['company_name'], 'type' => 'company'],
                'created_at' => now(),
            ]);
            
            return [
                'account' => $account->fresh(),
                'profile' => $profile->fresh(),
                'membership' => $membership,
            ];
        } catch (Throwable $e) {
            // Cleanup on failure
            if ($account !== null) {
                try {
                    $profile?->forceDelete();
                    $account->forceDelete();
                } catch (Throwable $cleanupException) {
                    Log::error('CompanyAccountService: cleanup failed', [
                        'user_uuid' => $user->uuid,
                        'account_uuid' => $account->uuid ?? null,
                        'error' => $e->getMessage(),
                        'cleanup_error' => $cleanupException->getMessage(),
                    ]);
                }
            }
            throw $e;
        }
    }
}
```

### 4. Controller
Add to `AccountController.php`:

```php
public function createCompany(Request $request): JsonResponse
{
    $validated = $request->validate([
        'company_name' => ['required', 'string', 'max:255', new NoControlCharacters(), new NoSqlInjection()],
        'registration_number' => ['nullable', 'string', 'max:50'],
        'industry' => ['required', 'string', 'max:100', new NoControlCharacters(), new NoSqlInjection()],
        'company_size' => 'required|in:small,medium,large,enterprise',
        'settlement_method' => 'required|in:maphapay_wallet,mobile_money,bank',
        'address' => ['nullable', 'string', 'max:500', new NoControlCharacters(), new NoSqlInjection()],
        'description' => ['nullable', 'string', 'max:1000', new NoControlCharacters(), new NoSqlInjection()],
    ]);
    
    $user = $request->user();
    $tenantId = (string) ($request->attributes->get('tenant_id') ?? '');
    
    if ($tenantId === '') {
        return response()->json([
            'success' => false,
            'message' => 'A valid account context is required to create a company account.',
        ], 403);
    }
    
    // Verify personal account membership exists
    $personalMembership = AccountMembership::query()
        ->forUser($user->uuid)
        ->where('account_type', 'personal')
        ->where('status', 'active')
        ->first();
    
    if ($personalMembership === null) {
        return response()->json([
            'success' => false,
            'message' => 'A personal account is required before creating a company account.',
        ], 403);
    }
    
    // KYC gate
    if (!in_array($user->kyc_status, ['approved'], true)) {
        return response()->json([
            'success' => false,
            'message' => 'Identity verification is required before creating a company account.',
        ], 403);
    }
    
    $result = $this->companyAccountService->createForUser($user, $tenantId, $validated);
    
    return response()->json([
        'success' => true,
        'data' => [
            'account_uuid' => $result['account']->uuid,
            'tenant_id' => $tenantId,
            'account_type' => 'company',
            'display_name' => $result['account']->display_name,
            'role' => $result['membership']->role,
            'verification_tier' => $result['membership']->verification_tier,
            'capabilities' => $result['membership']->capabilities ?? [],
        ],
    ], 201);
}
```

### 5. Route
Add to `app/Domain/Account/Routes/api.php`:

```php
Route::post('/accounts/company', [AccountController::class, 'createCompany'])
    ->middleware(['api.rate_limit:mutation', 'scope:write']);
```

### 6. Service Injection
Add to constructor of `AccountController`:

```php
private readonly CompanyAccountService $companyAccountService,
```

## Migration Commands

After creating migration files:

```bash
# Run tenant migrations
php artisan tenants:migrate --force

# Or for fresh tenants
php artisan tenants:migrate-fresh --force
```

## Testing Requirements

1. **Success case**: User with personal account + approved KYC creates company account
2. **No personal account**: Returns 403 with appropriate message
3. **KYC not approved**: Returns 403 with appropriate message
4. **Duplicate company account**: Returns 409 with appropriate message
5. **Validation errors**: Returns 422 with field errors

## Security Considerations

1. **Input sanitization**: Use `NoControlCharacters` and `NoSqlInjection` rules
2. **Authorization**: Must have active personal account membership
3. **KYC gate**: Same requirement as merchant accounts
4. **Audit logging**: All creation events logged
5. **Transaction atomicity**: No partial state on failure