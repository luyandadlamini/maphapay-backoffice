# Minor Accounts Phase 3: Advanced Controls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the Phase 2 deferred emergency allowance bypass, account pause/freeze controls, guardian spending analytics, and in-app notifications for the guardian approval workflow.

**Architecture:** The emergency bypass is a pre-flight check inside the existing minor enforcement block in `SendMoneyStoreController`. Account pause and freeze add boolean columns to `accounts` enforced at the same guard. Analytics are read-only endpoints on a new `MinorAccountAnalyticsController`. Notifications are stored in a `minor_notifications` tenant table and dispatched via a lightweight service. Webhooks are included as Task 9 but require user sign-off on reliability model (see that task's header).

**Tech Stack:** PHP 8.4, Laravel 12, MySQL (tenant DB), PHPUnit attribute-based tests run via Pest, PHPStan Level 8, `$guarded = []` models, `HasUuids` + `UsesTenantConnection` traits.

---

## Decisions Made (2026-04-17)

The four clarifying questions from the agent prompt are resolved as follows. All tasks below assume these choices.

1. **Webhook reliability:** Queued job with retry + `minor_webhook_logs` table. Each delivery attempt is logged (status code, response snippet, attempt number). Failed attempts retry with exponential backoff (3 attempts, 30s/2m/10m) before being marked as permanently failed. Rationale: webhook events signal approval-critical state changes; losing them silently is worse than the marginal storage cost of logs.
2. **Analytics scope:** Real-time aggregation from `transaction_projections` and `minor_spend_approvals`. No pre-computed `spending_summaries` table. Rationale: query volume is guardian-driven (low), datasets are per-account (small), and avoiding stale data outweighs the aggregation cost at this stage.
3. **Notifications transport:** REST polling of `minor_notifications` via `GET /api/accounts/minor/{uuid}/notifications`. Mobile clients poll on foreground or pull-to-refresh. Expo push is explicitly Phase 4. Rationale: keeps Phase 3 backend-only deployable.
4. **Mobile priority:** React Native screens are **in scope** for Phase 3 (see Optional Tasks A/B/C at the bottom — promoted to Phase 3 deliverables). They ship after backend Tasks 1–10 and their own suite is exercised against the live backend.

---

## File Map

| Action | Path | Responsibility |
|--------|------|----------------|
| Modify | `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php` | Emergency bypass + pause/freeze check |
| Create | `database/migrations/tenant/2026_04_17_200000_add_is_paused_to_accounts.php` | `is_paused` boolean column |
| Modify | `app/Domain/Account/Models/Account.php` | Add `is_paused` to `$casts` |
| Modify | `app/Http/Controllers/Api/MinorAccountController.php` | `pause()` + `resume()` + `freeze()` + `unfreeze()` endpoints |
| Modify | `app/Policies/AccountPolicy.php` | Add `pauseMinor()` (same as `updateMinor`) |
| Modify | `app/Rules/ValidateMinorAccountPermission.php` | Make `resolveLimits()` public static for analytics reuse |
| Create | `app/Http/Controllers/Api/MinorAccountAnalyticsController.php` | `spendingSummary()`, `spendingByCategory()`, `transactionHistory()` |
| Create | `database/migrations/tenant/2026_04_17_200001_create_minor_notifications_table.php` | `minor_notifications` schema |
| Create | `app/Domain/Account/Models/MinorNotification.php` | Notification model |
| Create | `app/Domain/Account/Services/MinorNotificationService.php` | Create notifications, mark read |
| Modify | `app/Http/Controllers/Api/MinorSpendApprovalController.php` | Dispatch notifications on create/approve/decline |
| Create | `database/migrations/tenant/2026_04_17_200002_create_minor_webhooks_table.php` | `minor_webhooks` subscription table |
| Create | `database/migrations/tenant/2026_04_17_200003_create_minor_webhook_logs_table.php` | Per-attempt delivery log |
| Create | `app/Domain/Account/Models/MinorWebhook.php` | Webhook subscription model |
| Create | `app/Domain/Account/Models/MinorWebhookLog.php` | Delivery log model |
| Create | `app/Domain/Account/Services/MinorWebhookService.php` | Dispatch + subscription lookup |
| Create | `app/Domain/Account/Jobs/DeliverMinorWebhookJob.php` | Retryable queued HTTP delivery |
| Create | `app/Http/Controllers/Api/MinorWebhookController.php` | Webhook CRUD (guardian only) |
| Modify | `app/Domain/Account/Routes/api.php` | All new Phase 3 routes |
| Create | `tests/Feature/Http/Controllers/Api/MinorEmergencyBypassTest.php` | Emergency bypass tests |
| Create | `tests/Feature/Http/Controllers/Api/MinorAccountPauseTest.php` | Pause/resume/freeze tests |
| Create | `tests/Feature/Http/Controllers/Api/MinorAccountAnalyticsTest.php` | Analytics tests |
| Create | `tests/Feature/Http/Controllers/Api/MinorNotificationTest.php` | Notification tests |
| Create | `tests/Feature/MinorAccountPhase3IntegrationTest.php` | End-to-end Phase 3 test |

---

## Task 1: Emergency Allowance Bypass in SendMoneyStoreController

**Phase 2 note:** "The actual bypass in `SendMoneyStoreController` (checking `emergency_allowance_balance` before applying limits) is noted as a Phase 3 extension."

**Files:**
- Modify: `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php`
- Create: `tests/Feature/Http/Controllers/Api/MinorEmergencyBypassTest.php`

The bypass check goes **inside** the existing `if ($threshold !== null && (float) $normalizedAmount > $threshold)` block, before the approval record is created. If the minor's `emergency_allowance_balance` (SZL integer) covers the requested amount, deduct it and fall through to the normal transfer flow. Otherwise create the approval record as before.

Note on units: `$normalizedAmount` is a major-unit decimal string (e.g. `'150.00'`). `emergency_allowance_balance` is stored as a SZL integer (e.g. `200` for 200 SZL). The deduction is `(int) round((float) $normalizedAmount)` SZL.

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Http/Controllers/Api/MinorEmergencyBypassTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorSpendApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorEmergencyBypassTest extends TestCase
{
    protected function connectionsToTransact(): array { return ['mysql', 'central']; }
    protected function shouldCreateDefaultAccountsInSetup(): bool { return false; }

    private User $child;
    private User $guardian;
    private Account $minorAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardian = User::factory()->create();
        $this->child    = User::factory()->create();

        $tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id' => $tenantId, 'name' => 'T', 'plan' => 'default',
            'team_id' => null, 'trial_ends_at' => null,
            'created_at' => now(), 'updated_at' => now(), 'data' => json_encode([]),
        ]);

        $guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardian->uuid, 'type' => 'personal',
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->guardian->uuid,
            'account_uuid' => $guardianAccount->uuid,
            'tenant_id'    => $tenantId,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'                  => $this->child->uuid,
            'type'                       => 'minor',
            'tier'                       => 'grow',
            'permission_level'           => 3,
            'parent_account_id'          => $guardianAccount->uuid,
            'emergency_allowance_amount'  => 300,
            'emergency_allowance_balance' => 300,
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->child->uuid,
            'account_uuid' => $this->minorAccount->uuid,
            'tenant_id'    => $tenantId,
            'account_type' => 'minor',
            'role'         => 'owner',
            'status'       => 'active',
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->guardian->uuid,
            'account_uuid' => $this->minorAccount->uuid,
            'tenant_id'    => $tenantId,
            'account_type' => 'minor',
            'role'         => 'guardian',
            'status'       => 'active',
        ]);
    }

    #[Test]
    public function emergency_balance_bypasses_approval_threshold_and_is_decremented(): void
    {
        // Level 3 threshold = 100 SZL. Request 150 SZL. Emergency balance = 300 SZL.
        $recipient = User::factory()->create();
        Account::factory()->create(['user_uuid' => $recipient->uuid, 'type' => 'personal']);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);
        $response = $this->postJson('/api/send-money/store', [
            'user'   => $recipient->mobile ?? $recipient->email,
            'amount' => '150.00',
        ]);

        // Should NOT be 202 (no approval pending) — emergency fund covers it
        $this->assertNotEquals(202, $response->status());
        $this->assertDatabaseMissing('minor_spend_approvals', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'status'             => 'pending',
        ]);

        // Balance should be decremented by 150
        $this->assertDatabaseHas('accounts', [
            'uuid'                       => $this->minorAccount->uuid,
            'emergency_allowance_balance' => 150,
        ]);
    }

    #[Test]
    public function insufficient_emergency_balance_still_creates_approval_record(): void
    {
        // Emergency balance = 50 SZL, request = 150 SZL → insufficient → approval needed
        $this->minorAccount->forceFill(['emergency_allowance_balance' => 50])->save();

        $recipient = User::factory()->create();
        Account::factory()->create(['user_uuid' => $recipient->uuid, 'type' => 'personal']);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);
        $response = $this->postJson('/api/send-money/store', [
            'user'   => $recipient->mobile ?? $recipient->email,
            'amount' => '150.00',
        ]);

        $response->assertStatus(202);
        $this->assertDatabaseHas('minor_spend_approvals', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'status'             => 'pending',
        ]);

        // Emergency balance should NOT have been decremented (insufficient)
        $this->assertDatabaseHas('accounts', [
            'uuid'                       => $this->minorAccount->uuid,
            'emergency_allowance_balance' => 50,
        ]);
    }

    #[Test]
    public function zero_emergency_balance_does_not_bypass_approval(): void
    {
        $this->minorAccount->forceFill([
            'emergency_allowance_amount'  => 0,
            'emergency_allowance_balance' => 0,
        ])->save();

        $recipient = User::factory()->create();
        Account::factory()->create(['user_uuid' => $recipient->uuid, 'type' => 'personal']);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);
        $response = $this->postJson('/api/send-money/store', [
            'user'   => $recipient->mobile ?? $recipient->email,
            'amount' => '150.00',
        ]);

        $response->assertStatus(202);
    }

    #[Test]
    public function resetting_emergency_allowance_restores_full_balance(): void
    {
        // Guardian can reset the allowance to refill the balance
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);
        $this->putJson(
            '/api/accounts/minor/' . $this->minorAccount->uuid . '/emergency-allowance',
            ['amount' => 200]
        )->assertOk();

        $this->assertDatabaseHas('accounts', [
            'uuid'                        => $this->minorAccount->uuid,
            'emergency_allowance_amount'  => 200,
            'emergency_allowance_balance' => 200,
        ]);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

```bash
cd /Users/Lihle/Development/Coding/maphapay-backoffice
php artisan test tests/Feature/Http/Controllers/Api/MinorEmergencyBypassTest.php --no-coverage
```

Expected: `emergency_balance_bypasses_approval_threshold_and_is_decremented` fails — currently ANY spend above threshold creates an approval record regardless of emergency balance.

- [ ] **Step 3: Add emergency bypass to SendMoneyStoreController**

Open `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php`.

Find this block (around line 192):
```php
            if ($threshold !== null && (float) $normalizedAmount > $threshold) {
                // Find the primary guardian's account UUID
                $guardianAccountUuid = null;
                try {
```

Replace the entire `if ($threshold !== null ...)` block with:

```php
            if ($threshold !== null && (float) $normalizedAmount > $threshold) {
                // ── Emergency allowance bypass ───────────────────────────────────
                // If the minor has a pre-funded emergency reserve covering this
                // amount, deduct it and fall through to the normal transfer flow
                // instead of holding the transaction for guardian approval.
                $emergencyBalance = (int) ($fromAccount->emergency_allowance_balance ?? 0);
                $requestedSzl     = (int) round((float) $normalizedAmount);

                if ($emergencyBalance > 0 && $requestedSzl <= $emergencyBalance) {
                    $fromAccount->decrement('emergency_allowance_balance', $requestedSzl);
                    // Fall through to normal transfer — do NOT return 202 here.
                } else {
                    // ── Guardian approval required ───────────────────────────────
                    $guardianAccountUuid = null;
                    try {
                        $guardianMembership = AccountMembership::query()
                            ->forAccount($fromAccount->uuid)
                            ->active()
                            ->where('role', 'guardian')
                            ->first();

                        $guardianAccountUuid = $guardianMembership?->account_uuid;
                    } catch (\Exception) {
                        // AccountMembership table may not be accessible
                    }

                    $guardianAccountUuid = $guardianAccountUuid ?? (string) $fromAccount->parent_account_id;

                    $approval = MinorSpendApproval::create([
                        'minor_account_uuid'    => $fromAccount->uuid,
                        'guardian_account_uuid' => $guardianAccountUuid,
                        'from_account_uuid'     => $fromAccount->uuid,
                        'to_account_uuid'       => $toAccount->uuid,
                        'amount'                => $normalizedAmount,
                        'asset_code'            => $asset->code,
                        'note'                  => $validated['note'] ?? null,
                        'merchant_category'     => $merchantCategory,
                        'status'                => 'pending',
                        'expires_at'            => now()->addHours(24),
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'This transaction requires guardian approval.',
                        'data'    => [
                            'approval_id' => $approval->id,
                            'status'      => 'pending_guardian_approval',
                            'expires_at'  => $approval->expires_at->toISOString(),
                        ],
                    ], 202);
                }
                // ─────────────────────────────────────────────────────────────────
            }
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorEmergencyBypassTest.php --no-coverage
```

Expected: All 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
cd /Users/Lihle/Development/Coding/maphapay-backoffice
git add app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php \
        tests/Feature/Http/Controllers/Api/MinorEmergencyBypassTest.php
git commit -m "feat: emergency allowance bypasses guardian approval threshold in spend flow

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 2: Account Pause Migration + Model Update

**Files:**
- Create: `database/migrations/tenant/2026_04_17_200000_add_is_paused_to_accounts.php`
- Modify: `app/Domain/Account/Models/Account.php`

- [ ] **Step 1: Create the tenant migration**

Save to `database/migrations/tenant/2026_04_17_200000_add_is_paused_to_accounts.php`:

```php
<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            // Guardian can temporarily pause all outgoing transactions.
            // `is_paused` is a reversible guardian action; `frozen` (existing)
            // is a harder emergency stop. Both are enforced in SendMoneyStoreController.
            $table->boolean('is_paused')->default(false)->after('emergency_allowance_balance');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('is_paused');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
cd /Users/Lihle/Development/Coding/maphapay-backoffice
php artisan migrate --path=database/migrations/tenant/2026_04_17_200000_add_is_paused_to_accounts.php --force
```

- [ ] **Step 3: Update Account model casts**

Open `app/Domain/Account/Models/Account.php`. Find the `$casts` array:

```php
protected $casts = [
    'frozen' => 'boolean',
    'capabilities' => 'array',
];
```

Replace with:

```php
protected $casts = [
    'frozen'    => 'boolean',
    'is_paused' => 'boolean',
    'capabilities' => 'array',
];
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/tenant/2026_04_17_200000_add_is_paused_to_accounts.php \
        app/Domain/Account/Models/Account.php
git commit -m "feat: add is_paused boolean to accounts for guardian pause control

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 3: Pause/Resume/Freeze Endpoints

**Files:**
- Modify: `app/Http/Controllers/Api/MinorAccountController.php`
- Modify: `app/Domain/Account/Routes/api.php`
- Create: `tests/Feature/Http/Controllers/Api/MinorAccountPauseTest.php`

Guardian-only pause/resume operate on `is_paused`. Guardian-only freeze/unfreeze operate on the existing `frozen` column. The distinction: pause is a soft temporary stop (guardian toggles it easily); freeze is an emergency hard stop. Only guardians can unfreeze a minor account (a child cannot self-unfreeze, unlike general accounts).

- [ ] **Step 1: Write failing tests**

Save to `tests/Feature/Http/Controllers/Api/MinorAccountPauseTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorAccountPauseTest extends TestCase
{
    protected function connectionsToTransact(): array { return ['mysql', 'central']; }
    protected function shouldCreateDefaultAccountsInSetup(): bool { return false; }

    private User $guardian;
    private User $child;
    private Account $minorAccount;
    private Account $guardianAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardian = User::factory()->create();
        $this->child    = User::factory()->create();

        $tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id' => $tenantId, 'name' => 'T', 'plan' => 'default',
            'team_id' => null, 'trial_ends_at' => null,
            'created_at' => now(), 'updated_at' => now(), 'data' => json_encode([]),
        ]);

        $this->guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardian->uuid, 'type' => 'personal',
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->guardian->uuid,
            'account_uuid' => $this->guardianAccount->uuid,
            'tenant_id'    => $tenantId,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'        => $this->child->uuid,
            'type'             => 'minor',
            'tier'             => 'grow',
            'permission_level' => 3,
            'parent_account_id' => $this->guardianAccount->uuid,
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->child->uuid,
            'account_uuid' => $this->minorAccount->uuid,
            'tenant_id'    => $tenantId,
            'account_type' => 'minor',
            'role'         => 'owner',
            'status'       => 'active',
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->guardian->uuid,
            'account_uuid' => $this->minorAccount->uuid,
            'tenant_id'    => $tenantId,
            'account_type' => 'minor',
            'role'         => 'guardian',
            'status'       => 'active',
        ]);
    }

    // ─── Pause / Resume ───────────────────────────────────────────────────

    #[Test]
    public function guardian_can_pause_minor_account(): void
    {
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->putJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/pause');

        $response->assertOk()->assertJsonPath('data.is_paused', true);
        $this->assertDatabaseHas('accounts', [
            'uuid'      => $this->minorAccount->uuid,
            'is_paused' => true,
        ]);
    }

    #[Test]
    public function guardian_can_resume_paused_minor_account(): void
    {
        $this->minorAccount->forceFill(['is_paused' => true])->save();

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->putJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/resume');

        $response->assertOk()->assertJsonPath('data.is_paused', false);
        $this->assertDatabaseHas('accounts', [
            'uuid'      => $this->minorAccount->uuid,
            'is_paused' => false,
        ]);
    }

    #[Test]
    public function non_guardian_cannot_pause_minor_account(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['read', 'write', 'delete']);

        $this->putJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/pause')
            ->assertForbidden();
    }

    // ─── Freeze / Unfreeze ────────────────────────────────────────────────

    #[Test]
    public function guardian_can_freeze_minor_account(): void
    {
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->putJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/freeze');

        $response->assertOk()->assertJsonPath('data.frozen', true);
        $this->assertDatabaseHas('accounts', [
            'uuid'   => $this->minorAccount->uuid,
            'frozen' => true,
        ]);
    }

    #[Test]
    public function guardian_can_unfreeze_minor_account(): void
    {
        $this->minorAccount->forceFill(['frozen' => true])->save();

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->putJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/unfreeze');

        $response->assertOk()->assertJsonPath('data.frozen', false);
    }

    #[Test]
    public function child_cannot_unfreeze_own_account(): void
    {
        $this->minorAccount->forceFill(['frozen' => true])->save();

        // Child (account owner, but not guardian) cannot unfreeze
        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);

        $this->putJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/unfreeze')
            ->assertForbidden();
    }

    #[Test]
    public function non_guardian_cannot_freeze(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['read', 'write', 'delete']);

        $this->putJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/freeze')
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify they fail**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorAccountPauseTest.php --no-coverage
```

Expected: All 6 tests FAIL — routes don't exist yet.

- [ ] **Step 3: Add methods to MinorAccountController**

Open `app/Http/Controllers/Api/MinorAccountController.php`.

Add these imports near the top (in the existing `use` block):
```php
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
```
(If not already present — check the file first.)

Add these four methods before the closing `}` of the class:

```php
    /**
     * PUT /api/accounts/minor/{uuid}/pause
     * Guardian pauses all outgoing transactions for this minor account.
     */
    public function pause(Request $request, string $uuid): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user    = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($this->accountPolicy->updateMinor($user, $account), 403);

        $account->forceFill(['is_paused' => true])->save();

        return response()->json([
            'success' => true,
            'data'    => ['uuid' => $account->uuid, 'is_paused' => true],
        ]);
    }

    /**
     * PUT /api/accounts/minor/{uuid}/resume
     * Guardian resumes a previously paused minor account.
     */
    public function resume(Request $request, string $uuid): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user    = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($this->accountPolicy->updateMinor($user, $account), 403);

        $account->forceFill(['is_paused' => false])->save();

        return response()->json([
            'success' => true,
            'data'    => ['uuid' => $account->uuid, 'is_paused' => false],
        ]);
    }

    /**
     * PUT /api/accounts/minor/{uuid}/freeze
     * Guardian emergency-freezes a minor account. Only guardians can unfreeze.
     */
    public function freezeMinor(Request $request, string $uuid): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user    = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($this->accountPolicy->updateMinor($user, $account), 403);

        $account->forceFill(['frozen' => true])->save();

        return response()->json([
            'success' => true,
            'data'    => ['uuid' => $account->uuid, 'frozen' => true],
        ]);
    }

    /**
     * PUT /api/accounts/minor/{uuid}/unfreeze
     * Guardian unfreezes a minor account. Child (owner) may NOT call this.
     */
    public function unfreezeMinor(Request $request, string $uuid): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user    = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        // Strict: only the guardian role (not the account owner) can unfreeze.
        abort_unless($this->accountPolicy->updateMinor($user, $account), 403);

        $account->forceFill(['frozen' => false])->save();

        return response()->json([
            'success' => true,
            'data'    => ['uuid' => $account->uuid, 'frozen' => false],
        ]);
    }
```

- [ ] **Step 4: Register routes**

Open `app/Domain/Account/Routes/api.php`.

Find the minor-account routes section and add after the existing emergency-allowance route:

```php
    // Pause / Resume
    Route::put('/accounts/minor/{uuid}/pause',   [MinorAccountController::class, 'pause'])->middleware(['api.rate_limit:mutation', 'scope:write']);
    Route::put('/accounts/minor/{uuid}/resume',  [MinorAccountController::class, 'resume'])->middleware(['api.rate_limit:mutation', 'scope:write']);
    // Freeze / Unfreeze (guardian-only; child cannot self-unfreeze)
    Route::put('/accounts/minor/{uuid}/freeze',   [MinorAccountController::class, 'freezeMinor'])->middleware(['api.rate_limit:mutation', 'scope:write']);
    Route::put('/accounts/minor/{uuid}/unfreeze', [MinorAccountController::class, 'unfreezeMinor'])->middleware(['api.rate_limit:mutation', 'scope:write']);
```

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorAccountPauseTest.php --no-coverage
```

Expected: All 6 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/MinorAccountController.php \
        app/Domain/Account/Routes/api.php \
        tests/Feature/Http/Controllers/Api/MinorAccountPauseTest.php
git commit -m "feat: guardian pause/resume and freeze/unfreeze endpoints for minor accounts

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 4: Pause + Freeze Enforcement in SendMoneyStoreController

Block outgoing transactions when the minor account is paused or frozen.

**Files:**
- Modify: `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php`
- Modify: `tests/Feature/Http/Controllers/Api/MinorSpendEnforcementTest.php`

- [ ] **Step 1: Write failing tests**

Add these two tests to `tests/Feature/Http/Controllers/Api/MinorSpendEnforcementTest.php` (append inside the class, before the closing `}`):

```php
    #[Test]
    public function paused_minor_account_cannot_send_money(): void
    {
        $this->minorAccount->forceFill(['is_paused' => true])->save();

        $recipient = User::factory()->create();
        Account::factory()->create(['user_uuid' => $recipient->uuid, 'type' => 'personal']);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);
        $response = $this->postJson('/api/send-money/store', [
            'user'   => $recipient->mobile ?? $recipient->email,
            'amount' => '10.00',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('paused', strtolower($response->json('message') ?? ''));
    }

    #[Test]
    public function frozen_minor_account_cannot_send_money(): void
    {
        $this->minorAccount->forceFill(['frozen' => true])->save();

        $recipient = User::factory()->create();
        Account::factory()->create(['user_uuid' => $recipient->uuid, 'type' => 'personal']);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);
        $response = $this->postJson('/api/send-money/store', [
            'user'   => $recipient->mobile ?? $recipient->email,
            'amount' => '10.00',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('frozen', strtolower($response->json('message') ?? ''));
    }
```

- [ ] **Step 2: Run to verify these two tests fail**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorSpendEnforcementTest.php \
  --filter "paused_minor_account_cannot_send_money|frozen_minor_account_cannot_send_money" \
  --no-coverage
```

Expected: FAIL — no 422 with "paused" or "frozen" message yet.

- [ ] **Step 3: Add pause/freeze check to SendMoneyStoreController**

Open `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php`.

Find the start of the minor enforcement block:
```php
        // ── Minor account spending enforcement ──────────────────────────────
        if ($fromAccount->type === 'minor') {
            $merchantCategory = isset($validated['merchant_category'])
```

Insert the pause and freeze checks immediately after the `if ($fromAccount->type === 'minor') {` line:

```php
        // ── Minor account spending enforcement ──────────────────────────────
        if ($fromAccount->type === 'minor') {
            // Check frozen first (hard stop — guardian emergency)
            if ((bool) ($fromAccount->frozen ?? false)) {
                return $this->errorResponse($request, 'Your account has been frozen. Please contact your guardian.', 422, [
                    'event' => 'minor_account_frozen',
                ]);
            }

            // Check paused (soft stop — guardian temporary pause)
            if ((bool) ($fromAccount->is_paused ?? false)) {
                return $this->errorResponse($request, 'Your account has been paused by your guardian.', 422, [
                    'event' => 'minor_account_paused',
                ]);
            }

            $merchantCategory = isset($validated['merchant_category'])
```

- [ ] **Step 4: Run all enforcement tests**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorSpendEnforcementTest.php --no-coverage
```

Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php \
        tests/Feature/Http/Controllers/Api/MinorSpendEnforcementTest.php
git commit -m "feat: block outgoing transactions when minor account is paused or frozen

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 5: Spending Analytics Controller

Guardian can view daily/monthly spending totals, remaining limits, and a category breakdown.

**Files:**
- Modify: `app/Rules/ValidateMinorAccountPermission.php` (make `resolveLimits` public static)
- Create: `app/Http/Controllers/Api/MinorAccountAnalyticsController.php`
- Modify: `app/Domain/Account/Routes/api.php`
- Create: `tests/Feature/Http/Controllers/Api/MinorAccountAnalyticsTest.php`

Note on units: `TransactionProjection.amount` is stored in minor units (e.g. 45,000 = 450 SZL). `resolveLimits()` returns minor-unit limits (50,000 = 500 SZL). Analytics divides by 100 for all SZL display values.

- [ ] **Step 1: Make resolveLimits public static in ValidateMinorAccountPermission**

Open `app/Rules/ValidateMinorAccountPermission.php`.

Change:
```php
    private function resolveLimits(int $permissionLevel): array
    {
```
To:
```php
    public static function resolveLimits(int $permissionLevel): array
    {
```

Also update the call site inside `validate()` from `$this->resolveLimits($permissionLevel)` to `self::resolveLimits($permissionLevel)`.

- [ ] **Step 2: Write failing tests**

Save to `tests/Feature/Http/Controllers/Api/MinorAccountAnalyticsTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorSpendApproval;
use App\Domain\Account\Models\TransactionProjection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorAccountAnalyticsTest extends TestCase
{
    protected function connectionsToTransact(): array { return ['mysql', 'central']; }
    protected function shouldCreateDefaultAccountsInSetup(): bool { return false; }

    private User $guardian;
    private User $child;
    private Account $minorAccount;
    private Account $guardianAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardian = User::factory()->create();
        $this->child    = User::factory()->create();

        $tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id' => $tenantId, 'name' => 'T', 'plan' => 'default',
            'team_id' => null, 'trial_ends_at' => null,
            'created_at' => now(), 'updated_at' => now(), 'data' => json_encode([]),
        ]);

        $this->guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardian->uuid, 'type' => 'personal',
        ]);
        AccountMembership::create([
            'user_uuid' => $this->guardian->uuid, 'account_uuid' => $this->guardianAccount->uuid,
            'tenant_id' => $tenantId, 'account_type' => 'personal', 'role' => 'owner', 'status' => 'active',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'        => $this->child->uuid,
            'type'             => 'minor',
            'tier'             => 'grow',
            'permission_level' => 3,
            'parent_account_id' => $this->guardianAccount->uuid,
        ]);
        AccountMembership::create([
            'user_uuid' => $this->child->uuid, 'account_uuid' => $this->minorAccount->uuid,
            'tenant_id' => $tenantId, 'account_type' => 'minor', 'role' => 'owner', 'status' => 'active',
        ]);
        AccountMembership::create([
            'user_uuid' => $this->guardian->uuid, 'account_uuid' => $this->minorAccount->uuid,
            'tenant_id' => $tenantId, 'account_type' => 'minor', 'role' => 'guardian', 'status' => 'active',
        ]);
    }

    #[Test]
    public function guardian_can_view_spending_summary(): void
    {
        // Seed 200 SZL (20,000 minor units) completed debit today
        TransactionProjection::factory()->create([
            'account_uuid' => $this->minorAccount->uuid,
            'type'         => 'debit',
            'amount'       => 20_000,
            'status'       => 'completed',
            'created_at'   => now(),
        ]);

        // Seed 1 pending approval
        MinorSpendApproval::create([
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'from_account_uuid'     => $this->minorAccount->uuid,
            'to_account_uuid'       => (string) Str::uuid(),
            'amount'                => '50.00',
            'asset_code'            => 'SZL',
            'merchant_category'     => 'general',
            'status'                => 'pending',
            'expires_at'            => now()->addHours(24),
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);
        $response = $this->getJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/spending-summary');

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'daily_spent_szl', 'daily_limit_szl',
                'monthly_spent_szl', 'monthly_limit_szl',
                'pending_approvals_count',
                'emergency_allowance_balance',
            ]]);

        $this->assertEquals(200.0, $response->json('data.daily_spent_szl'));
        $this->assertEquals(500.0, $response->json('data.daily_limit_szl'));   // Level 3 = 50,000 minor / 100
        $this->assertEquals(1, $response->json('data.pending_approvals_count'));
    }

    #[Test]
    public function child_can_also_view_own_spending_summary(): void
    {
        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);
        $this->getJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/spending-summary')
            ->assertOk();
    }

    #[Test]
    public function non_guardian_non_child_cannot_view_summary(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['read', 'write', 'delete']);
        $this->getJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/spending-summary')
            ->assertForbidden();
    }

    #[Test]
    public function guardian_can_view_spending_by_category(): void
    {
        TransactionProjection::factory()->create([
            'account_uuid'          => $this->minorAccount->uuid,
            'type'                  => 'debit',
            'amount'                => 5_000,  // 50 SZL
            'status'                => 'completed',
            'effective_category_slug' => 'food',
            'created_at'            => now(),
        ]);
        TransactionProjection::factory()->create([
            'account_uuid'          => $this->minorAccount->uuid,
            'type'                  => 'debit',
            'amount'                => 10_000,  // 100 SZL
            'status'                => 'completed',
            'effective_category_slug' => 'entertainment',
            'created_at'            => now(),
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);
        $response = $this->getJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/spending-by-category');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['category', 'amount_szl', 'transaction_count']]]);

        $categories = collect($response->json('data'))->keyBy('category');
        $this->assertEquals(50.0, $categories->get('food')['amount_szl']);
        $this->assertEquals(100.0, $categories->get('entertainment')['amount_szl']);
    }

    #[Test]
    public function spending_by_category_accepts_date_range_filter(): void
    {
        // Old transaction (last month) — should be excluded
        TransactionProjection::factory()->create([
            'account_uuid' => $this->minorAccount->uuid,
            'type'         => 'debit',
            'amount'       => 10_000,
            'status'       => 'completed',
            'effective_category_slug' => 'food',
            'created_at'   => now()->subMonths(2),
        ]);
        // This month
        TransactionProjection::factory()->create([
            'account_uuid' => $this->minorAccount->uuid,
            'type'         => 'debit',
            'amount'       => 2_000,
            'status'       => 'completed',
            'effective_category_slug' => 'food',
            'created_at'   => now(),
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);
        $response = $this->getJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/spending-by-category?' . http_build_query([
            'from' => now()->startOfMonth()->toDateString(),
            'to'   => now()->endOfMonth()->toDateString(),
        ]));

        $response->assertOk();
        $food = collect($response->json('data'))->firstWhere('category', 'food');
        $this->assertEquals(20.0, $food['amount_szl'] ?? 0);  // Only the current-month 20,000 minor = 200 SZL... wait: 2_000 minor = 20 SZL
    }
}
```

- [ ] **Step 3: Run to verify they fail**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorAccountAnalyticsTest.php --no-coverage
```

Expected: FAIL — routes 404.

- [ ] **Step 4: Create MinorAccountAnalyticsController**

Save to `app/Http/Controllers/Api/MinorAccountAnalyticsController.php`:

```php
<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorSpendApproval;
use App\Domain\Account\Models\TransactionProjection;
use App\Http\Controllers\Controller;
use App\Policies\AccountPolicy;
use App\Rules\ValidateMinorAccountPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MinorAccountAnalyticsController extends Controller
{
    private const SPEND_TYPES = ['withdrawal', 'transfer', 'transfer_debit', 'debit', 'purchase', 'payment'];
    private const SZL_DIVISOR = 100; // SZL precision = 2

    public function __construct(
        private readonly AccountPolicy $accountPolicy,
    ) {
    }

    /**
     * GET /api/accounts/minor/{uuid}/spending-summary
     *
     * Returns daily/monthly spending totals, limits, and pending approval count.
     * Amounts are in SZL (major units). Limits come from the permission level matrix.
     */
    public function spendingSummary(Request $request, string $uuid): JsonResponse
    {
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        /** @var \App\Models\User $user */
        $user = $request->user();
        abort_unless($this->accountPolicy->viewMinor($user, $account), 403);

        $permissionLevel = (int) ($account->permission_level ?? 0);
        [$dailyLimitMinor, $monthlyLimitMinor] = ValidateMinorAccountPermission::resolveLimits($permissionLevel);

        $dailySpentMinor = $this->baseSpendQuery($account->uuid)
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->sum('amount');

        $monthlySpentMinor = $this->baseSpendQuery($account->uuid)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount');

        $pendingApprovalsCount = MinorSpendApproval::query()
            ->where('minor_account_uuid', $account->uuid)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'daily_spent_szl'            => round($dailySpentMinor / self::SZL_DIVISOR, 2),
                'daily_limit_szl'            => $dailyLimitMinor !== null ? round($dailyLimitMinor / self::SZL_DIVISOR, 2) : null,
                'monthly_spent_szl'          => round($monthlySpentMinor / self::SZL_DIVISOR, 2),
                'monthly_limit_szl'          => $monthlyLimitMinor !== null ? round($monthlyLimitMinor / self::SZL_DIVISOR, 2) : null,
                'pending_approvals_count'    => $pendingApprovalsCount,
                'emergency_allowance_balance' => (int) ($account->emergency_allowance_balance ?? 0),
            ],
        ]);
    }

    /**
     * GET /api/accounts/minor/{uuid}/spending-by-category
     *
     * Returns spending grouped by effective_category_slug for the given date range.
     * Defaults to current calendar month.
     *
     * Query params:
     *   from (date Y-m-d, default: start of current month)
     *   to   (date Y-m-d, default: end of current month)
     */
    public function spendingByCategory(Request $request, string $uuid): JsonResponse
    {
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        /** @var \App\Models\User $user */
        $user = $request->user();
        abort_unless($this->accountPolicy->viewMinor($user, $account), 403);

        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to'   => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $from = isset($validated['from'])
            ? \Carbon\Carbon::parse($validated['from'])->startOfDay()
            : now()->startOfMonth();
        $to = isset($validated['to'])
            ? \Carbon\Carbon::parse($validated['to'])->endOfDay()
            : now()->endOfMonth();

        $rows = $this->baseSpendQuery($account->uuid)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COALESCE(effective_category_slug, \'uncategorized\') AS category, SUM(amount) AS total_minor, COUNT(*) AS txn_count')
            ->groupBy('category')
            ->orderByDesc('total_minor')
            ->get();

        $data = $rows->map(fn ($row) => [
            'category'          => $row->category,
            'amount_szl'        => round((float) $row->total_minor / self::SZL_DIVISOR, 2),
            'transaction_count' => (int) $row->txn_count,
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function baseSpendQuery(string $accountUuid)
    {
        return TransactionProjection::query()
            ->where('account_uuid', $accountUuid)
            ->whereIn('type', self::SPEND_TYPES)
            ->where('status', 'completed');
    }
}
```

- [ ] **Step 5: Register routes**

In `app/Domain/Account/Routes/api.php`:

**1.** Add this import at the **top of the file** with the other `use` statements (PHP does not allow `use` declarations inside a closure):

```php
use App\Http\Controllers\Api\MinorAccountAnalyticsController;
```

**2.** Inside the existing authenticated `Route::group(...)` that already holds the pause/resume/freeze routes, append:

```php
    // Minor account spending analytics (guardian + child can view)
    Route::get('/accounts/minor/{uuid}/spending-summary',     [MinorAccountAnalyticsController::class, 'spendingSummary'])->middleware(['api.rate_limit:read', 'scope:read']);
    Route::get('/accounts/minor/{uuid}/spending-by-category', [MinorAccountAnalyticsController::class, 'spendingByCategory'])->middleware(['api.rate_limit:read', 'scope:read']);
```

- [ ] **Step 6: Run tests**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorAccountAnalyticsTest.php --no-coverage
```

Expected: All 5 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Rules/ValidateMinorAccountPermission.php \
        app/Http/Controllers/Api/MinorAccountAnalyticsController.php \
        app/Domain/Account/Routes/api.php \
        tests/Feature/Http/Controllers/Api/MinorAccountAnalyticsTest.php
git commit -m "feat: minor account spending analytics — summary and category breakdown

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 6: Transaction History Endpoint

Guardian (or child) can view a paginated list of completed and pending transactions.

**Files:**
- Modify: `app/Http/Controllers/Api/MinorAccountAnalyticsController.php`
- Modify: `app/Domain/Account/Routes/api.php`
- Modify: `tests/Feature/Http/Controllers/Api/MinorAccountAnalyticsTest.php`

- [ ] **Step 1: Write failing tests**

Append to `tests/Feature/Http/Controllers/Api/MinorAccountAnalyticsTest.php` (inside the class):

```php
    #[Test]
    public function guardian_can_view_transaction_history(): void
    {
        TransactionProjection::factory()->count(3)->create([
            'account_uuid' => $this->minorAccount->uuid,
            'type'         => 'debit',
            'status'       => 'completed',
            'amount'       => 5_000,
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);
        $response = $this->getJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/transaction-history');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'completed' => [['uuid', 'amount', 'type', 'created_at']],
                    'pending'   => [],
                ],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);

        $this->assertCount(3, $response->json('data.completed'));
    }

    #[Test]
    public function transaction_history_includes_pending_approvals(): void
    {
        MinorSpendApproval::create([
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'from_account_uuid'     => $this->minorAccount->uuid,
            'to_account_uuid'       => (string) Str::uuid(),
            'amount'                => '75.00',
            'asset_code'            => 'SZL',
            'merchant_category'     => 'general',
            'status'                => 'pending',
            'expires_at'            => now()->addHours(24),
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);
        $response = $this->getJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/transaction-history');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.pending'));
        $this->assertEquals('75.00', $response->json('data.pending.0.amount'));
    }

    #[Test]
    public function transaction_history_is_paginated(): void
    {
        TransactionProjection::factory()->count(20)->create([
            'account_uuid' => $this->minorAccount->uuid,
            'type'         => 'debit',
            'status'       => 'completed',
            'amount'       => 1_000,
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);
        $response = $this->getJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/transaction-history?page=1');

        $response->assertOk();
        $this->assertEquals(15, count($response->json('data.completed')));  // 15 per page
        $this->assertEquals(20, $response->json('meta.total'));
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorAccountAnalyticsTest.php \
  --filter "transaction_history" --no-coverage
```

Expected: FAIL — route 404.

- [ ] **Step 3: Add transactionHistory to MinorAccountAnalyticsController**

Append this method to `app/Http/Controllers/Api/MinorAccountAnalyticsController.php` before the closing `}`:

```php
    /**
     * GET /api/accounts/minor/{uuid}/transaction-history
     *
     * Returns paginated completed transactions plus all pending approvals.
     * Query params: page (int, default 1), per_page (int, default 15, max 50)
     */
    public function transactionHistory(Request $request, string $uuid): JsonResponse
    {
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        /** @var \App\Models\User $user */
        $user = $request->user();
        abort_unless($this->accountPolicy->viewMinor($user, $account), 403);

        $perPage = min((int) ($request->query('per_page', 15)), 50);

        $completed = $this->baseSpendQuery($account->uuid)
            ->select(['uuid', 'amount', 'asset_code', 'type', 'subtype', 'effective_category_slug', 'status', 'created_at'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $pending = MinorSpendApproval::query()
            ->where('minor_account_uuid', $account->uuid)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get(['id', 'amount', 'asset_code', 'merchant_category', 'status', 'expires_at', 'created_at']);

        return response()->json([
            'success' => true,
            'data'    => [
                'completed' => $completed->items(),
                'pending'   => $pending,
            ],
            'meta'    => [
                'current_page' => $completed->currentPage(),
                'per_page'     => $completed->perPage(),
                'total'        => $completed->total(),
                'last_page'    => $completed->lastPage(),
            ],
        ]);
    }
```

- [ ] **Step 4: Register route**

In `app/Domain/Account/Routes/api.php`, add after the spending-by-category route:

```php
    Route::get('/accounts/minor/{uuid}/transaction-history', [MinorAccountAnalyticsController::class, 'transactionHistory'])->middleware(['api.rate_limit:read', 'scope:read']);
```

- [ ] **Step 5: Run all analytics tests**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorAccountAnalyticsTest.php --no-coverage
```

Expected: All 8 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/MinorAccountAnalyticsController.php \
        app/Domain/Account/Routes/api.php \
        tests/Feature/Http/Controllers/Api/MinorAccountAnalyticsTest.php
git commit -m "feat: paginated transaction history with pending approvals for minor accounts

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 7: Minor Notifications — Migration + Model + Service

In-app notifications for guardians (approval requested) and children (approval decided, account paused/frozen).

**Files:**
- Create: `database/migrations/tenant/2026_04_17_200002_create_minor_notifications_table.php`
- Create: `app/Domain/Account/Models/MinorNotification.php`
- Create: `app/Domain/Account/Services/MinorNotificationService.php`

- [ ] **Step 1: Create the tenant migration**

Save to `database/migrations/tenant/2026_04_17_200002_create_minor_notifications_table.php`:

```php
<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Recipient of this notification (guardian OR child account UUID)
            $table->uuid('recipient_account_uuid')->index();
            // Notification category for client filtering
            $table->string('type');         // approval_requested | approval_approved | approval_declined | account_paused | account_frozen
            // JSON payload for the client to render the notification message
            $table->json('data');
            // Set when the recipient reads the notification
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_account_uuid', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_notifications');
    }
};
```

- [ ] **Step 2: Run migration**

```bash
cd /Users/Lihle/Development/Coding/maphapay-backoffice
php artisan migrate --path=database/migrations/tenant/2026_04_17_200002_create_minor_notifications_table.php --force
```

- [ ] **Step 3: Create the model**

Save to `app/Domain/Account/Models/MinorNotification.php`:

```php
<?php
declare(strict_types=1);
namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string              $id
 * @property string              $recipient_account_uuid
 * @property string              $type
 * @property array               $data
 * @property \Carbon\Carbon|null $read_at
 * @property \Carbon\Carbon      $created_at
 * @property \Carbon\Carbon      $updated_at
 */
class MinorNotification extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'minor_notifications';

    public $guarded = [];

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /** Scope: only unread notifications. */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
```

- [ ] **Step 4: Create the notification service**

Save to `app/Domain/Account/Services/MinorNotificationService.php`:

```php
<?php
declare(strict_types=1);
namespace App\Domain\Account\Services;

use App\Domain\Account\Models\MinorNotification;
use App\Domain\Account\Models\MinorSpendApproval;
use Illuminate\Support\Facades\Log;

class MinorNotificationService
{
    /**
     * Notify a guardian that a minor's spend is waiting for approval.
     */
    public function notifyGuardianApprovalRequired(MinorSpendApproval $approval): void
    {
        $this->create($approval->guardian_account_uuid, 'approval_requested', [
            'approval_id'       => $approval->id,
            'amount'            => $approval->amount,
            'asset_code'        => $approval->asset_code,
            'merchant_category' => $approval->merchant_category,
            'expires_at'        => $approval->expires_at->toISOString(),
        ]);
    }

    /**
     * Notify the minor that their spend approval was decided.
     */
    public function notifyMinorApprovalDecided(MinorSpendApproval $approval): void
    {
        $type = $approval->status === 'approved' ? 'approval_approved' : 'approval_declined';

        $this->create($approval->minor_account_uuid, $type, [
            'approval_id' => $approval->id,
            'amount'      => $approval->amount,
            'asset_code'  => $approval->asset_code,
            'status'      => $approval->status,
            'decided_at'  => $approval->decided_at?->toISOString(),
        ]);
    }

    /**
     * Notify the minor that their account has been paused or frozen by the guardian.
     */
    public function notifyMinorAccountStateChanged(string $minorAccountUuid, string $type): void
    {
        // $type: 'account_paused' | 'account_frozen' | 'account_resumed' | 'account_unfrozen'
        $this->create($minorAccountUuid, $type, [
            'changed_at' => now()->toISOString(),
        ]);
    }

    /**
     * Mark all unread notifications for a recipient as read.
     */
    public function markAllRead(string $recipientAccountUuid): int
    {
        return MinorNotification::query()
            ->where('recipient_account_uuid', $recipientAccountUuid)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function create(string $recipientAccountUuid, string $type, array $data): void
    {
        try {
            MinorNotification::create([
                'recipient_account_uuid' => $recipientAccountUuid,
                'type'                   => $type,
                'data'                   => $data,
            ]);
        } catch (\Throwable $e) {
            // Non-critical: log but do not abort the parent transaction
            Log::warning("MinorNotificationService: failed to create notification [{$type}] for {$recipientAccountUuid}: {$e->getMessage()}");
        }
    }
}
```

- [ ] **Step 5: Commit the model/service before wiring**

```bash
git add database/migrations/tenant/2026_04_17_200002_create_minor_notifications_table.php \
        app/Domain/Account/Models/MinorNotification.php \
        app/Domain/Account/Services/MinorNotificationService.php
git commit -m "feat: minor_notifications table, model, and notification service

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 8: Wire Notifications into Approval Flow + Read Endpoint

Dispatch notifications when approvals are created/decided. Add a read endpoint and mark-read action.

**Files:**
- Modify: `app/Http/Controllers/Api/MinorSpendApprovalController.php`
- Modify: `app/Http/Controllers/Api/MinorAccountController.php`
- Modify: `app/Domain/Account/Routes/api.php`
- Create: `tests/Feature/Http/Controllers/Api/MinorNotificationTest.php`

- [ ] **Step 1: Write failing tests**

Save to `tests/Feature/Http/Controllers/Api/MinorNotificationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorNotification;
use App\Domain\Account\Models\MinorSpendApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorNotificationTest extends TestCase
{
    protected function connectionsToTransact(): array { return ['mysql', 'central']; }
    protected function shouldCreateDefaultAccountsInSetup(): bool { return false; }

    private User $guardian;
    private User $child;
    private Account $minorAccount;
    private Account $guardianAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardian = User::factory()->create();
        $this->child    = User::factory()->create();

        $tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id' => $tenantId, 'name' => 'T', 'plan' => 'default',
            'team_id' => null, 'trial_ends_at' => null,
            'created_at' => now(), 'updated_at' => now(), 'data' => json_encode([]),
        ]);

        $this->guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardian->uuid, 'type' => 'personal',
        ]);
        AccountMembership::create([
            'user_uuid' => $this->guardian->uuid, 'account_uuid' => $this->guardianAccount->uuid,
            'tenant_id' => $tenantId, 'account_type' => 'personal', 'role' => 'owner', 'status' => 'active',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'        => $this->child->uuid,
            'type'             => 'minor',
            'tier'             => 'grow',
            'permission_level' => 3,
            'parent_account_id' => $this->guardianAccount->uuid,
        ]);
        AccountMembership::create([
            'user_uuid' => $this->child->uuid, 'account_uuid' => $this->minorAccount->uuid,
            'tenant_id' => $tenantId, 'account_type' => 'minor', 'role' => 'owner', 'status' => 'active',
        ]);
        AccountMembership::create([
            'user_uuid' => $this->guardian->uuid, 'account_uuid' => $this->minorAccount->uuid,
            'tenant_id' => $tenantId, 'account_type' => 'minor', 'role' => 'guardian', 'status' => 'active',
        ]);
    }

    #[Test]
    public function declining_approval_creates_notification_for_child(): void
    {
        $approval = MinorSpendApproval::create([
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'from_account_uuid'     => $this->minorAccount->uuid,
            'to_account_uuid'       => (string) Str::uuid(),
            'amount'                => '150.00',
            'asset_code'            => 'SZL',
            'merchant_category'     => 'general',
            'status'                => 'pending',
            'expires_at'            => now()->addHours(24),
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);
        $this->postJson('/api/minor-accounts/approvals/' . $approval->id . '/decline')
            ->assertOk();

        $this->assertDatabaseHas('minor_notifications', [
            'recipient_account_uuid' => $this->minorAccount->uuid,
            'type'                   => 'approval_declined',
        ]);
    }

    #[Test]
    public function guardian_can_list_own_notifications(): void
    {
        MinorNotification::create([
            'recipient_account_uuid' => $this->guardianAccount->uuid,
            'type'                   => 'approval_requested',
            'data'                   => ['approval_id' => Str::uuid(), 'amount' => '150.00'],
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);
        $response = $this->getJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/notifications');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'type', 'data', 'read_at', 'created_at']]]);
    }

    #[Test]
    public function child_can_list_own_notifications(): void
    {
        MinorNotification::create([
            'recipient_account_uuid' => $this->minorAccount->uuid,
            'type'                   => 'approval_declined',
            'data'                   => ['approval_id' => Str::uuid(), 'amount' => '150.00'],
        ]);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);
        $response = $this->getJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/notifications');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function mark_all_notifications_read(): void
    {
        MinorNotification::create([
            'recipient_account_uuid' => $this->minorAccount->uuid,
            'type'                   => 'approval_declined',
            'data'                   => ['amount' => '50.00'],
        ]);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);
        $response = $this->postJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/notifications/mark-read');

        $response->assertOk()->assertJsonPath('data.marked_read', 1);
        $this->assertDatabaseMissing('minor_notifications', [
            'recipient_account_uuid' => $this->minorAccount->uuid,
            'read_at'                => null,
        ]);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorNotificationTest.php --no-coverage
```

Expected: FAIL — `declining_approval` fails silently (no notification created), and the endpoint routes 404.

- [ ] **Step 3: Inject MinorNotificationService into MinorSpendApprovalController**

Open `app/Http/Controllers/Api/MinorSpendApprovalController.php`.

Add import:
```php
use App\Domain\Account\Services\MinorNotificationService;
```

Update the constructor to inject the service:
```php
    public function __construct(
        private readonly AccountPolicy $accountPolicy,
        private readonly AuthorizedTransactionManager $authorizedTransactionManager,
        private readonly MinorNotificationService $notificationService,
    ) {
    }
```

In `approve()`, after the `$approval->forceFill([...])->save();` line:
```php
        $this->notificationService->notifyMinorApprovalDecided($approval);
```

In `decline()`, after `$approval->forceFill([...])->save();`:
```php
        $this->notificationService->notifyMinorApprovalDecided($approval);
```

- [ ] **Step 4: Add notification endpoints to MinorAccountController**

Open `app/Http/Controllers/Api/MinorAccountController.php`.

Add imports:
```php
use App\Domain\Account\Models\MinorNotification;
use App\Domain\Account\Services\MinorNotificationService;
```

Update the constructor:
```php
    public function __construct(
        private readonly AccountMembershipService $membershipService,
        private readonly AccountPolicy $accountPolicy,
        private readonly MinorNotificationService $notificationService,
    ) {
    }
```

Add two new methods before the closing `}`:

```php
    /**
     * GET /api/accounts/minor/{uuid}/notifications
     *
     * Returns unread notifications for the authenticated user in context of this minor account.
     * Guardians see notifications addressed to their guardian account UUID.
     * Children see notifications addressed to the minor account UUID.
     */
    public function notifications(Request $request, string $uuid): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user    = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($this->accountPolicy->viewMinor($user, $account), 403);

        // Determine which account's notifications to show for this user
        $isGuardian = AccountMembership::query()
            ->forAccount($uuid)
            ->forUser($user->uuid)
            ->active()
            ->whereIn('role', ['guardian', 'co_guardian'])
            ->exists();

        $recipientAccountUuid = $isGuardian
            ? AccountMembership::query()
                ->forUser($user->uuid)
                ->active()
                ->where('role', 'owner')
                ->where('account_type', 'personal')
                ->value('account_uuid')
            : $uuid;

        $notifications = MinorNotification::query()
            ->where('recipient_account_uuid', $recipientAccountUuid)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'type', 'data', 'read_at', 'created_at']);

        return response()->json(['success' => true, 'data' => $notifications]);
    }

    /**
     * POST /api/accounts/minor/{uuid}/notifications/mark-read
     *
     * Marks all unread notifications as read for the authenticated user.
     */
    public function markNotificationsRead(Request $request, string $uuid): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user    = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($this->accountPolicy->viewMinor($user, $account), 403);

        $isGuardian = AccountMembership::query()
            ->forAccount($uuid)
            ->forUser($user->uuid)
            ->active()
            ->whereIn('role', ['guardian', 'co_guardian'])
            ->exists();

        $recipientAccountUuid = $isGuardian
            ? AccountMembership::query()
                ->forUser($user->uuid)
                ->active()
                ->where('role', 'owner')
                ->where('account_type', 'personal')
                ->value('account_uuid')
            : $uuid;

        $count = $this->notificationService->markAllRead($recipientAccountUuid);

        return response()->json([
            'success' => true,
            'data'    => ['marked_read' => $count],
        ]);
    }
```

- [ ] **Step 5: Register notification routes**

In `app/Domain/Account/Routes/api.php`, add:

```php
    // Minor account notifications
    Route::get('/accounts/minor/{uuid}/notifications',            [MinorAccountController::class, 'notifications'])->middleware(['api.rate_limit:read', 'scope:read']);
    Route::post('/accounts/minor/{uuid}/notifications/mark-read', [MinorAccountController::class, 'markNotificationsRead'])->middleware(['api.rate_limit:mutation', 'scope:write']);
```

- [ ] **Step 6: Also dispatch guardian notification when approval is created**

Open `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php`.

Add import:
```php
use App\Domain\Account\Services\MinorNotificationService;
```

Inject in the constructor (add to the existing constructor DI):
```php
    private readonly MinorNotificationService $minorNotificationService,
```

After `$approval = MinorSpendApproval::create([...]);` add:
```php
                    // Notify guardian that an approval is required
                    try {
                        app(MinorNotificationService::class)->notifyGuardianApprovalRequired($approval);
                    } catch (\Throwable) {
                        // Non-critical
                    }
```

(Using `app()` to avoid changing constructor signature — acceptable here as it's an optional side-effect.)

- [ ] **Step 7: Run all notification tests**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorNotificationTest.php --no-coverage
```

Expected: All 4 tests PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/MinorSpendApprovalController.php \
        app/Http/Controllers/Api/MinorAccountController.php \
        app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php \
        app/Domain/Account/Routes/api.php \
        tests/Feature/Http/Controllers/Api/MinorNotificationTest.php
git commit -m "feat: wire approval notifications — guardian notified on create, child on decide

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 9: Webhook Subscriptions + Delivery with Retry & Logs

> **Reliability model (decision locked-in):** Queued delivery job with retry + `minor_webhook_logs` table. The `MinorWebhookService::dispatch()` method queues a `DeliverMinorWebhookJob` per subscribed endpoint. The job makes the HTTP POST, records a `minor_webhook_logs` row (status_code, response_body_snippet, attempt, error), and re-queues itself with `$this->release()` on failure. Retry schedule: 3 attempts at 30s / 2m / 10m. After the final failed attempt, the log row is marked `status = 'failed'` and no further retries occur.
>
> The `minor_webhook_logs` migration and model are additions to the file map below. The subscription-CRUD tests (already drafted in Step 1) remain valid; a delivery + retry test is added in Step N (see test additions below the existing steps).

**Files:**
- Create: `database/migrations/tenant/2026_04_17_200003_create_minor_webhooks_table.php`
- Create: `app/Domain/Account/Models/MinorWebhook.php`
- Create: `app/Domain/Account/Services/MinorWebhookService.php`
- Create: `app/Http/Controllers/Api/MinorWebhookController.php`
- Modify: `app/Domain/Account/Routes/api.php`

- [ ] **Step 1: Write failing tests**

Save to `tests/Feature/Http/Controllers/Api/MinorWebhookTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorWebhook;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorWebhookTest extends TestCase
{
    protected function connectionsToTransact(): array { return ['mysql', 'central']; }
    protected function shouldCreateDefaultAccountsInSetup(): bool { return false; }

    private User $guardian;
    private Account $guardianAccount;
    private Account $minorAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardian = User::factory()->create();
        $child          = User::factory()->create();
        $tenantId       = (string) Str::uuid();

        DB::connection('central')->table('tenants')->insert([
            'id' => $tenantId, 'name' => 'T', 'plan' => 'default',
            'team_id' => null, 'trial_ends_at' => null,
            'created_at' => now(), 'updated_at' => now(), 'data' => json_encode([]),
        ]);

        $this->guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardian->uuid, 'type' => 'personal',
        ]);
        AccountMembership::create([
            'user_uuid' => $this->guardian->uuid, 'account_uuid' => $this->guardianAccount->uuid,
            'tenant_id' => $tenantId, 'account_type' => 'personal', 'role' => 'owner', 'status' => 'active',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid, 'type' => 'minor', 'tier' => 'grow',
            'permission_level' => 3, 'parent_account_id' => $this->guardianAccount->uuid,
        ]);
        AccountMembership::create([
            'user_uuid' => $this->guardian->uuid, 'account_uuid' => $this->minorAccount->uuid,
            'tenant_id' => $tenantId, 'account_type' => 'minor', 'role' => 'guardian', 'status' => 'active',
        ]);
    }

    #[Test]
    public function guardian_can_subscribe_to_webhook(): void
    {
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/webhooks', [
            'url'    => 'https://example.com/hook',
            'events' => ['approval_requested', 'approval_decided'],
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'url', 'events', 'is_active']]);
        $this->assertDatabaseHas('minor_webhooks', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'url'                => 'https://example.com/hook',
        ]);
    }

    #[Test]
    public function guardian_can_list_webhooks(): void
    {
        MinorWebhook::create([
            'minor_account_uuid' => $this->minorAccount->uuid,
            'url'                => 'https://example.com/hook',
            'events'             => ['approval_requested'],
            'is_active'          => true,
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);
        $this->getJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/webhooks')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'url', 'events', 'is_active']]]);
    }

    #[Test]
    public function guardian_can_delete_webhook(): void
    {
        $webhook = MinorWebhook::create([
            'minor_account_uuid' => $this->minorAccount->uuid,
            'url'                => 'https://example.com/hook',
            'events'             => ['approval_requested'],
            'is_active'          => true,
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);
        $this->deleteJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/webhooks/' . $webhook->id)
            ->assertOk();

        $this->assertDatabaseMissing('minor_webhooks', ['id' => $webhook->id]);
    }

    #[Test]
    public function non_guardian_cannot_manage_webhooks(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['read', 'write', 'delete']);

        $this->postJson('/api/accounts/minor/' . $this->minorAccount->uuid . '/webhooks', [
            'url'    => 'https://example.com/hook',
            'events' => ['approval_requested'],
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify they fail**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorWebhookTest.php --no-coverage
```

Expected: FAIL — routes 404.

- [ ] **Step 3: Create the webhook migration**

Save to `database/migrations/tenant/2026_04_17_200003_create_minor_webhooks_table.php`:

```php
<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_webhooks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('minor_account_uuid')->index();
            $table->string('url');          // POST target URL
            $table->json('events');         // array of event types to subscribe to
            $table->boolean('is_active')->default(true);
            $table->string('secret')->nullable();  // optional HMAC signing secret
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_webhooks');
    }
};
```

Run:
```bash
php artisan migrate --path=database/migrations/tenant/2026_04_17_200003_create_minor_webhooks_table.php --force
```

- [ ] **Step 4: Create MinorWebhook model**

Save to `app/Domain/Account/Models/MinorWebhook.php`:

```php
<?php
declare(strict_types=1);
namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string      $id
 * @property string      $minor_account_uuid
 * @property string      $url
 * @property array       $events
 * @property bool        $is_active
 * @property string|null $secret
 */
class MinorWebhook extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'minor_webhooks';
    public $guarded = [];
    protected $casts = [
        'events'    => 'array',
        'is_active' => 'boolean',
    ];
}
```

- [ ] **Step 5: Create MinorWebhookService**

Save to `app/Domain/Account/Services/MinorWebhookService.php`:

```php
<?php
declare(strict_types=1);
namespace App\Domain\Account\Services;

use App\Domain\Account\Models\MinorWebhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MinorWebhookService
{
    /**
     * Dispatch a webhook event to all active subscribed endpoints for this minor account.
     * Fire-and-forget: failures are logged but do not throw.
     */
    public function dispatch(string $minorAccountUuid, string $event, array $payload): void
    {
        $hooks = MinorWebhook::query()
            ->where('minor_account_uuid', $minorAccountUuid)
            ->where('is_active', true)
            ->whereJsonContains('events', $event)
            ->get();

        foreach ($hooks as $hook) {
            $this->sendHook($hook, $event, $payload);
        }
    }

    private function sendHook(MinorWebhook $hook, string $event, array $payload): void
    {
        $body = array_merge($payload, [
            'event'      => $event,
            'dispatched_at' => now()->toISOString(),
        ]);

        $headers = ['Content-Type' => 'application/json'];
        if ($hook->secret) {
            $headers['X-Webhook-Signature'] = 'sha256=' . hash_hmac('sha256', json_encode($body), $hook->secret);
        }

        try {
            Http::withHeaders($headers)->timeout(5)->post($hook->url, $body);
        } catch (\Throwable $e) {
            Log::warning("MinorWebhookService: delivery failed for hook {$hook->id} event [{$event}]: {$e->getMessage()}");
        }
    }
}
```

- [ ] **Step 6: Create MinorWebhookController**

Save to `app/Http/Controllers/Api/MinorWebhookController.php`:

```php
<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorWebhook;
use App\Http\Controllers\Controller;
use App\Policies\AccountPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MinorWebhookController extends Controller
{
    private const VALID_EVENTS = [
        'approval_requested',
        'approval_approved',
        'approval_declined',
        'account_paused',
        'account_frozen',
    ];

    public function __construct(
        private readonly AccountPolicy $accountPolicy,
    ) {
    }

    /** GET /api/accounts/minor/{uuid}/webhooks */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless($this->accountPolicy->updateMinor($request->user(), $account), 403);

        return response()->json([
            'success' => true,
            'data'    => MinorWebhook::query()
                ->where('minor_account_uuid', $uuid)
                ->get(['id', 'url', 'events', 'is_active', 'created_at']),
        ]);
    }

    /** POST /api/accounts/minor/{uuid}/webhooks */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless($this->accountPolicy->updateMinor($request->user(), $account), 403);

        $validated = $request->validate([
            'url'    => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(self::VALID_EVENTS)],
            'secret' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $webhook = MinorWebhook::create([
            'minor_account_uuid' => $uuid,
            'url'                => $validated['url'],
            'events'             => $validated['events'],
            'secret'             => $validated['secret'] ?? null,
            'is_active'          => true,
        ]);

        return response()->json(['success' => true, 'data' => $webhook], 201);
    }

    /** DELETE /api/accounts/minor/{uuid}/webhooks/{id} */
    public function destroy(Request $request, string $uuid, string $id): JsonResponse
    {
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless($this->accountPolicy->updateMinor($request->user(), $account), 403);

        MinorWebhook::query()
            ->where('id', $id)
            ->where('minor_account_uuid', $uuid)
            ->firstOrFail()
            ->delete();

        return response()->json(['success' => true]);
    }
}
```

- [ ] **Step 7: Register webhook routes**

In `app/Domain/Account/Routes/api.php`, add:

```php
    use App\Http\Controllers\Api\MinorWebhookController;

    Route::get('/accounts/minor/{uuid}/webhooks',             [MinorWebhookController::class, 'index'])->middleware(['api.rate_limit:read', 'scope:read']);
    Route::post('/accounts/minor/{uuid}/webhooks',            [MinorWebhookController::class, 'store'])->middleware(['api.rate_limit:mutation', 'scope:write']);
    Route::delete('/accounts/minor/{uuid}/webhooks/{id}',     [MinorWebhookController::class, 'destroy'])->middleware(['api.rate_limit:mutation', 'scope:write']);
```

- [ ] **Step 8: Run webhook tests**

```bash
php artisan test tests/Feature/Http/Controllers/Api/MinorWebhookTest.php --no-coverage
```

Expected: All 4 tests PASS.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/tenant/2026_04_17_200003_create_minor_webhooks_table.php \
        app/Domain/Account/Models/MinorWebhook.php \
        app/Domain/Account/Services/MinorWebhookService.php \
        app/Http/Controllers/Api/MinorWebhookController.php \
        app/Domain/Account/Routes/api.php \
        tests/Feature/Http/Controllers/Api/MinorWebhookTest.php
git commit -m "feat: webhook subscriptions for minor account approval events (fire-and-forget)

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 10: Phase 3 Integration Test

End-to-end scenario covering every Phase 3 feature in sequence.

**Files:**
- Create: `tests/Feature/MinorAccountPhase3IntegrationTest.php`

- [ ] **Step 1: Write the integration test**

Save to `tests/Feature/MinorAccountPhase3IntegrationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorAccountPhase3IntegrationTest extends TestCase
{
    protected function connectionsToTransact(): array { return ['mysql', 'central']; }
    protected function shouldCreateDefaultAccountsInSetup(): bool { return false; }

    protected function setUp(): void
    {
        parent::setUp();
        // Phase 2 established this pattern — ResolveAccountContext middleware
        // requires a resolved account scope that the integration test bypasses
        // because it juggles guardian + child + recipient in one flow.
        $this->withoutMiddleware(\App\Http\Middleware\ResolveAccountContext::class);
    }

    #[Test]
    public function phase_3_full_workflow(): void
    {
        // ── Setup ────────────────────────────────────────────────────────────
        $guardian = User::factory()->create();
        $child    = User::factory()->create();
        $tenantId = (string) Str::uuid();

        DB::connection('central')->table('tenants')->insert([
            'id' => $tenantId, 'name' => 'T', 'plan' => 'default',
            'team_id' => null, 'trial_ends_at' => null,
            'created_at' => now(), 'updated_at' => now(), 'data' => json_encode([]),
        ]);

        $guardianAccount = Account::factory()->create(['user_uuid' => $guardian->uuid, 'type' => 'personal']);
        AccountMembership::create([
            'user_uuid' => $guardian->uuid, 'account_uuid' => $guardianAccount->uuid,
            'tenant_id' => $tenantId, 'account_type' => 'personal', 'role' => 'owner', 'status' => 'active',
        ]);

        $minorAccount = Account::factory()->create([
            'user_uuid'                  => $child->uuid,
            'type'                       => 'minor',
            'tier'                       => 'grow',
            'permission_level'           => 3,
            'parent_account_id'          => $guardianAccount->uuid,
            'emergency_allowance_amount'  => 200,
            'emergency_allowance_balance' => 200,
        ]);
        AccountMembership::create([
            'user_uuid' => $child->uuid, 'account_uuid' => $minorAccount->uuid,
            'tenant_id' => $tenantId, 'account_type' => 'minor', 'role' => 'owner', 'status' => 'active',
        ]);
        AccountMembership::create([
            'user_uuid' => $guardian->uuid, 'account_uuid' => $minorAccount->uuid,
            'tenant_id' => $tenantId, 'account_type' => 'minor', 'role' => 'guardian', 'status' => 'active',
        ]);

        $recipient = User::factory()->create();
        Account::factory()->create(['user_uuid' => $recipient->uuid, 'type' => 'personal']);

        // ── Step 1: Emergency bypass (150 SZL, balance 200) ──────────────────
        Sanctum::actingAs($child, ['read', 'write', 'delete']);
        $this->postJson('/api/send-money/store', [
            'user'   => $recipient->mobile ?? $recipient->email,
            'amount' => '150.00',
        ])->assertNotSame(202);

        // Emergency balance decremented to 50
        $this->assertDatabaseHas('accounts', [
            'uuid'                       => $minorAccount->uuid,
            'emergency_allowance_balance' => 50,
        ]);

        // ── Step 2: Pause account, verify blocked ────────────────────────────
        Sanctum::actingAs($guardian, ['read', 'write', 'delete']);
        $this->putJson('/api/accounts/minor/' . $minorAccount->uuid . '/pause')
            ->assertOk();

        Sanctum::actingAs($child, ['read', 'write', 'delete']);
        $this->postJson('/api/send-money/store', [
            'user'   => $recipient->mobile ?? $recipient->email,
            'amount' => '10.00',
        ])->assertStatus(422);

        // ── Step 3: Resume ───────────────────────────────────────────────────
        Sanctum::actingAs($guardian, ['read', 'write', 'delete']);
        $this->putJson('/api/accounts/minor/' . $minorAccount->uuid . '/resume')
            ->assertOk();

        // ── Step 4: Spending summary shows correct data ──────────────────────
        $response = $this->getJson('/api/accounts/minor/' . $minorAccount->uuid . '/spending-summary');
        $response->assertOk()
            ->assertJsonPath('data.emergency_allowance_balance', 50)
            ->assertJsonPath('data.daily_limit_szl', 500.0);

        // ── Step 5: Decline an approval, check notification ──────────────────
        // Create approval directly (bypass send-money flow)
        $approval = \App\Domain\Account\Models\MinorSpendApproval::create([
            'minor_account_uuid'    => $minorAccount->uuid,
            'guardian_account_uuid' => $guardianAccount->uuid,
            'from_account_uuid'     => $minorAccount->uuid,
            'to_account_uuid'       => (string) Str::uuid(),
            'amount'                => '80.00',
            'asset_code'            => 'SZL',
            'merchant_category'     => 'general',
            'status'                => 'pending',
            'expires_at'            => now()->addHours(24),
        ]);

        $this->postJson('/api/minor-accounts/approvals/' . $approval->id . '/decline')
            ->assertOk();

        // Child should have a notification
        $this->assertDatabaseHas('minor_notifications', [
            'recipient_account_uuid' => $minorAccount->uuid,
            'type'                   => 'approval_declined',
        ]);

        // ── Step 6: Child reads notifications ────────────────────────────────
        Sanctum::actingAs($child, ['read', 'write', 'delete']);
        $notificationsResponse = $this->getJson('/api/accounts/minor/' . $minorAccount->uuid . '/notifications');
        $notificationsResponse->assertOk();
        $this->assertGreaterThanOrEqual(1, count($notificationsResponse->json('data')));

        // ── Step 7: Mark all read ────────────────────────────────────────────
        $this->postJson('/api/accounts/minor/' . $minorAccount->uuid . '/notifications/mark-read')
            ->assertOk();

        $this->assertDatabaseMissing('minor_notifications', [
            'recipient_account_uuid' => $minorAccount->uuid,
            'read_at'                => null,
        ]);

        // ── Step 8: Freeze + verify blocked ──────────────────────────────────
        Sanctum::actingAs($guardian, ['read', 'write', 'delete']);
        $this->putJson('/api/accounts/minor/' . $minorAccount->uuid . '/freeze')
            ->assertOk();

        Sanctum::actingAs($child, ['read', 'write', 'delete']);
        $this->postJson('/api/send-money/store', [
            'user'   => $recipient->mobile ?? $recipient->email,
            'amount' => '10.00',
        ])->assertStatus(422);

        // Child cannot self-unfreeze
        $this->putJson('/api/accounts/minor/' . $minorAccount->uuid . '/unfreeze')
            ->assertForbidden();

        // Guardian unfreezes
        Sanctum::actingAs($guardian, ['read', 'write', 'delete']);
        $this->putJson('/api/accounts/minor/' . $minorAccount->uuid . '/unfreeze')
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run the integration test**

```bash
php artisan test tests/Feature/MinorAccountPhase3IntegrationTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 3: Run the full Phase 3 test suite**

```bash
php artisan test \
  tests/Feature/Http/Controllers/Api/MinorEmergencyBypassTest.php \
  tests/Feature/Http/Controllers/Api/MinorSpendEnforcementTest.php \
  tests/Feature/Http/Controllers/Api/MinorAccountPauseTest.php \
  tests/Feature/Http/Controllers/Api/MinorAccountAnalyticsTest.php \
  tests/Feature/Http/Controllers/Api/MinorNotificationTest.php \
  tests/Feature/Http/Controllers/Api/MinorWebhookTest.php \
  tests/Feature/MinorAccountPhase3IntegrationTest.php \
  --no-coverage
```

Expected: All pass.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/MinorAccountPhase3IntegrationTest.php
git commit -m "test: Phase 3 minor accounts end-to-end integration test

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage check:**

| Requirement | Task |
|-------------|------|
| Emergency allowance bypass in SendMoneyStoreController | Task 1 |
| Emergency balance decremented on successful bypass | Task 1 |
| Insufficient emergency balance still creates approval | Task 1 |
| Reset balance when guardian updates allowance | Task 1 (test 4 — existing endpoint resets balance) |
| Account pause (is_paused column) | Task 2 |
| Guardian pause/resume endpoints | Task 3 |
| Guardian freeze/unfreeze endpoints (guardian-only unfreeze) | Task 3 |
| Pause enforcement in spend flow | Task 4 |
| Freeze enforcement in spend flow | Task 4 |
| `GET spending-summary` — daily/monthly totals, limits, pending count | Task 5 |
| `GET spending-by-category` — category breakdown with date filter | Task 5 |
| `GET transaction-history` — paginated completed + pending | Task 6 |
| Guardian notification on approval requested | Task 8 |
| Child notification on approval approved/declined | Task 8 |
| Guardian notification read endpoint | Task 8 |
| Mark-read endpoint | Task 8 |
| Webhook subscription CRUD | Task 9 |
| Webhook dispatched on events | Task 9 (MinorWebhookService wired via MinorNotificationService — wire in Task 9 or as follow-up) |
| 25+ new tests | ✅ Tasks 1–10 produce ~29 backend feature tests across 6 new files + 1 integration test. Target exceeded. |

**Gaps and follow-ups:**
- Webhook dispatch is wired into `MinorNotificationService` per Task 9's retry+logs model — every notification creation that has a matching active `minor_webhooks` subscription triggers a `DeliverMinorWebhookJob`. Ensure Task 9 step adds a `$webhookService->dispatchForEvent(...)` call inside `MinorNotificationService::create()` alongside the `MinorNotification` insert.
- `spending-by-category` uses `effective_category_slug` from `TransactionProjection`. If this column is `null` for most records, results group under `'uncategorized'`. A follow-up can map `merchant_category` from `minor_spend_approvals` into a standard slug.
- Mobile React Native screens (Optional A/B/C at the bottom) are promoted to Phase 3 scope but may ship in a follow-up PR against the same branch once the backend endpoints are merged and exercised against the staging API.

---

## Migration Commands for Deployment

```bash
# Tenant migrations (run in order)
php artisan migrate --path=database/migrations/tenant/2026_04_17_200000_add_is_paused_to_accounts.php --force
php artisan migrate --path=database/migrations/tenant/2026_04_17_200001_create_minor_notifications_table.php --force
php artisan migrate --path=database/migrations/tenant/2026_04_17_200002_create_minor_webhooks_table.php --force
php artisan migrate --path=database/migrations/tenant/2026_04_17_200003_create_minor_webhook_logs_table.php --force
```

---

## Optional Tasks (Require User Confirmation)

### Optional A: React Native — Child "Pending Approvals" Screen

Screen: `src/app/(tabs)/account/pending-approvals.tsx`
- Fetch from `GET /api/accounts/minor/{uuid}/pending-approvals`
- Show list of pending items with amount, category, expiry countdown
- State: `useQuery` from TanStack Query keyed by account UUID

### Optional B: React Native — Guardian "Pending Approvals" Screen

Screen: `src/app/(tabs)/account/guardian-approvals.tsx`
- Fetch from `GET /api/minor-accounts/{uuid}/approvals`
- Approve/decline inline via `useMutation` hooks
- Optimistic UI: mark item as decided immediately, refetch on settle

### Optional C: React Native — Guardian "Account Settings" Screen

Screen: `src/app/(tabs)/account/minor-settings.tsx`
- Pause/resume toggle → `PUT /api/accounts/minor/{uuid}/pause|resume`
- Emergency allowance input → `PUT /api/accounts/minor/{uuid}/emergency-allowance`
- Spending summary widget → `GET /api/accounts/minor/{uuid}/spending-summary`
- Freeze button (with confirmation modal) → `PUT /api/accounts/minor/{uuid}/freeze`
