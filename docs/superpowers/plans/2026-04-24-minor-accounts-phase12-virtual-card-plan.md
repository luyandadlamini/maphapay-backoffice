# Minor Accounts Phase 12 Virtual Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable Rise tier (ages 13+) minor accounts to request and use a virtual card, with parent approval workflow, spending limit enforcement, and independent card freeze.

**Architecture:** Extend the existing CardIssuance domain with minor-specific request/approval flow. Card issuance through existing `CardProvisioningService` with minor account limits baked in. New `MinorCardRequest` model tracks the approval workflow. Card-to-minor linking via `minor_account_uuid` on the cards table.

**Tech Stack:** Laravel 12, Spatie Event Sourcing, Filament v3, Sanctum auth, existing CardIssuance domain.

---

## File Structure

- `app/Domain/Account/Models/MinorCardRequest.php` — request + approval state tracking (uses `UsesTenantConnection`)
- `app/Domain/Account/Constants/MinorCardConstants.php` — status + limit constants
- `app/Domain/Account/Services/MinorCardRequestService.php` — request/approve/deny logic
- `app/Domain/Account/Services/MinorCardService.php` — card creation with minor limits
- `app/Http/Controllers/Api/Account/MinorCardController.php` — API endpoints
- `app/Filament/Admin/Resources/MinorCardRequestResource.php` — Filament management
- `app/Console/Commands/ExpireMinorCardRequests.php` — scheduled expiry job
- `database/migrations/tenant/2026_04_24_xxxxxx_add_minor_account_uuid_to_cards_table.php`
- `database/migrations/tenant/2026_04_24_xxxxxx_create_minor_card_requests_table.php`
- `tests/Unit/Domain/Account/Services/MinorCardRequestServiceTest.php`
- `tests/Unit/Domain/Account/Services/MinorCardServiceTest.php`
- `tests/Feature/Http/Controllers/Api/MinorCardControllerTest.php`
- `tests/Feature/Filament/MinorCardRequestResourceTest.php`
- `routes/api.php` — minor card routes

---

## CRITICAL PATTERNS (Read First)

### Authorization Pattern

`MinorAccountAccessService` operates on `User + Account` pairs. NEVER pass Account objects alone.

Correct:
```php
$user = $request->user(); // App\Models\User
$minorAccount = Account::where('uuid', $minorUuid)->firstOrFail();
$this->accessService->authorizeGuardian($user, $minorAccount); // throws if not guardian
```

Incorrect:
```php
$guardian = Auth::user()->account;
$minorAccount = Account::where('uuid', $minorUuid)->firstOrFail();
$this->accessService->isGuardianOf($guardian, $minorAccount); // WRONG - uses Account instead of User
```

### Card Token Column

The `cards` table uses `issuer_card_token` (NOT `card_token`).
- Correct: `Card::where('issuer_card_token', $card->cardToken)`
- Wrong: `Card::where('card_token', $card->cardToken)`

### Card Link Column

Use `$card->minor_account_uuid` (DB column) for queries, not `$card->metadata['minor_account_uuid']`.

---

## Task Breakdown

### Phase 12.1: Baseline & Guardrails

- [ ] **12.1.1** Verify CardIssuance domain is healthy
  - Run: `./vendor/bin/pest --filter=CardIssuance`
  - Verify: `CardProvisioningService`, `VirtualCard`, `CardIssuerInterface` all pass

- [ ] **12.1.2** Verify minor account infrastructure
  - Check `app/Domain/Account/Services/MinorAccountAccessService.php` has guardian validation
  - Check existing controllers for correct `User + Account` pattern (see `MinorSpendApprovalController.php`)
  - Run: `./vendor/bin/pest --filter=MinorAccount`

- [ ] **12.1.3** Define minor card constants
  - File: `app/Domain/Account/Constants/MinorCardConstants.php`
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Domain\Account\Constants;

  final class MinorCardConstants
  {
      public const MIN_AGE_FOR_CARD = 13;
      public const REQUEST_EXPIRY_HOURS = 72;
      public const DEFAULT_DAILY_LIMIT = 2000.00;
      public const DEFAULT_MONTHLY_LIMIT = 10000.00;
      public const DEFAULT_SINGLE_TRANSACTION_LIMIT = 1500.00;
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

- [ ] **12.2.1** Create migration for `minor_card_requests` table
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
              $table->uuid('requested_by_account_uuid');
              $table->string('request_type');
              $table->string('status')->default('pending_approval');
              $table->string('requested_network')->default('visa');
              $table->json('requested_limits')->nullable();
              $table->text('denial_reason')->nullable();
              $table->uuid('approved_by')->nullable();
              $table->timestamp('approved_at')->nullable();
              $table->timestamp('expires_at')->nullable();
              $table->timestamps();

              $table->foreign('minor_account_uuid')->references('uuid')->on('accounts')->onDelete('cascade');
              $table->foreign('requested_by_account_uuid')->references('uuid')->on('accounts')->onDelete('cascade');
              $table->foreign('approved_by')->references('uuid')->on('accounts')->onDelete('set null');

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

- [ ] **12.2.2** Create migration to add `minor_account_uuid` to `cards` table
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

- [ ] **12.2.3** Create `MinorCardRequest` model (uses `UsesTenantConnection`, NOT `TenantTrait`)
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
          'requested_by_account_uuid',
          'request_type',
          'status',
          'requested_network',
          'requested_limits',
          'denial_reason',
          'approved_by',
          'approved_at',
          'expires_at',
      ];

      protected $casts = [
          'requested_limits' => 'array',
          'approved_at' => 'datetime',
          'expires_at' => 'datetime',
      ];

      public function minorAccount(): BelongsTo
      {
          return $this->belongsTo(Account::class, 'minor_account_uuid');
      }

      public function requestedBy(): BelongsTo
      {
          return $this->belongsTo(Account::class, 'requested_by_account_uuid');
      }

      public function approvedByAccount(): BelongsTo
      {
          return $this->belongsTo(Account::class, 'approved_by');
      }

      public function isPending(): bool
      {
          return $this->status === MinorCardConstants::STATUS_PENDING_APPROVAL;
      }

      public function isExpired(): bool
      {
          return $this->expires_at !== null && $this->expires_at->isPast();
      }

      public function canBeApproved(): bool
      {
          return $this->isPending() && ! $this->isExpired();
      }
  }
  ```

- [ ] **12.2.4** Add `minor_account_uuid` to `Card` model fillable
  - File: `app/Domain/CardIssuance/Models/Card.php`
  - Add `'minor_account_uuid'` to `$fillable` array
  - Add relationship:
  ```php
  public function minorAccount(): BelongsTo
  {
      return $this->belongsTo(\App\Domain\Account\Models\Account::class, 'minor_account_uuid');
  }
  ```

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
  use App\Exceptions\BusinessException;
  use App\Models\User;
  use Illuminate\Support\Facades\DB;

  class MinorCardRequestService
  {
      public function __construct(
          private readonly MinorAccountAccessService $accessService,
      ) {}

      /**
       * Create a new card request.
       *
       * @param User $requester - The authenticated User making the request
       * @param Account $minor - The minor account to receive the card
       */
      public function createRequest(User $requester, Account $minor, string $network, ?array $limits): MinorCardRequest
      {
          $this->guardCanRequest($requester, $minor);

          $tier = $this->getMinorTier($minor);
          if ($tier !== 'rise') {
              throw BusinessException::withMessage('Virtual cards are only available for Rise tier (ages 13+)');
          }

          $hasActiveCard = $this->minorHasActiveCard($minor);
          if ($hasActiveCard) {
              throw BusinessException::withMessage('Minor already has an active virtual card');
          }

          $hasPendingRequest = MinorCardRequest::where('minor_account_uuid', $minor->uuid)
              ->where('status', MinorCardConstants::STATUS_PENDING_APPROVAL)
              ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
              ->exists();
          if ($hasPendingRequest) {
              throw BusinessException::withMessage('A pending card request already exists for this minor');
          }

          $requestType = $this->accessService->hasGuardianAccess($requester, $minor)
              ? MinorCardConstants::REQUEST_TYPE_PARENT_INITIATED
              : MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED;

          return MinorCardRequest::create([
              'minor_account_uuid' => $minor->uuid,
              'requested_by_account_uuid' => $requester->account->uuid,
              'request_type' => $requestType,
              'status' => MinorCardConstants::STATUS_PENDING_APPROVAL,
              'requested_network' => $network,
              'requested_limits' => $limits,
              'expires_at' => now()->addHours(MinorCardConstants::REQUEST_EXPIRY_HOURS),
          ]);
      }

      /**
       * Approve a card request.
       *
       * CRITICAL: Caller MUST guard with MinorAccountAccessService::hasGuardianAccess() before calling.
       *
       * @param User $guardian - The authenticated User approving
       * @param MinorCardRequest $request - The request to approve
       */
      public function approve(User $guardian, MinorCardRequest $request): MinorCardRequest
      {
          // Note: Authorization check happens at controller layer via authorizeGuardian()

          if (! $request->canBeApproved()) {
              throw BusinessException::withMessage('Request cannot be approved in its current state');
          }

          $request->update([
              'status' => MinorCardConstants::STATUS_APPROVED,
              'approved_by' => $guardian->account->uuid,
              'approved_at' => now(),
          ]);

          return $request->refresh();
      }

      /**
       * Deny a card request.
       *
       * CRITICAL: Caller MUST guard with MinorAccountAccessService::hasGuardianAccess() before calling.
       */
      public function deny(User $guardian, MinorCardRequest $request, string $reason): MinorCardRequest
      {
          // Note: Authorization check happens at controller layer via authorizeGuardian()

          if (! $request->canBeApproved()) {
              throw BusinessException::withMessage('Request cannot be denied in its current state');
          }

          $request->update([
              'status' => MinorCardConstants::STATUS_DENIED,
              'denial_reason' => $reason,
          ]);

          return $request->refresh();
      }

      private function guardCanRequest(User $requester, Account $minor): void
      {
          $isMinor = $requester->account->uuid === $minor->uuid;
          $isGuardian = $this->accessService->hasGuardianAccess($requester, $minor);

          if (! $isMinor && ! $isGuardian) {
              throw BusinessException::withMessage('Only the minor or their guardian can request a card');
          }
      }

      private function minorHasActiveCard(Account $minor): bool
      {
          return DB::table('cards')
              ->where('minor_account_uuid', $minor->uuid)
              ->whereIn('status', ['active', 'frozen'])
              ->exists();
      }

      private function getMinorTier(Account $minor): string
      {
          $dob = $minor->date_of_birth;
          if (! $dob) {
              return 'grow';
          }
          $age = now()->diffInYears($dob);
          return $age >= MinorCardConstants::MIN_AGE_FOR_CARD ? 'rise' : 'grow';
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

      /**
       * Create a card from an approved request.
       *
       * Wrapped in DB transaction to prevent race conditions.
       */
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
                  cardholderName: $minor->full_name ?? $minor->name,
                  metadata: [
                      'minor_account_uuid' => $minor->uuid,
                      'card_request_id' => $request->uuid,
                      'tier' => 'rise',
                  ],
                  network: $network,
              );

              $this->cardProvisioning->updateSpendingLimits($card->cardToken, $limits);

              $persistedCard = Card::where('issuer_card_token', $card->cardToken)->first();
              $persistedCard->update(['minor_account_uuid' => $minor->uuid]);

              $request->update(['status' => MinorCardConstants::STATUS_CARD_CREATED]);

              return $persistedCard;
          });
      }

      /**
       * Freeze a minor's card. Guardian authorization check must happen before calling.
       */
      public function freezeCard(User $guardian, Card $card): Card
      {
          $minor = $card->minorAccount;
          if ($minor && ! $this->accessService->hasGuardianAccess($guardian, $minor)) {
              throw \App\Exceptions\BusinessException::withMessage('Only guardians can freeze a minor card');
          }

          $this->cardProvisioning->freezeCard($card->issuer_card_token);
          return $card->refresh();
      }

      /**
       * Unfreeze a minor's card. Guardian authorization check must happen before calling.
       */
      public function unfreezeCard(User $guardian, Card $card): Card
      {
          $minor = $card->minorAccount;
          if ($minor && ! $this->accessService->hasGuardianAccess($guardian, $minor)) {
              throw \App\Exceptions\BusinessException::withMessage('Only guardians can unfreeze a minor card');
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
          $requestedLimits = $request->requested_limits ?? [];
          $accountDailyLimit = $minor->daily_limit ?? MinorCardConstants::DEFAULT_DAILY_LIMIT;
          $accountMonthlyLimit = $minor->monthly_limit ?? MinorCardConstants::DEFAULT_MONTHLY_LIMIT;
          $accountSingleLimit = $minor->single_transaction_limit ?? MinorCardConstants::DEFAULT_SINGLE_TRANSACTION_LIMIT;

          return [
              'daily' => min($requestedLimits['daily'] ?? $accountDailyLimit, $accountDailyLimit),
              'monthly' => min($requestedLimits['monthly'] ?? $accountMonthlyLimit, $accountMonthlyLimit),
              'single_transaction' => min($requestedLimits['single_transaction'] ?? $accountSingleLimit, $accountSingleLimit),
          ];
      }
  }
  ```

### Phase 12.4: API Contracts

- [ ] **12.4.1** Add minor card routes
  - File: `routes/api.php`
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

- [ ] **12.4.2** Create `MinorCardController` (CRITICAL: uses User + Account pattern)
  - File: `app/Http/Controllers/Api/Account/MinorCardController.php`
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

      public function listRequests(Request $request): JsonResponse
      {
          /** @var User $user */
          $user = $request->user();

          $query = MinorCardRequest::query();

          if ($user->account->isMinor()) {
              $query->where('minor_account_uuid', $user->account->uuid);
          } else {
              $guardianMinors = $this->getGuardianMinors($user);
              $query->whereIn('minor_account_uuid', $guardianMinors->pluck('uuid'));
          }

          return response()->json($query->latest()->paginate());
      }

      public function createRequest(Request $request): JsonResponse
      {
          $validated = $request->validate([
              'minor_account_uuid' => 'required_without:self_request|uuid|exists:accounts,uuid',
              'network' => 'in:visa,mastercard',
              'requested_limits' => 'nullable|array',
              'requested_limits.daily' => 'nullable|numeric|min:0',
              'requested_limits.monthly' => 'nullable|numeric|min:0',
              'requested_limits.single_transaction' => 'nullable|numeric|min:0',
          ]);

          /** @var User $user */
          $user = $request->user();

          $minorUuid = $validated['minor_account_uuid'] ?? $user->account->uuid;
          $minor = Account::where('uuid', $minorUuid)->firstOrFail();

          $result = $this->requestService->createRequest(
              $user,
              $minor,
              $validated['network'] ?? 'visa',
              $validated['requested_limits'] ?? null,
          );

          return response()->json($result, 201);
      }

      public function showRequest(string $id): JsonResponse
      {
          $request = MinorCardRequest::where('uuid', $id)->firstOrFail();
          $this->guardViewAccess($request);
          return response()->json($request);
      }

      /**
       * Approve a card request. CRITICAL: requires guardian authorization.
       */
      public function approveRequest(string $id): JsonResponse
      {
          /** @var User $user */
          $user = Auth::user();
          $minorCardRequest = MinorCardRequest::where('uuid', $id)->firstOrFail();
          $minorAccount = $minorCardRequest->minorAccount;

          // CRITICAL: Authorize that caller is a guardian of this minor
          $this->accessService->authorizeGuardian($user, $minorAccount);

          $card = $this->cardService->createCardFromRequest($minorCardRequest);
          return response()->json(['request' => $minorCardRequest->refresh(), 'card' => $card]);
      }

      /**
       * Deny a card request. CRITICAL: requires guardian authorization.
       */
      public function denyRequest(Request $request, string $id): JsonResponse
      {
          $validated = $request->validate(['reason' => 'required|string|max:500']);

          /** @var User $user */
          $user = Auth::user();
          $minorCardRequest = MinorCardRequest::where('uuid', $id)->firstOrFail();
          $minorAccount = $minorCardRequest->minorAccount;

          // CRITICAL: Authorize that caller is a guardian of this minor
          $this->accessService->authorizeGuardian($user, $minorAccount);

          $result = $this->requestService->deny($user, $minorCardRequest, $validated['reason']);
          return response()->json($result);
      }

      public function index(Request $request): JsonResponse
      {
          /** @var User $user */
          $user = Auth::user();

          if ($user->account->isMinor()) {
              $cards = $this->cardService->listMinorCards($user->account);
          } else {
              $validated = $request->validate([
                  'minor_account_uuid' => 'required|uuid|exists:accounts,uuid',
              ]);
              $minor = Account::where('uuid', $validated['minor_account_uuid'])->firstOrFail();
              $cards = $this->cardService->listMinorCards($minor);
          }

          return response()->json(['data' => $cards]);
      }

      public function show(string $cardId): JsonResponse
      {
          $card = $this->cardProvisioning->getCard($cardId);
          abort_unless($card, 404);

          // Use column for access check
          if ($card->minor_account_uuid) {
              $this->guardMinorCardAccess($card);
          }

          return response()->json($card);
      }

      /**
       * Freeze a minor's card. CRITICAL: requires guardian authorization.
       */
      public function freeze(string $cardId): JsonResponse
      {
          /** @var User $user */
          $user = Auth::user();

          $card = $this->cardProvisioning->getCard($cardId);
          abort_unless($card, 404);

          if ($card->minor_account_uuid) {
              $minorAccount = Account::where('uuid', $card->minor_account_uuid)->firstOrFail();
              $this->accessService->authorizeGuardian($user, $minorAccount);
          }

          $result = $this->cardService->freezeCard($user, $card);
          return response()->json($result);
      }

      /**
       * Unfreeze a minor's card. CRITICAL: requires guardian authorization.
       */
      public function unfreeze(string $cardId): JsonResponse
      {
          /** @var User $user */
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

          if ($card->minor_account_uuid) {
              $this->guardMinorCardAccess($card);
          }

          // CRITICAL: Pass userId to getProvisioningData
          $provisioningData = $this->cardProvisioning->getProvisioningData(
              userId: $user->uuid,
              cardToken: $card->cardToken,
              walletType: WalletType::from($validated['wallet_type']),
              deviceId: $validated['device_id'],
              certificates: [],
          );

          return response()->json($provisioningData);
      }

      private function getGuardianMinors(User $guardian): \Illuminate\Support\Collection
      {
          return Account::where('account_type', 'minor')
              ->whereHas('memberships', fn ($q) => $q
                  ->where('user_id', $guardian->id)
                  ->whereIn('role', ['guardian', 'co_guardian']))
              ->get();
      }

      private function guardViewAccess(MinorCardRequest $request): void
      {
          $user = Auth::user();
          $isMinor = $user->account->uuid === $request->minor_account_uuid;
          $isGuardian = $this->accessService->hasGuardianAccess($user, $request->minorAccount);
          abort_unless($isMinor || $isGuardian, 403);
      }

      private function guardMinorCardAccess($card): void
      {
          $user = Auth::user();
          $minor = Account::where('uuid', $card->minor_account_uuid)->firstOrFail();
          $isMinor = $user->account->uuid === $minor->uuid;
          $isGuardian = $this->accessService->hasGuardianAccess($user, $minor);
          abort_unless($isMinor || $isGuardian, 403);
      }
  }
  ```

### Phase 12.5: Filament Workflows

- [ ] **12.5.1** Create `MinorCardRequestResource`
  - File: `app/Filament/Admin/Resources/MinorCardRequestResource.php`
  - List page, view page, relation manager
  - Filters: by status, by date
  - Actions: `ApproveAction`, `DenyAction`

- [ ] **12.5.2** Create ApproveAction / DenyAction
  - Use `authorizeGuardian()` in the action to verify admin is guardian-capable
  - Note: Filament admin may need a separate admin-only guard (not the User-based guard)

- [ ] **12.5.3** Register resource in Filament panel

### Phase 12.6: JIT Funding Integration

- [ ] **12.6.1** Extend `JitFundingService` for minor card limits
  - File: `app/Domain/CardIssuance/Services/JitFundingService.php`
  - Method: Add a check in `authorize()`:
  ```php
  // After line 65: Check if this is a minor card
  if ($card->minor_account_uuid) {
      $minorAccount = Account::query()->where('uuid', $card->minor_account_uuid)->first();

      if ($minorAccount) {
          // Aggregate today's spend for this minor's cards
          $todaySpend = $this->getMinorCardSpendToday($minorAccount);

          // Get the card's limits (from card metadata or DB)
          $dailyLimit = $card->metadata['limits']['daily'] ?? MinorCardConstants::DEFAULT_DAILY_LIMIT;
          $accountLimit = $minorAccount->daily_limit ?? $dailyLimit;
          $effectiveLimit = min($dailyLimit, $accountLimit);

          if ($todaySpend + $authorizationAmount > $effectiveLimit) {
              return $this->decline($request, AuthorizationDecision::DECLINED_LIMIT_EXCEEDED);
          }
      }
  }
  ```
  - Add helper method `getMinorCardSpendToday()` that queries card authorizations for the day

### Phase 12.7: Scheduled Job (Request Expiry)

- [ ] **12.7.1** Create command to expire stale requests
  - File: `app/Console/Commands/ExpireMinorCardRequests.php`
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Console\Commands;

  use App\Domain\Account\Constants\MinorCardConstants;
  use App\Domain\Account\Models\MinorCardRequest;
  use Illuminate\Console\Command;

  class ExpireMinorCardRequests extends Command
  {
      protected $signature = 'minor-card:expire-requests';
      protected $description = 'Expire pending minor card requests older than 72 hours';

      public function handle(): int
      {
          $expired = MinorCardRequest::where('status', MinorCardConstants::STATUS_PENDING_APPROVAL)
              ->whereNotNull('expires_at')
              ->where('expires_at', '<=', now())
              ->count();

          MinorCardRequest::where('status', MinorCardConstants::STATUS_PENDING_APPROVAL)
              ->whereNotNull('expires_at')
              ->where('expires_at', '<=', now())
              ->update(['status' => MinorCardConstants::STATUS_EXPIRED]);

          $this->info("Expired {$expired} pending card requests.");

          return Command::SUCCESS;
      }
  }
  ```

- [ ] **12.7.2** Register in scheduler
  - File: `app/Console/Kernel.php` (or `routes/console.php`):
  ```php
  $schedule->command('minor-card:expire-requests')->daily();
  ```

### Phase 12.8: Unit Tests

- [ ] **12.8.1** Write `MinorCardRequestServiceTest`
  - Tests: Rise tier eligible, Grow tier rejected, active card conflict, pending request conflict, request type determination, approval/deny state transitions, auth checks

- [ ] **12.8.2** Write `MinorCardServiceTest`
  - Tests: card creation with minor_account_uuid, limit MIN enforcement, freeze/unfreeze as guardian, freeze/unfreeze as non-guardian → 403

- [ ] **12.8.3** Write `MinorCardControllerTest`
  - Tests: Guardian authorization on approve/deny/freeze/unfreeze endpoints, non-guardian returns 403

- [ ] **12.8.4** Write Filament tests

### Phase 12.9: Final Hardening

- [ ] **12.9.1** Run static analysis
- [ ] **12.9.2** Run regression suites
- [ ] **12.9.3** Run full test suite

---

## Stop/Go Gates

| Gate | Criteria | Verification Command |
|------|----------|---------------------|
| 12.A | Migrations + model tests pass | `./vendor/bin/pest tests/Unit/Domain/Account/Models/MinorCardRequestTest.php` |
| 12.B | Age gate proven | `./vendor/bin/pest tests/Unit/Domain/Account/Services/MinorCardRequestServiceTest.php --filter="Grow tier"` |
| 12.C | Limit MIN enforcement proven | `./vendor/bin/pest tests/Unit/Domain/Account/Services/MinorCardServiceTest.php --filter="limit = MIN"` |
| 12.D | Guardian authorization proven | `./vendor/bin/pest tests/Feature/Http/Controllers/Api/MinorCardControllerTest.php --filter="guardian"` |
| 12.E | Regression suites green | `./vendor/bin/pest --filter="CardIssuance" && ./vendor/bin/pest --filter="MinorAccount"` |

---

## Definition of Done

- [ ] Rise tier minors can request a virtual card
- [ ] Parents can approve/deny card requests with proper guardian authorization
- [ ] Card spending limits mirror account-level limits (most restrictive)
- [ ] Cards can be frozen/unfrozen independently (guardian-only)
- [ ] Apple Pay / Google Pay provisioning works
- [ ] Merchant category blocks enforced at card level
- [ ] Pending requests auto-expire after 72 hours
- [ ] All tests pass + static analysis clean