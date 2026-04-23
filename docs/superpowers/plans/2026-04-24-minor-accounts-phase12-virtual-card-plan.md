# Minor Accounts Phase 12 Virtual Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable Rise tier (ages 13+) minor accounts to request and use a virtual card, with parent approval workflow, spending limit enforcement, and independent card freeze.

**Architecture:** Extend the existing CardIssuance domain with minor-specific request/approval flow. Card issuance through existing `CardProvisioningService` with limits from new `minor_card_limits` table. New `MinorCardRequest` model tracks the approval workflow. Card-to-minor linking via `minor_account_uuid` on the cards table.

**Tech Stack:** Laravel 12, Spatie Event Sourcing, Filament v3, Sanctum auth, existing CardIssuance domain.

---

## CRITICAL IMPLEMENTATION RULES (No Guessing)

### 1. Authorization Pattern - User + Account

`MinorAccountAccessService` takes `User + Account` pairs. CORRECT:

```php
$user = $request->user(); // App\Models\User
$minorAccount = Account::where('uuid', $minorUuid)->firstOrFail();
$this->accessService->authorizeGuardian($user, $minorAccount); // throws AuthorizationException
```

WRONG (will fail):
```php
$this->accessService->hasGuardianAccess($user->account, $minorAccount); // uses Account, not User
```

### 2. Card Token Column

The `cards` table uses `issuer_card_token` (NOT `card_token`):
```php
Card::where('issuer_card_token', $cardToken) // CORRECT
```

### 3. Tier Verification

Use `$account->tier` - ALREADY EXISTS on accounts table:
```php
if ($account->tier !== 'rise') {
    throw new \InvalidArgumentException('Virtual cards are only available for Rise tier');
}
```

### 4. Age Derivation

Get from `UserProfile`, NOT from accounts:
```php
$profile = \App\Domain\User\Models\UserProfile::query()
    ->where('user_id', $account->user_id)
    ->first();
$age = $profile?->date_of_birth 
    ? now()->diffInYears($profile->date_of_birth) 
    : null;
```

### 5. Limits Storage

Create NEW `minor_card_limits` table - limits are NOT stored on accounts table.

### 6. User Identification

Use `$user->uuid` for user identification, NOT `$user->account->uuid`:
```php
// In MinorCardRequestService:
'requested_by_user_uuid' => $requester->uuid, // CORRECT - User's uuid
```

### 7. Exception Pattern

DO NOT use `BusinessException` - it doesn't exist. Use:
- `\InvalidArgumentException` for validation errors
- `\RuntimeException` for operational errors

---

## File Structure

- `app/Domain/Account/Models/MinorCardRequest.php` — request + approval state
- `app/Domain/Account/Models/MinorCardLimit.php` — card limits (NEW TABLE)
- `app/Domain/Account/Constants/MinorCardConstants.php` — status + limit constants
- `app/Domain/Account/Services/MinorCardRequestService.php` — request logic
- `app/Domain/Account/Services/MinorCardService.php` — card creation with limits
- `app/Http/Controllers/Api/Account/MinorCardController.php` — API endpoints
- `app/Filament/Admin/Resources/MinorCardRequestResource.php` — Filament management
- `app/Console/Commands/ExpireMinorCardRequests.php` — scheduled expiry
- `database/migrations/tenant/2026_04_24_xxxxxx_create_minor_card_limits_table.php`
- `database/migrations/tenant/2026_04_24_xxxxxx_create_minor_card_requests_table.php`
- `database/migrations/tenant/2026_04_24_xxxxxx_add_minor_account_uuid_to_cards_table.php`

---

## Task Breakdown

### Phase 12.1: Baseline & Guardrails

- [ ] **12.1.1** Verify CardIssuance domain is healthy
  - Run: `./vendor/bin/pest --filter=CardIssuance`

- [ ] **12.1.2** Define minor card constants
  - File: `app/Domain/Account/Constants/MinorCardConstants.php`
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Domain\Account\Constants;

  final class MinorCardConstants
  {
      public const REQUEST_EXPIRY_HOURS = 72;
      public const DEFAULT_DAILY_LIMIT = '2000.00';
      public const DEFAULT_MONTHLY_LIMIT = '10000.00';
      public const DEFAULT_SINGLE_TRANSACTION_LIMIT = '1500.00';
      public const REQUEST_TYPE_PARENT_INITIATED = 'parent_initiated';
      public const REQUEST_TYPE_CHILD_REQUESTED = 'child_requested';
      public const STATUS_PENDING_APPROVAL = 'pending_approval';
      public const STATUS_APPROVED = 'approved';
      public const STATUS_DENIED = 'denied';
      public const STATUS_CARD_CREATED = 'card_created';
      public const STATUS_EXPIRED = 'expired';
  }
  ```

### Phase 12.2: Data Model

- [ ] **12.2.1** Create `minor_card_limits` table (NEW)
  - File: `database/migrations/tenant/2026_04_24_xxxxxx_create_minor_card_limits_table.php`
  ```php
  <?php

  declare(strict_types=1);

  use Illuminate\Database\Migrations\Migration;
  use Illuminate\Database\Schema\Blueprint;
  use Illuminate\Support\Facades\Schema;

  return new class extends Migration
  {
      public function up(): void
      {
          Schema::create('minor_card_limits', function (Blueprint $table) {
              $table->uuid('id')->primary();
              $table->uuid('tenant_id')->nullable();
              $table->uuid('minor_account_uuid')->unique();
              $table->decimal('daily_limit', 12, 2)->default(2000.00);
              $table->decimal('monthly_limit', 12, 2)->default(10000.00);
              $table->decimal('single_transaction_limit', 12, 2)->default(1500.00);
              $table->boolean('is_active')->default(true);
              $table->timestamps();

              $table->foreign('minor_account_uuid')
                  ->references('uuid')
                  ->on('accounts')
                  ->onDelete('cascade');
              $table->index(['tenant_id', 'minor_account_uuid']);
          });
      }

      public function down(): void
      {
          Schema::dropIfExists('minor_card_limits');
      }
  };
  ```

- [ ] **12.2.2** Create `minor_card_requests` table
  - File: `database/migrations/tenant/2026_04_24_xxxxxx_create_minor_card_requests_table.php`
  ```php
  <?php

  declare(strict_types=1);

  use Illuminate\Database\Migrations\Migration;
  use Illuminate\Database\Schema\Blueprint;
  use Illuminate\Support\Facades\Schema;

  return new class extends Migration
  {
      public function up(): void
      {
          Schema::create('minor_card_requests', function (Blueprint $table) {
              $table->uuid('id')->primary();
              $table->uuid('tenant_id')->nullable();
              $table->uuid('minor_account_uuid');
              $table->uuid('requested_by_user_uuid');
              $table->string('request_type');
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

              $table->foreign('minor_account_uuid')
                  ->references('uuid')
                  ->on('accounts')
                  ->onDelete('cascade');

              $table->index(['minor_account_uuid', 'status']);
              $table->index(['status', 'expires_at']);
          });
      }

      public function down(): void
      {
          Schema::dropIfExists('minor_card_requests');
      }
  };
  ```

- [ ] **12.2.3** Add `minor_account_uuid` to `cards` table
  - File: `database/migrations/tenant/2026_04_24_xxxxxx_add_minor_account_uuid_to_cards_table.php`
  ```php
  <?php

  declare(strict_types=1);

  use Illuminate\Database\Migrations\Migration;
  use Illuminate\Database\Schema\Blueprint;
  use Illuminate\Support\Facades\Schema;

  return new class extends Migration
  {
      public function up(): void
      {
          Schema::table('cards', function (Blueprint $table) {
              $table->uuid('minor_account_uuid')->nullable()->after('user_id');
              $table->foreign('minor_account_uuid')
                    ->references('uuid')
                    ->on('accounts')
                    ->onDelete('cascade');
              $table->index(['minor_account_uuid', 'status']);
          });
      }

      public function down(): void
      {
          Schema::table('cards', function (Blueprint $table) {
              $table->dropForeign(['minor_account_uuid']);
              $table->dropColumn('minor_account_uuid');
          });
      }
  };
  ```

- [ ] **12.2.4** Create `MinorCardLimit` model
  - File: `app/Domain/Account/Models/MinorCardLimit.php`
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Domain\Account\Models;

  use App\Domain\Shared\Traits\UsesTenantConnection;
  use Illuminate\Database\Eloquent\Concerns\HasUuids;
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;

  class MinorCardLimit extends Model
  {
      use HasUuids, UsesTenantConnection;

      protected $table = 'minor_card_limits';

      protected $fillable = [
          'minor_account_uuid',
          'daily_limit',
          'monthly_limit',
          'single_transaction_limit',
          'is_active',
      ];

      protected $casts = [
          'daily_limit' => 'decimal:2',
          'monthly_limit' => 'decimal:2',
          'single_transaction_limit' => 'decimal:2',
          'is_active' => 'boolean',
      ];

      public function account(): BelongsTo
      {
          return $this->belongsTo(Account::class, 'minor_account_uuid');
      }
  }
  ```

- [ ] **12.2.5** Create `MinorCardRequest` model
  - File: `app/Domain/Account/Models/MinorCardRequest.php`
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Domain\Account\Models;

  use App\Domain\Shared\Traits\UsesTenantConnection;
  use Illuminate\Database\Eloquent\Concerns\HasUuids;
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;

  class MinorCardRequest extends Model
  {
      use HasUuids, UsesTenantConnection;

      protected $table = 'minor_card_requests';

      protected $fillable = [
          'minor_account_uuid',
          'requested_by_user_uuid',
          'request_type',
          'status',
          'requested_network',
          'requested_daily_limit',
          'requested_monthly_limit',
          'requested_single_limit',
          'denial_reason',
          'approved_by_user_uuid',
          'approved_at',
          'expires_at',
      ];

      protected $casts = [
          'requested_daily_limit' => 'decimal:2',
          'requested_monthly_limit' => 'decimal:2',
          'requested_single_limit' => 'decimal:2',
          'approved_at' => 'datetime',
          'expires_at' => 'datetime',
      ];

      public function minorAccount(): BelongsTo
      {
          return $this->belongsTo(Account::class, 'minor_account_uuid');
      }

      public function isPending(): bool
      {
          return $this->status === MinorCardConstants::STATUS_PENDING_APPROVAL;
      }

      public function canBeApproved(): bool
      {
          return $this->isPending() 
              && ($this->expires_at === null || $this->expires_at->isFuture());
      }
  }
  ```

- [ ] **12.2.6** Add `minor_account_uuid` to `Card` model
  - File: `app/Domain/CardIssuance/Models/Card.php` - add to `$fillable`
  - Add relationship: `minorAccount()`

### Phase 12.3: Domain Services

- [ ] **12.3.1** Create `MinorCardRequestService`
  - File: `app/Domain/Account/Services/MinorCardRequestService.php`
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Domain\Account\Services;

  use App\Domain\Account\Constants\MinorCardConstants;
  use App\Domain\Account\Models\Account;
  use App\Domain\Account\Models\MinorCardRequest;
  use App\Models\User;
  use Illuminate\Support\Facades\DB;

  class MinorCardRequestService
  {
      public function __construct(
          private readonly MinorAccountAccessService $accessService,
      ) {}

      public function createRequest(User $requester, Account $minor, string $network, ?array $limits): MinorCardRequest
      {
          $this->guardCanRequest($requester, $minor);

          // CRITICAL: Use account->tier NOT date_of_birth on accounts
          if ($minor->tier !== 'rise') {
              throw new \InvalidArgumentException('Virtual cards are only available for Rise tier (ages 13+)');
          }

          $hasActiveCard = $this->minorHasActiveCard($minor);
          if ($hasActiveCard) {
              throw new \InvalidArgumentException('Minor already has an active virtual card');
          }

          $hasPendingRequest = MinorCardRequest::where('minor_account_uuid', $minor->uuid)
              ->where('status', MinorCardConstants::STATUS_PENDING_APPROVAL)
              ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
              ->exists();
          if ($hasPendingRequest) {
              throw new \InvalidArgumentException('A pending card request already exists');
          }

          $requestType = $this->accessService->hasGuardianAccess($requester, $minor)
              ? MinorCardConstants::REQUEST_TYPE_PARENT_INITIATED
              : MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED;

          return MinorCardRequest::create([
              'minor_account_uuid' => $minor->uuid,
              'requested_by_user_uuid' => $requester->uuid, // CRITICAL: use User's uuid
              'request_type' => $requestType,
              'status' => MinorCardConstants::STATUS_PENDING_APPROVAL,
              'requested_network' => $network,
              'requested_daily_limit' => $limits['daily'] ?? null,
              'requested_monthly_limit' => $limits['monthly'] ?? null,
              'requested_single_limit' => $limits['single_transaction'] ?? null,
              'expires_at' => now()->addHours(MinorCardConstants::REQUEST_EXPIRY_HOURS),
          ]);
      }

      public function approve(User $guardian, MinorCardRequest $request): MinorCardRequest
      {
          // Authorization happens at controller layer (authorizeGuardian)

          if (! $request->canBeApproved()) {
              throw new \InvalidArgumentException('Request cannot be approved in its current state');
          }

          $request->update([
              'status' => MinorCardConstants::STATUS_APPROVED,
              'approved_by_user_uuid' => $guardian->uuid,
              'approved_at' => now(),
          ]);

          return $request->refresh();
      }

      public function deny(User $guardian, MinorCardRequest $request, string $reason): MinorCardRequest
      {
          if (! $request->canBeApproved()) {
              throw new \InvalidArgumentException('Request cannot be denied in its current state');
          }

          $request->update([
              'status' => MinorCardConstants::STATUS_DENIED,
              'denial_reason' => $reason,
          ]);

          return $request->refresh();
      }

      private function guardCanRequest(User $requester, Account $minor): void
      {
          $isMinor = $requester->uuid === $minor->user_uuid;
          $isGuardian = $this->accessService->hasGuardianAccess($requester, $minor);

          if (! $isMinor && ! $isGuardian) {
              throw new \InvalidArgumentException('Only the minor or their guardian can request a card');
          }
      }

      private function minorHasActiveCard(Account $minor): bool
      {
          return DB::table('cards')
              ->where('minor_account_uuid', $minor->uuid)
              ->whereIn('status', ['active', 'frozen'])
              ->exists();
      }
  }
  ```

- [ ] **12.3.2** Create `MinorCardService`
  - File: `app/Domain/Account/Services/MinorCardService.php`
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Domain\Account\Services;

  use App\Domain\Account\Constants\MinorCardConstants;
  use App\Domain\Account\Models\Account;
  use App\Domain\Account\Models\MinorCardLimit;
  use App\Domain\Account\Models\MinorCardRequest;
  use App\Domain\CardIssuance\Enums\CardNetwork;
  use App\Domain\CardIssuance\Models\Card;
  use App\Domain\CardIssuance\Services\CardProvisioningService;
  use App\Models\User;
  use Illuminate\Support\Facades\DB;

  class MinorCardService
  {
      public function __construct(
          private readonly CardProvisioningService $cardProvisioning,
          private readonly MinorAccountAccessService $accessService,
      ) {}

      public function createCardFromRequest(MinorCardRequest $request): Card
      {
          return DB::transaction(function () use ($request) {
              $minor = Account::where('uuid', $request->minor_account_uuid)->firstOrFail();
              $limits = $this->resolveLimits($request, $minor);

              $network = $request->requested_network === 'mastercard'
                  ? CardNetwork::MASTERCARD
                  : CardNetwork::VISA;

              $card = $this->cardProvisioning->createCard(
                  userId: $minor->user_uuid,
                  cardholderName: $minor->name,
                  metadata: [
                      'minor_account_uuid' => $minor->uuid,
                      'card_request_id' => $request->uuid,
                      'tier' => 'rise',
                  ],
                  network: $network,
              );

              $this->cardProvisioning->updateSpendingLimits($card->cardToken, $limits);

              // CRITICAL: Use issuer_card_token
              $persistedCard = Card::where('issuer_card_token', $card->cardToken)->first();
              $persistedCard->update(['minor_account_uuid' => $minor->uuid]);

              $request->update(['status' => MinorCardConstants::STATUS_CARD_CREATED]);

              return $persistedCard;
          });
      }

      public function freezeCard(User $guardian, Card $card): Card
      {
          $minor = $card->minorAccount;
          if ($minor && ! $this->accessService->hasGuardianAccess($guardian, $minor)) {
              throw new \InvalidArgumentException('Only guardians can freeze a minor card');
          }

          $this->cardProvisioning->freezeCard($card->issuer_card_token); // CRITICAL: use issuer_card_token
          return $card->refresh();
      }

      public function unfreezeCard(User $guardian, Card $card): Card
      {
          $minor = $card->minorAccount;
          if ($minor && ! $this->accessService->hasGuardianAccess($guardian, $minor)) {
              throw new \InvalidArgumentException('Only guardians can unfreeze a minor card');
          }

          $this->cardProvisioning->unfreezeCard($card->issuer_card_token);
          return $card->refresh();
      }

      public function listMinorCards(Account $minor): array
      {
          $tokens = Card::where('minor_account_uuid', $minor->uuid)
              ->whereNotIn('status', ['cancelled'])
              ->pluck('issuer_card_token')
              ->toArray();

          return array_map(
              fn ($token) => $this->cardProvisioning->getCard($token),
              $tokens
          );
      }

      private function resolveLimits(MinorCardRequest $request, Account $minor): array
      {
          $limitRecord = MinorCardLimit::where('minor_account_uuid', $minor->uuid)->first();
          
          $defaultDaily = MinorCardConstants::DEFAULT_DAILY_LIMIT;
          $defaultMonthly = MinorCardConstants::DEFAULT_MONTHLY_LIMIT;
          $defaultSingle = MinorCardConstants::DEFAULT_SINGLE_TRANSACTION_LIMIT;

          $requestedDaily = $request->requested_daily_limit ?? $defaultDaily;
          $requestedMonthly = $request->requested_monthly_limit ?? $defaultMonthly;
          $requestedSingle = $request->requested_single_limit ?? $defaultSingle;

          $accountDaily = $limitRecord?->daily_limit ?? $defaultDaily;
          $accountMonthly = $limitRecord?->monthly_limit ?? $defaultMonthly;
          $accountSingle = $limitRecord?->single_transaction_limit ?? $defaultSingle;

          return [
              'daily' => min((float) $requestedDaily, (float) $accountDaily),
              'monthly' => min((float) $requestedMonthly, (float) $accountMonthly),
              'single_transaction' => min((float) $requestedSingle, (float) $accountSingle),
          ];
      }
  }
  ```

### Phase 12.4: API Contracts

- [ ] **12.4.1** Add minor card routes
- [ ] **12.4.2** Create `MinorCardController` with CORRECT patterns:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Http\Controllers\Api\Account;

  use App\Domain\Account\Models\Account;
  use App\Domain\Account\Models\MinorCardRequest;
  use App\Domain\Account\Services\MinorAccountAccessService;
  use App\Domain\Account\Services\MinorCardRequestService;
  use App\Domain\Account\Services\MinorCardService;
  use App\Domain\CardIssuance\Enums\WalletType;
  use App\Domain\CardIssuance\Services\CardProvisioningService;
  use App\Http\Controllers\Controller;
  use App\Models\User;
  use Illuminate\Http\JsonResponse;
  use Illuminate\Http\Request;
  use Illuminate\Support\Facades\Auth;

  class MinorCardController extends Controller
  {
      public function __construct(
          private readonly MinorAccountAccessService $accessService,
          private readonly MinorCardRequestService $requestService,
          private readonly MinorCardService $cardService,
          private readonly CardProvisioningService $cardProvisioning,
      ) {}

      public function createRequest(Request $request): JsonResponse
      {
          $validated = $request->validate([
              'minor_account_uuid' => 'required_without:self_request|uuid|exists:accounts,uuid',
              'network' => 'in:visa,mastercard',
              'requested_limits' => 'nullable|array',
          ]);

          /** @var User $user */
          $user = $request->user();

          $minorUuid = $validated['minor_account_uuid'] ?? null;
          $minor = $minorUuid 
              ? Account::where('uuid', $minorUuid)->firstOrFail()
              : Account::where('user_uuid', $user->uuid)->where('type', 'minor')->firstOrFail();

          // CRITICAL: authorizeGuardian takes User + Account
          $this->accessService->authorizeGuardian($user, $minor);

          $result = $this->requestService->createRequest(
              $user,
              $minor,
              $validated['network'] ?? 'visa',
              $validated['requested_limits'] ?? null,
          );

          return response()->json($result, 201);
      }

      public function approveRequest(string $id): JsonResponse
      {
          /** @var User $user */
          $user = Auth::user();
          $minorCardRequest = MinorCardRequest::where('uuid', $id)->firstOrFail();
          $minorAccount = $minorCardRequest->minorAccount;

          // CRITICAL: authorizeGuardian takes User + Account, throws if not guardian
          $this->accessService->authorizeGuardian($user, $minorAccount);

          $card = $this->cardService->createCardFromRequest($minorCardRequest);
          return response()->json(['request' => $minorCardRequest->refresh(), 'card' => $card]);
      }

      public function denyRequest(Request $request, string $id): JsonResponse
      {
          $validated = $request->validate(['reason' => 'required|string|max:500']);

          $user = Auth::user();
          $minorCardRequest = MinorCardRequest::where('uuid', $id)->firstOrFail();
          $minorAccount = $minorCardRequest->minorAccount;

          // CRITICAL: authorizeGuardian takes User + Account
          $this->accessService->authorizeGuardian($user, $minorAccount);

          $result = $this->requestService->deny($user, $minorCardRequest, $validated['reason']);
          return response()->json($result);
      }

      public function freeze(string $cardId): JsonResponse
      {
          $user = Auth::user();

          $card = $this->cardProvisioning->getCard($cardId);
          abort_unless($card, 404);

          if ($card->minor_account_uuid) {
              $minorAccount = Account::where('uuid', $card->minor_account_uuid)->firstOrFail();
              // CRITICAL: authorizeGuardian takes User + Account
              $this->accessService->authorizeGuardian($user, $minorAccount);
          }

          $result = $this->cardService->freezeCard($user, $card);
          return response()->json($result);
      }

      public function unfreeze(string $cardId): JsonResponse
      {
          $user = Auth::user();

          $card = $this->cardProvisioning->getCard($cardId);
          abort_unless($card, 404);

          if ($card->minor_account_uuid) {
              $minorAccount = Account::where('uuid', $card->minor_account_uuid)->firstOrFail();
              $this->accessService->authorizeGuardian($user, $minorAccount);
          }

          $result = $this->cardService->unfreezeCard($user, $card);
          return response()->json($result);
      }

      public function provision(Request $request, string $cardId): JsonResponse
      {
          $validated = $request->validate([
              'wallet_type' => 'required|in:apple_pay,google_pay',
              'device_id' => 'required|string',
          ]);

          /** @var User $user */
          $user = Auth::user();

          $card = $this->cardProvisioning->getCard($cardId);
          abort_unless($card, 404);

          // Pass userId to getProvisioningData - CORRECT signature
          $provisioningData = $this->cardProvisioning->getProvisioningData(
              userId: $user->uuid,
              cardToken: $card->cardToken,
              walletType: WalletType::from($validated['wallet_type']),
              deviceId: $validated['device_id'],
              certificates: [],
          );

          return response()->json($provisioningData);
      }

      // ... other methods with correct User + Account patterns
  }
  ```

### Phase 12.5: Filament Workflows

- [ ] **12.5.1** Create `MinorCardRequestResource` with guardian authorization in actions

### Phase 12.6: JIT Funding Integration

- [ ] **12.6.1** Extend `JitFundingService` - read limits from `MinorCardLimit` table

### Phase 12.7: Scheduled Job

- [ ] **12.7.1** Create `ExpireMinorCardRequests` command

### Phase 12.8: Tests

- [ ] **12.8.1** Unit tests with CORRECT patterns (User + Account, issuer_card_token)
- [ ] **12.8.2** Integration tests verifying 403 for non-guardians

---

## Stop/Go Gates

| Gate | Criteria |
|------|----------|
| 12.A | Migrations pass - minor_card_limits, minor_card_requests, cards.minor_account_uuid |
| 12.B | Uses `authorizeGuardian(User, Account)` - not Account-only |
| 12.C | Uses `issuer_card_token` - not card_token |
| 12.D | Uses `$account->tier` - not date_of_birth |
| 12.E | Uses `$user->uuid` - not account->uuid |

---

## Definition of Done

- [ ] All guardian endpoints require `authorizeGuardian(User, Account)`
- [ ] Card token queries use `issuer_card_token`
- [ ] Tier verification uses `$account->tier`
- [ ] Limits read from `minor_card_limits` table
- [ ] No use of `BusinessException` - use `InvalidArgumentException`
- [ ] All tests pass + static analysis clean