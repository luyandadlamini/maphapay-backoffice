# Minor Accounts Phase 12 Virtual Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable Rise tier (ages 13+) minor accounts to request and use a virtual card, with parent approval workflow, spending limit enforcement, and independent card freeze.

**Architecture:** Extend the existing CardIssuance domain with minor-specific request/approval flow. Card issuance through existing `CardProvisioningService` with minor account limits baked in. New `MinorCardRequest` model tracks the approval workflow. Card-to-minor linking via `minor_account_uuid` on the cards table.

**Tech Stack:** Laravel 12, Spatie Event Sourcing, Filament v3, Sanctum auth, existing CardIssuance domain.

---

## File Structure

- `app/Domain/Account/Models/MinorCardRequest.php` — request + approval state tracking
- `app/Domain/Account/Services/MinorCardRequestService.php` — request/approve/deny logic
- `app/Domain/Account/Services/MinorCardService.php` — card creation with minor limits
- `app/Http/Controllers/Api/Account/MinorCardController.php` — API endpoints
- `app/Filament/Admin/Resources/MinorCardRequestResource.php` — Filament management
- `database/migrations/tenant/2026_04_24_xxxxxx_add_minor_account_uuid_to_cards_table.php` — card-to-minor link
- `database/migrations/tenant/2026_04_24_xxxxxx_create_minor_card_requests_table.php` — request table
- `tests/Unit/Domain/Account/Services/MinorCardRequestServiceTest.php`
- `tests/Unit/Domain/Account/Services/MinorCardServiceTest.php`
- `tests/Feature/Http/Controllers/Api/MinorCardControllerTest.php`
- `tests/Feature/Filament/MinorCardRequestResourceTest.php`
- `routes/api.php` — minor card routes

---

## Task Breakdown

### Phase 12.1: Baseline & Guardrails

- [ ] **12.1.1** Verify CardIssuance domain is healthy
  - Run: `./vendor/bin/pest --filter=CardIssuance`
  - Verify: `CardProvisioningService`, `VirtualCard`, `CardIssuerInterface` all green

- [ ] **12.1.2** Verify minor account infrastructure
  - Check `app/Domain/Account/Services/MinorAccountAccessService.php` has guardian validation
  - Check `Account` model has `tier` attribute and age derivation
  - Run: `./vendor/bin/pest --filter=MinorAccount`
  - Verify: minors have `tier` ('grow'|'rise') and age derivation works

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
              $table->string('request_type'); // parent_initiated | child_requested
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

- [ ] **12.2.3** Create `MinorCardRequest` model
  - File: `app/Domain/Account/Models/MinorCardRequest.php`
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Domain\Account\Models;

  use App\Traits\TenantTrait;
  use Illuminate\Database\Eloquent\Concerns\HasUuids;
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;

  class MinorCardRequest extends Model
  {
      use HasUuids, TenantTrait;

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

      public function approvedBy(): BelongsTo
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
  - File: `app/Domain/CardIssuance/Models/Card.php` — add `minor_account_uuid` to `$fillable` array
  - Add relationship: `public function minorAccount(): BelongsTo { return $this->belongsTo(Account::class, 'minor_account_uuid'); }`

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
  use Illuminate\Support\Facades\DB;

  class MinorCardRequestService
  {
      public function __construct(
          private readonly MinorAccountAccessService $accessService,
      ) {}

      public function createRequest(Account $requester, string $minorAccountUuid, string $network, ?array $limits): MinorCardRequest
      {
          $minor = Account::where('uuid', $minorAccountUuid)->firstOrFail();

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

          $requestType = $this->accessService->isGuardianOf($requester, $minor)
              ? MinorCardConstants::REQUEST_TYPE_PARENT_INITIATED
              : MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED;

          return MinorCardRequest::create([
              'minor_account_uuid' => $minor->uuid,
              'requested_by_account_uuid' => $requester->uuid,
              'request_type' => $requestType,
              'status' => MinorCardConstants::STATUS_PENDING_APPROVAL,
              'requested_network' => $network,
              'requested_limits' => $limits,
              'expires_at' => now()->addHours(MinorCardConstants::REQUEST_EXPIRY_HOURS),
          ]);
      }

      public function approve(Account $guardian, MinorCardRequest $request): MinorCardRequest
      {
          $this->guardIsGuardian($guardian, $request->minorAccount);

          if (! $request->canBeApproved()) {
              throw BusinessException::withMessage('Request cannot be approved in its current state');
          }

          $request->update([
              'status' => MinorCardConstants::STATUS_APPROVED,
              'approved_by' => $guardian->uuid,
              'approved_at' => now(),
          ]);

          return $request->refresh();
      }

      public function deny(Account $guardian, MinorCardRequest $request, string $reason): MinorCardRequest
      {
          $this->guardIsGuardian($guardian, $request->minorAccount);

          if (! $request->canBeApproved()) {
              throw BusinessException::withMessage('Request cannot be denied in its current state');
          }

          $request->update([
              'status' => MinorCardConstants::STATUS_DENIED,
              'denial_reason' => $reason,
          ]);

          return $request->refresh();
      }

      private function guardCanRequest(Account $requester, Account $minor): void
      {
          $isMinor = $requester->uuid === $minor->uuid;
          $isGuardian = $this->accessService->isGuardianOf($requester, $minor);

          if (! $isMinor && ! $isGuardian) {
              throw BusinessException::withMessage('Only the minor or their guardian can request a card');
          }
      }

      private function guardIsGuardian(Account $guardian, Account $minor): void
      {
          if (! $this->accessService->isGuardianOf($guardian, $minor)) {
              throw BusinessException::withMessage('Only guardians can approve or deny card requests');
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
  use App\Domain\CardIssuance\Models\Card;
  use App\Domain\CardIssuance\Services\CardProvisioningService;
  use Illuminate\Support\Facades\DB;

  class MinorCardService
  {
      public function __construct(
          private readonly CardProvisioningService $cardProvisioning,
          private readonly MinorCardRequestService $requestService,
      ) {}

      public function createCardFromRequest(MinorCardRequest $request): Card
      {
          $minor = Account::where('uuid', $request->minor_account_uuid)->firstOrFail();
          $limits = $this->resolveLimits($request, $minor);

          $card = $this->cardProvisioning->createCard(
              userId: $minor->user_id,
              cardholderName: $minor->full_name ?? $minor->name,
              metadata: [
                  'minor_account_uuid' => $minor->uuid,
                  'card_request_id' => $request->uuid,
                  'tier' => 'rise',
              ],
              network: $request->requested_network === 'mastercard'
                  ? \App\Domain\CardIssuance\Enums\CardNetwork::MASTERCARD
                  : \App\Domain\CardIssuance\Enums\CardNetwork::VISA,
          );

          $this->cardProvisioning->updateSpendingLimits($card->cardToken, $limits);

          $persistedCard = Card::where('card_token', $card->cardToken)->first();
          $persistedCard->update(['minor_account_uuid' => $minor->uuid]);

          $request->update(['status' => MinorCardConstants::STATUS_CARD_CREATED]);

          return $persistedCard;
      }

      public function freezeCard(Account $guardian, Card $card): Card
      {
          $minor = Account::where('uuid', $card->minor_account_uuid)->first();
          if ($minor && ! app(MinorAccountAccessService::class)->isGuardianOf($guardian, $minor)) {
              throw \App\Exceptions\BusinessException::withMessage('Only guardians can freeze a minor card');
          }

          $this->cardProvisioning->freezeCard($card->card_token);
          return $card->refresh();
      }

      public function unfreezeCard(Account $guardian, Card $card): Card
      {
          $minor = Account::where('uuid', $card->minor_account_uuid)->first();
          if ($minor && ! app(MinorAccountAccessService::class)->isGuardianOf($guardian, $minor)) {
              throw \App\Exceptions\BusinessException::withMessage('Only guardians can unfreeze a minor card');
          }

          $this->cardProvisioning->unfreezeCard($card->card_token);
          return $card->refresh();
      }

      public function listMinorCards(Account $minor): array
      {
          $tokens = Card::where('minor_account_uuid', $minor->uuid)
              ->whereNotIn('status', ['cancelled'])
              ->pluck('card_token')
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
  - File: `routes/api.php` — add the following routes grouped under `/api/v1`:
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

- [ ] **12.4.2** Create `MinorCardController`
  - File: `app/Http/Controllers/Api/Account/MinorCardController.php`
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Http\Controllers\Api\Account;

  use App\Domain\Account\Models\Account;
  use App\Domain\Account\Models\MinorCardRequest;
  use App\Domain\Account\Services\MinorCardRequestService;
  use App\Domain\Account\Services\MinorCardService;
  use App\Domain\CardIssuance\Services\CardProvisioningService;
  use App\Http\Controllers\Controller;
  use Illuminate\Http\JsonResponse;
  use Illuminate\Http\Request;
  use Illuminate\Support\Facades\Auth;

  class MinorCardController extends Controller
  {
      public function __construct(
          private readonly MinorCardRequestService $requestService,
          private readonly MinorCardService $cardService,
          private readonly CardProvisioningService $cardProvisioning,
      ) {}

      public function listRequests(Request $request): JsonResponse
      {
          $user = Auth::user();
          $account = $this->resolveAccount($request);

          $query = MinorCardRequest::query();

          if ($user->account->isMinor()) {
              $query->where('minor_account_uuid', $user->account->uuid);
          } else {
              $guardianMinors = $this->getGuardianMinors($user->account);
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

          $minorUuid = $validated['minor_account_uuid'] ?? Auth::user()->account->uuid;
          $minor = Account::where('uuid', $minorUuid)->firstOrFail();

          $result = $this->requestService->createRequest(
              requester: Auth::user()->account,
              minorAccountUuid: $minor->uuid,
              network: $validated['network'] ?? 'visa',
              limits: $validated['requested_limits'] ?? null,
          );

          return response()->json($result, 201);
      }

      public function showRequest(string $id): JsonResponse
      {
          $request = MinorCardRequest::where('uuid', $id)->firstOrFail();
          $this->guardViewAccess($request);
          return response()->json($request);
      }

      public function approveRequest(string $id): JsonResponse
      {
          $minorCardRequest = MinorCardRequest::where('uuid', $id)->firstOrFail();
          $card = $this->cardService->createCardFromRequest($minorCardRequest);
          return response()->json(['request' => $minorCardRequest->refresh(), 'card' => $card]);
      }

      public function denyRequest(Request $request, string $id): JsonResponse
      {
          $validated = $request->validate(['reason' => 'required|string|max:500']);
          $minorCardRequest = MinorCardRequest::where('uuid', $id)->firstOrFail();
          $result = $this->requestService->deny(
            Auth::user()->account,
            $minorCardRequest,
            $validated['reason'],
          );
          return response()->json($result);
      }

      public function index(Request $request): JsonResponse
      {
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
          $this->guardCardAccess($card);
          return response()->json($card);
      }

      public function freeze(string $cardId): JsonResponse
      {
          $card = $this->cardProvisioning->getCard($cardId);
          abort_unless($card, 404);
          $this->guardCardAccess($card);
          $result = $this->cardService->freezeCard(Auth::user()->account, $card);
          return response()->json($result);
      }

      public function unfreeze(string $cardId): JsonResponse
      {
          $card = $this->cardProvisioning->getCard($cardId);
          abort_unless($card, 404);
          $this->guardCardAccess($card);
          $result = $this->cardService->unfreezeCard(Auth::user()->account, $card);
          return response()->json($result);
      }

      public function provision(Request $request, string $cardId): JsonResponse
      {
          $validated = $request->validate([
              'wallet_type' => 'required|in:apple_pay,google_pay',
              'device_id' => 'required|string',
          ]);

          $card = $this->cardProvisioning->getCard($cardId);
          abort_unless($card, 404);
          $this->guardCardAccess($card);

          $provisioningData = $this->cardProvisioning->getProvisioningData(
              cardToken: $card->cardToken,
              walletType: \App\Domain\CardIssuance\Enums\WalletType::from($validated['wallet_type']),
              deviceId: $validated['device_id'],
              certificates: [],
          );

          return response()->json($provisioningData);
      }

      private function resolveAccount(Request $request): Account
      {
          if ($request->has('minor_account_uuid')) {
              return Account::where('uuid', $request->minor_account_uuid)->firstOrFail();
          }
          return Auth::user()->account;
      }

      private function guardViewAccess(MinorCardRequest $cardRequest): void
      {
          $user = Auth::user()->account;
          $isMinor = $user->uuid === $cardRequest->minor_account_uuid;
          $isGuardian = app(MinorAccountAccessService::class)->isGuardianOf($user, $cardRequest->minorAccount);
          abort_unless($isMinor || $isGuardian, 403);
      }

      private function guardCardAccess($card): void
      {
          if (! $card->metadata['minor_account_uuid'] ?? null) {
              return;
          }
          $user = Auth::user()->account;
          $minor = Account::where('uuid', $card->metadata['minor_account_uuid'])->first();
          if (! $minor) {
              return;
          }
          $isMinor = $user->uuid === $minor->uuid;
          $isGuardian = app(MinorAccountAccessService::class)->isGuardianOf($user, $minor);
          abort_unless($isMinor || $isGuardian, 403);
      }

      private function getGuardianMinors(Account $guardian): \Illuminate\Support\Collection
      {
          return Account::where('account_type', 'minor')
              ->whereHas('guardians', fn ($q) => $q->where('account_id', $guardian->uuid))
              ->get();
      }
  }
  ```

### Phase 12.5: Filament Workflows

- [ ] **12.5.1** Create `MinorCardRequestResource`
  - File: `app/Filament/Admin/Resources/MinorCardRequestResource.php`
  - List page: columns for minor name, request type, status, network, created at, expires at
  - Relation manager: linked card (read-only)
  - Filters: by status, by tier (Rise only)
  - Actions: `ApproveAction`, `DenyAction`

- [ ] **12.5.2** Create `MinorCardRequestResource/Pages`
  - File: `app/Filament/Admin/Resources/MinorCardRequestResource/Pages/ListMinorCardRequests.php`
  - File: `app/Filament/Admin/Resources/MinorCardRequestResource/Pages/ViewMinorCardRequest.php`

- [ ] **12.5.3** Create ApproveAction
  - File: `app/Filament/Admin/Resources/MinorCardRequestResource/Actions/ApproveAction.php`
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Filament\Admin\Resources\MinorCardRequestResource\Actions;

  use App\Domain\Account\Models\MinorCardRequest;
  use App\Domain\Account\Services\MinorCardService;
  use Filament\Actions\Action;
  use Filament\Notifications\Notification;

  class ApproveAction
  {
      public static function make(): Action
      {
          return Action::make('approve')
              ->label('Approve & Issue Card')
              ->color('success')
              ->requiresConfirmation()
              ->action(function (MinorCardRequest $record) {
                  $guardian = auth()->user()->account;
                  $cardService = app(MinorCardService::class);
                  $cardService->createCardFromRequest($record);
                  Notification::make()->title('Card issued successfully')->send();
              });
      }
  }
  ```

- [ ] **12.5.4** Create DenyAction
  - File: `app/Filament/Admin/Resources/MinorCardRequestResource/Actions/DenyAction.php`
  - Modal with reason textarea
  - Updates request status to `denied`

- [ ] **12.5.5** Register resource in Filament
  - Check `app/Providers/Filament/AdminPanelProvider.php` or `app/Providers/FilamentServiceProvider.php`
  - Add: `MinorCardRequestResource::navigationItems()` to the panel

### Phase 12.6: JIT Funding Integration (Card Limit Enforcement)

- [ ] **12.6.1** Extend `JitFundingService` to enforce minor account limits
  - File: `app/Domain/CardIssuance/Services/JitFundingService.php` — update `authorize()` method
  - Before approving, check: if card has `minor_account_uuid`, fetch account and verify transaction is within `MIN(card_limit, account_limit)`
  - Logic: if `account.daily_limit` is set, compute daily spent so far + this authorization amount, reject if exceeds limit

### Phase 12.7: Unit Tests

- [ ] **12.7.1** Write `MinorCardRequestServiceTest`
  - File: `tests/Unit/Domain/Account/Services/MinorCardRequestServiceTest.php`
  - Test: create request for Rise tier minor → succeeds
  - Test: create request for Grow tier minor → throws BusinessException
  - Test: create request when minor already has active card → throws BusinessException (409 pattern)
  - Test: create request when pending request exists → throws BusinessException
  - Test: child creates request → request_type = 'child_requested'
  - Test: guardian creates request → request_type = 'parent_initiated'
  - Test: non-guardian creates request for another minor → throws BusinessException
  - Test: approve by guardian → status = 'approved'
  - Test: approve by non-guardian → throws BusinessException
  - Test: deny by guardian → status = 'denied' with reason
  - Test: approve expired request → throws BusinessException
  - Test: minor account not found → 404

- [ ] **12.7.2** Write `MinorCardServiceTest`
  - File: `tests/Unit/Domain/Account/Services/MinorCardServiceTest.php`
  - Test: create card from approved request → card created with minor_account_uuid
  - Test: card limits = MIN(requested, account) daily limit
  - Test: card limits = MIN(requested, account) monthly limit
  - Test: card limits = MIN(requested, account) single transaction limit
  - Test: card limits use defaults when no limits requested and no account limits
  - Test: freeze card as guardian → status = 'frozen'
  - Test: freeze card as minor → throws BusinessException
  - Test: unfreeze card as guardian → status = 'active'
  - Test: unfreeze card as minor → throws BusinessException
  - Test: listMinorCards returns only non-cancelled cards for minor
  - Test: listMinorCards empty when minor has no cards

- [ ] **12.7.3** Write `MinorCardControllerTest`
  - File: `tests/Feature/Http/Controllers/Api/MinorCardControllerTest.php`
  - Test: child POST /minor-cards/requests → 201, pending request
  - Test: parent POST /minor-cards/requests → 201, parent_initiated type
  - Test: child GET /minor-cards/requests → sees own requests only
  - Test: parent GET /minor-cards/requests → sees minor's requests
  - Test: parent POST /minor-cards/requests/{id}/approve → 200, card created
  - Test: parent POST /minor-cards/requests/{id}/deny → 200, status = denied
  - Test: non-guardian POST /approve → 403
  - Test: minor POST /approve → 403
  - Test: guardian GET /minor-cards → sees minor's cards
  - Test: child GET /minor-cards → sees own cards
  - Test: guardian POST /minor-cards/{id}/freeze → 200, card frozen
  - Test: guardian DELETE /minor-cards/{id}/freeze → 200, card unfrozen
  - Test: child POST /freeze → 403
  - Test: card provisioning returns valid data
  - Test: 409 when minor already has active card

- [ ] **12.7.4** Write Filament tests
  - File: `tests/Feature/Filament/MinorCardRequestResourceTest.php`
  - Test: list page shows all requests
  - Test: approve action creates card and updates status
  - Test: deny action updates status with reason
  - Test: filter by status works

### Phase 12.8: Final Hardening

- [ ] **12.8.1** Run static analysis
  - Run: `./vendor/bin/phpstan analyse --memory-limit=2G`
  - Fix any errors before proceeding

- [ ] **12.8.2** Run regression suites
  - Run: `./vendor/bin/pest --filter=CardIssuance`
  - Run: `./vendor/bin/pest --filter=MinorAccount`
  - All must pass

- [ ] **12.8.3** Run full test suite
  - Run: `./vendor/bin/pest --parallel`

---

## Stop/Go Gates

| Gate | Criteria | Verification Command |
|------|----------|---------------------|
| 12.A | Migrations + model tests pass | `./vendor/bin/pest tests/Unit/Domain/Account/Models/MinorCardRequestTest.php` |
| 12.B | Age gate proven | `./vendor/bin/pest tests/Unit/Domain/Account/Services/MinorCardRequestServiceTest.php --filter="Grow tier"` |
| 12.C | Limit MIN enforcement proven | `./vendor/bin/pest tests/Unit/Domain/Account/Services/MinorCardServiceTest.php --filter="limit = MIN"` |
| 12.D | Card freeze independent proven | `./vendor/bin/pest tests/Unit/Domain/Account/Services/MinorCardServiceTest.php --filter="freeze"` |
| 12.E | Regression suites green | `./vendor/bin/pest --filter="CardIssuance" && ./vendor/bin/pest --filter="MinorAccount"` |

---

## Definition of Done

- [ ] Rise tier minors (ages 13+) can request a virtual card
- [ ] Parents can approve or deny card requests in Filament and via API
- [ ] Card spending limits mirror account-level limits (most restrictive wins)
- [ ] Cards can be frozen/unfrozen independently of the account
- [ ] Apple Pay / Google Pay provisioning works for minor cards
- [ ] Merchant category blocks enforced at card level (via existing MinorAccountAccessService)
- [ ] All tests pass: unit, feature, integration
- [ ] Static analysis clean (phpstan + php-cs-fixer)
- [ ] Spec coverage complete

---

## Open Questions

1. **Physical card issuance**: Defer or include card printing workflow in this phase?
2. **Card expiry on minor turn 18**: Should card auto-freeze or auto-convert to personal card?
3. **Grow tier eligibility**: Confirm no cards for ages 6-12 under any circumstances.
4. **Card request expiry job**: Should a scheduled job auto-expire pending requests after 72 hours?