# Minor Accounts Phase 11 Merchant QR Integration - API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Mobile API endpoints for merchant discovery with minor-bonus eligibility, bonus details endpoint, internal calculation endpoint for QR payment hook, and Filament admin enhancements.

**Architecture:** Build on existing MobileCommerceController and merchant_partners table. Extend with minor-specific fields, create bonus service for calculations, add API endpoints, enhance Filament resource.

**Tech Stack:** Laravel 12, Filament v3, GraphQL (Lighthouse), Mobile API

---

## Context

Phase 11 spec defined the data model for merchant bonus system but the implementation was not completed:
- merchant_partners table exists with base schema (name, category, logo_url, qr_endpoint, api_key, commission_rate, payout_schedule, is_active)
- Missing: minor-specific fields (bonus_multiplier, min_age_allowance, category_slugs, is_active_for_minors, bonus_terms, tenant_id, updated_by)
- MinorMerchantBonusService does not exist
- API endpoints need bonus metadata

Phase 11 scope from prompt:
- Mobile API endpoints for merchant discovery with minor-bonus eligibility
- Bonus details endpoint per merchant
- Internal calculation endpoint for QR payment hook
- Filament admin enhancements

---

## File Structure

### New Files to Create

| File | Responsibility |
|---|---|
| `app/Domain/Account/Services/MinorMerchantBonusService.php` | Bonus calculation logic, eligibility checks |
| `app/Domain/Account/Models/MinorMerchantBonusTransaction.php` | Persisted bonus award records |
| `app/Http/Controllers/Api/Commerce/MinorMerchantBonusController.php` | Internal API endpoint for bonus calculation |
| `database/migrations/2026_04_23_000001_add_minor_fields_to_merchant_partners_table.php` | Add minor-specific fields |
| `database/migrations/2026_04_23_000002_create_minor_merchant_bonus_transactions_table.php` | Bonus transaction records |
| `tests/Unit/Domain/Account/Services/MinorMerchantBonusServiceTest.php` | Unit tests for bonus service |
| `tests/Feature/Api/MinorMerchantBonusApiTest.php` | API endpoint tests |

### Files to Modify

| File | Change |
|---|---|
| `app/Models/MerchantPartner.php` | Add minor fields to $fillable and $casts |
| `app/Http/Controllers/Api/Commerce/MobileCommerceController.php` | Add bonus metadata to merchants list and detail |
| `routes/api.php` | Add internal bonus endpoint route |
| `app/Filament/Admin/Resources/MerchantPartnerResource.php` | Add minor fields, filters, actions |

---

## Task 1: Data Layer - Merchant Partners Minor Fields Migration

**Files:**
- Create: `database/migrations/2026_04_23_000001_add_minor_fields_to_merchant_partners_table.php`
- Modify: `app/Models/MerchantPartner.php:13-22`

- [ ] **Step 1: Write the failing test (verify model lacks minor fields)**

File: Create test that checks MerchantPartner has bonus_multiplier in fillable
```php
// tests/Unit/Domain/Account/Models/MerchantPartnerMinorFieldsTest.php
<?php

namespace Tests\Unit\Domain\Account\Models;

use App\Models\MerchantPartner;
use Tests\TestCase;

class MerchantPartnerMinorFieldsTest extends TestCase
{
    public function test_merchant_partner_has_minor_bonus_fields_in_fillable(): void
    {
        $partner = new MerchantPartner();
        $fillable = $partner->getFillable();
        
        $this->assertContains('bonus_multiplier', $fillable);
        $this->assertContains('min_age_allowance', $fillable);
        $this->assertContains('category_slugs', $fillable);
        $this->assertContains('is_active_for_minors', $fillable);
        $this->assertContains('bonus_terms', $fillable);
        $this->assertContains('tenant_id', $fillable);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Account/Models/MerchantPartnerMinorFieldsTest.php`
Expected: FAIL - bonus_multiplier not in fillable

- [ ] **Step 3: Write migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('merchant_partners', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->decimal('bonus_multiplier', 3, 2)->default(2.0)->after('tenant_id');
            $table->smallInteger('min_age_allowance')->default(0)->after('bonus_multiplier');
            $table->json('category_slugs')->nullable()->after('min_age_allowance');
            $table->boolean('is_active_for_minors')->default(true)->after('category_slugs');
            $table->text('bonus_terms')->nullable()->after('is_active_for_minors');
            $table->uuid('updated_by')->nullable()->after('bonus_terms');
            
            $table->index('tenant_id');
            $table->index('is_active_for_minors');
            $table->index('min_age_allowance');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_partners', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropIndex(['is_active_for_minors']);
            $table->dropIndex(['min_age_allowance']);
            $table->dropColumn([
                'tenant_id',
                'bonus_multiplier',
                'min_age_allowance',
                'category_slugs',
                'is_active_for_minors',
                'bonus_terms',
                'updated_by',
            ]);
        });
    }
};
```

- [ ] **Step 4: Run migration**

Run: `php artisan migrate`
Expected: Table updated with new columns

- [ ] **Step 5: Update MerchantPartner model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantPartner extends Model
{
    protected $table = 'merchant_partners';

    protected $fillable = [
        'name',
        'category',
        'logo_url',
        'qr_endpoint',
        'api_key',
        'commission_rate',
        'payout_schedule',
        'is_active',
        // Minor-specific fields
        'tenant_id',
        'bonus_multiplier',
        'min_age_allowance',
        'category_slugs',
        'is_active_for_minors',
        'bonus_terms',
        'updated_by',
    ];

    protected $casts = [
        'commission_rate'    => 'decimal:2',
        'bonus_multiplier'   => 'decimal:2',
        'category_slugs'    => 'array',
        'is_active'        => 'boolean',
        'is_active_for_minors' => 'boolean',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public function getBonusMultiplier(): float
    {
        return (float) ($this->bonus_multiplier ?? 2.0);
    }

    public function getMinAgeAllowance(): int
    {
        return (int) ($this->min_age_allowance ?? 0);
    }

    public function isActiveForMinors(): bool
    {
        return (bool) ($this->is_active_for_minors ?? true);
    }

    public function isEligibleForMinors(int $minorAge, ?array $categorySlugs = null): bool
    {
        if (! $this->isActiveForMinors()) {
            return false;
        }

        if ($minorAge < $this->getMinAgeAllowance()) {
            return false;
        }

        if ($categorySlugs !== null && $this->category_slugs !== null) {
            $intersection = array_intersect($categorySlugs, $this->category_slugs);
            if (empty($intersection)) {
                return false;
            }
        }

        return true;
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Account/Models/MerchantPartnerMinorFieldsTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Models/MerchantPartner.php database/migrations/2026_04_23_000001_add_minor_fields_to_merchant_partners_table.php
git commit -m "feat(minor-accounts): add minor-specific fields to merchant_partners table"
```

---

## Task 2: Data Layer - Bonus Transactions Table

**Files:**
- Create: `database/migrations/2026_04_23_000002_create_minor_merchant_bonus_transactions_table.php`
- Create: `app/Domain/Account/Models/MinorMerchantBonusTransaction.php`

- [ ] **Step 1: Write the failing test (model doesn't exist)**

```php
// tests/Unit/Domain/Account/Models/MinorMerchantBonusTransactionTest.php
<?php

namespace Tests\Unit\Domain\Account\Models;

use App\Domain\Account\Models\MinorMerchantBonusTransaction;
use Tests\TestCase;

class MinorMerchantBonusTransactionTest extends TestCase
{
    public function test_bonus_transaction_can_be_created(): void
    {
        $transaction = MinorMerchantBonusTransaction::create([
            'merchant_partner_id' => 1,
            'minor_account_uuid' => 'minor-uuid-123',
            'parent_transaction_uuid' => 'trx-456',
            'bonus_points_awarded' => 5,
            'multiplier_applied' => 2.0,
            'amount_szl' => 25.00,
            'status' => 'awarded',
        ]);
        
        $this->assertDatabaseHas('minor_merchant_bonus_transactions', [
            'minor_account_uuid' => 'minor-uuid-123',
            'bonus_points_awarded' => 5,
        ]);
    }

    public function test_bonus_transaction_idempotency_unique_constraint(): void
    {
        MinorMerchantBonusTransaction::create([
            'merchant_partner_id' => 1,
            'minor_account_uuid' => 'minor-uuid-123',
            'parent_transaction_uuid' => 'trx-same',
            'bonus_points_awarded' => 5,
            'multiplier_applied' => 2.0,
            'amount_szl' => 25.00,
            'status' => 'awarded',
        ]);
        
        $this->assertDatabaseCount('minor_merchant_bonus_transactions', 1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Account/Models/MinorMerchantBonusTransactionTest.php`
Expected: FAIL - table/class doesn't exist

- [ ] **Step 3: Write migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_merchant_bonus_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->unsignedBigInteger('merchant_partner_id');
            $table->uuid('minor_account_uuid');
            $table->uuid('parent_transaction_uuid');
            $table->integer('bonus_points_awarded');
            $table->decimal('multiplier_applied', 3, 2);
            $table->decimal('amount_szl', 12, 2);
            $table->enum('status', ['pending', 'awarded', 'failed'])->default('pending');
            $table->string('error_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->foreign('merchant_partner_id')->references('id')->on('merchant_partners')->onDelete('cascade');
            $table->index(['tenant_id', 'minor_account_uuid']);
            $table->index(['merchant_partner_id', 'created_at']);
            $table->unique('parent_transaction_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_merchant_bonus_transactions');
    }
};
```

- [ ] **Step 4: Run migration**

Run: `php artisan migrate`
Expected: Table created

- [ ] **Step 5: Create model**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MinorMerchantBonusTransaction extends Model
{
    protected $table = 'minor_merchant_bonus_transactions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'merchant_partner_id',
        'minor_account_uuid',
        'parent_transaction_uuid',
        'bonus_points_awarded',
        'multiplier_applied',
        'amount_szl',
        'status',
        'error_reason',
        'metadata',
    ];

    protected $casts = [
        'bonus_points_awarded' => 'integer',
        'multiplier_applied'  => 'decimal:2',
        'amount_szl'          => 'decimal:2',
        'metadata'            => 'array',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
    ];

    public function merchantPartner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\MerchantPartner::class, 'merchant_partner_id');
    }

    public static function findByParentTransaction(string $parentTransactionUuid): ?self
    {
        return static::where('parent_transaction_uuid', $parentTransactionUuid)->first();
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Account/Models/MinorMerchantBonusTransactionTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Account/Models/MinorMerchantBonusTransaction.php database/migrations/2026_04_23_000002_create_minor_merchant_bonus_transactions_table.php
git commit -m "feat(minor-accounts): create minor_merchant_bonus_transactions table"
```

---

## Task 3: Bonus Calculation Service

**Files:**
- Create: `app/Domain/Account/Services/MinorMerchantBonusService.php`
- Create: `tests/Unit/Domain/Account/Services/MinorMerchantBonusServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Unit\Domain\Account\Services;

use App\Domain\Account\Services\MinorMerchantBonusService;
use App\Models\MerchantPartner;
use Tests\TestCase;

class MinorMerchantBonusServiceTest extends TestCase
{
    public function test_calculate_bonus_points_uses_floor(): void
    {
        $service = app(MinorMerchantBonusService::class);
        
        // 15 SZL * 0.1 * 2.0 = 3.0 -> floor to 3
        $result = $service->calculateBonusPoints(15.00, 2.0);
        $this->assertEquals(3, $result);
        
        // 20 SZL * 0.1 * 2.0 = 4.0 -> 4
        $result = $service->calculateBonusPoints(20.00, 2.0);
        $this->assertEquals(4, $result);
        
        // 25 SZL * 0.1 * 2.0 = 5.0 -> 5
        $result = $service->calculateBonusPoints(25.00, 2.0);
        $this->assertEquals(5, $result);
    }

    public function test_calculate_bonus_points_caps_at_max_multiplier(): void
    {
        $service = app(MinorMerchantBonusService::class);
        
        // multiplier 6.0 should be capped at 5.0
        $result = $service->calculateBonusPoints(25.00, 6.0);
        $this->assertEquals(5, $result); // 25 * 0.1 * 5.0 = 5 (capped)
    }

    public function test_award_bonus_checks_idempotency(): void
    {
        $service = app(MinorMerchantBonusService::class);
        
        // First award returns points
        $result = $service->awardBonus(
            'trx-123',
            1,
            'minor-uuid',
            25.00
        );
        $this->assertEquals(5, $result['bonus_points_awarded']);
        
        // Second award with same trx returns 0 (already exists)
        $result = $service->awardBonus(
            'trx-123',
            1,
            'minor-uuid',
            25.00
        );
        $this->assertEquals(0, $result['bonus_points_awarded']);
    }

    public function test_award_bonus_checks_minor_age(): void
    {
        $service = app(MinorMerchantBonusService::class);
        
        // Minor age 10 < min_age_allowance 12 = 0 points
        $result = $service->awardBonus(
            'trx-new',
            1,
            'minor-uuid',
            25.00,
            10
        );
        $this->assertEquals(0, $result['bonus_points_awarded']);
        
        // Minor age 14 >= min_age_allowance 12 = points
        $result = $service->awardBonus(
            'trx-new-2',
            1,
            'minor-uuid',
            25.00,
            14
        );
        $this->assertEquals(5, $result['bonus_points_awarded']);
    }

    public function test_get_bonus_details_returns_correct_structure(): void
    {
        $service = app(MinorMerchantBonusService::class);
        
        $result = $service->getBonusDetails(1);
        
        $this->assertArrayHasKey('bonus_multiplier', $result);
        $this->assertArrayHasKey('min_age_allowance', $result);
        $this->assertArrayHasKey('is_active_for_minors', $result);
        $this->assertArrayHasKey('bonus_terms', $result);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Domain/Account/Services/MinorMerchantBonusServiceTest.php`
Expected: FAIL - class doesn't exist

- [ ] **Step 3: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\MinorMerchantBonusTransaction;
use App\Models\MerchantPartner;
use Illuminate\Support\Facades\DB;

class MinorMerchantBonusService
{
    private const POINTS_PER_SZL = 0.1;

    private const MAX_MULTIPLIER = 5.0;

    public function calculateBonusPoints(float $amountSz, float $multiplier): int
    {
        $cappedMultiplier = min($multiplier, self::MAX_MULTIPLIER);
        
        return (int) floor($amountSz * self::POINTS_PER_SZL * $cappedMultiplier);
    }

    public function awardBonus(
        string $parentTransactionUuid,
        int $merchantPartnerId,
        string $minorAccountUuid,
        float $amountSz,
        ?int $minorAge = null
    ): array {
        $existing = MinorMerchantBonusTransaction::findByParentTransaction($parentTransactionUuid);
        
        if ($existing !== null) {
            return [
                'bonus_points_awarded' => 0,
                'multiplier_applied' => 0.0,
                'already_awarded' => true,
            ];
        }

        $partner = MerchantPartner::findOrFail($merchantPartnerId);
        
        if (! $partner->isEligibleForMinors()) {
            $this->recordBonusTransaction(
                $merchantPartnerId,
                $minorAccountUuid,
                $parentTransactionUuid,
                0,
                0.0,
                $amountSz,
                'failed',
                'Merchant not active for minors'
            );
            
            return [
                'bonus_points_awarded' => 0,
                'multiplier_applied' => 0.0,
                'reason' => 'not_eligible',
            ];
        }

        if ($minorAge !== null && $minorAge < $partner->getMinAgeAllowance()) {
            $this->recordBonusTransaction(
                $merchantPartnerId,
                $minorAccountUuid,
                $parentTransactionUuid,
                0,
                0.0,
                $amountSz,
                'failed',
                'Minor below minimum age allowance'
            );
            
            return [
                'bonus_points_awarded' => 0,
                'multiplier_applied' => 0.0,
                'reason' => 'age_restriction',
            ];
        }

        $multiplier = $partner->getBonusMultiplier();
        $points = $this->calculateBonusPoints($amountSz, $multiplier);

        if ($points > 0) {
            $this->recordBonusTransaction(
                $merchantPartnerId,
                $minorAccountUuid,
                $parentTransactionUuid,
                $points,
                $multiplier,
                $amountSz,
                'awarded'
            );
        }

        return [
            'bonus_points_awarded' => $points,
            'multiplier_applied' => $multiplier,
            'reason' => $points > 0 ? 'success' : 'no_points',
        ];
    }

    public function getBonusDetails(int $merchantPartnerId): array
    {
        $partner = MerchantPartner::findOrFail($merchantPartnerId);
        
        return [
            'merchant_partner_id' => $partner->id,
            'merchant_name' => $partner->name,
            'bonus_multiplier' => $partner->getBonusMultiplier(),
            'min_age_allowance' => $partner->getMinAgeAllowance(),
            'category_slugs' => $partner->category_slugs,
            'is_active_for_minors' => $partner->isActiveForMinors(),
            'bonus_terms' => $partner->bonus_terms,
        ];
    }

    private function recordBonusTransaction(
        int $merchantPartnerId,
        string $minorAccountUuid,
        string $parentTransactionUuid,
        int $bonusPoints,
        float $multiplier,
        float $amountSz,
        string $status,
        ?string $errorReason = null
    ): MinorMerchantBonusTransaction {
        return MinorMerchantBonusTransaction::create([
            'id' => uuid_create(),
            'merchant_partner_id' => $merchantPartnerId,
            'minor_account_uuid' => $minorAccountUuid,
            'parent_transaction_uuid' => $parentTransactionUuid,
            'bonus_points_awarded' => $bonusPoints,
            'multiplier_applied' => $multiplier,
            'amount_szl' => $amountSz,
            'status' => $status,
            'error_reason' => $errorReason,
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Domain/Account/Services/MinorMerchantBonusServiceTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Account/Services/MinorMerchantBonusService.php tests/Unit/Domain/Account/Services/MinorMerchantBonusServiceTest.php
git commit -m "feat(minor-accounts): implement MinorMerchantBonusService"
```

---

## Task 4: Mobile API - Merchant Discovery with Bonus Metadata

**Files:**
- Modify: `app/Http/Controllers/Api/Commerce/MobileCommerceController.php:61-90`

- [ ] **Step 1: Write failing test**

```php
<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MerchantBonusDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchants_list_includes_minor_bonus_when_flagged(): void
    {
        $this->seedMerchantPartner();
        
        $response = $this->getJson('/api/v1/commerce/merchants?include_minor_bonus=true');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'display_name',
                        'bonus_multiplier',
                        'min_age_allowance',
                        'is_active_for_minors',
                    ],
                ],
            ]);
    }

    public function test_merchants_list_no_bonus_fields_by_default(): void
    {
        $response = $this->getJson('/api/v1/commerce/merchants');
        
        $response->assertStatus(200)
            ->assertJsonMissing(['bonus_multiplier']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Api/MerchantBonusDiscoveryTest.php`
Expected: FAIL - bonus fields not in response

- [ ] **Step 3: Update MobileCommerceController**

Find the `merchants()` method (lines 61-90) and update the response mapper:

```php
public function merchants(Request $request): JsonResponse
{
    $query = Merchant::where('status', MerchantStatus::ACTIVE);

    if ($search = $request->query('search')) {
        $query->where('display_name', 'like', '%' . (string) $search . '%');
    }

    $merchants = $query->orderBy('display_name')
        ->paginate(min((int) $request->query('per_page', '20'), 100));

    $includeMinorBonus = $request->query('include_minor_bonus') === 'true';

    return response()->json([
        'success' => true,
        'data'    => $merchants->map(fn (Merchant $m) => [
            'id'                => $m->public_id,
            'display_name'      => $m->display_name,
            'category'          => $m->terminal_id ?? 'general',
            'accepted_tokens'   => $m->accepted_assets ?? [],
            'accepted_networks' => $m->accepted_networks ?? [],
            'icon_url'          => $m->icon_url,
            'active'            => true,
        ] + ($includeMinorBonus ? [
            'bonus_multiplier'      => 2.0,
            'min_age_allowance'     => 0,
            'category_slugs'        => null,
            'is_active_for_minors' => true,
        ] : []))->values(),
        'pagination' => [
            'current_page' => $merchants->currentPage(),
            'last_page'    => $merchants->lastPage(),
            'per_page'     => $merchants->perPage(),
            'total'        => $merchants->total(),
        ],
    ]);
}
```

Wait - we need to check MerchantPartner model (not the Commerce Merchant). Need to add a separate query for merchant partners.

- [ ] **Step 4: Correct implementation with MerchantPartner**

```php
use App\Models\MerchantPartner;

public function merchants(Request $request): JsonResponse
{
    $includeMinorBonus = $request->query('include_minor_bonus') === 'true';
    
    $query = MerchantPartner::where('is_active', true);

    if ($search = $request->query('search')) {
        $query->where('display_name', 'like', '%' . (string) $search . '%');
    }

    if ($includeMinorBonus) {
        $query->where('is_active_for_minors', true);
    }

    $merchants = $query->orderBy('name')
        ->paginate(min((int) $request->query('per_page', '20'), 100));

    return response()->json([
        'success' => true,
        'data'    => $merchants->map(fn (MerchantPartner $m) => [
            'id'                    => (string) $m->id,
            'display_name'          => $m->name,
            'category'              => $m->category,
            'bonus_multiplier'      => $includeMinorBonus ? $m->getBonusMultiplier() : null,
            'min_age_allowance'     => $includeMinorBonus ? $m->getMinAgeAllowance() : null,
            'category_slugs'        => $includeMinorBonus ? $m->category_slugs : null,
            'is_active_for_minors'  => $includeMinorBonus ? $m->isActiveForMinors() : null,
        ])->values(),
        'pagination' => [
            'current_page' => $merchants->currentPage(),
            'last_page'    => $merchants->lastPage(),
            'per_page'     => $merchants->perPage(),
            'total'        => $merchants->total(),
        ],
    ]);
}
```

Also need to add `display_name` attribute to MerchantPartner model:

In MerchantPartner.php, add accessor:
```php
public function getDisplayNameAttribute(): string
{
    return $this->name;
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Api/MerchantBonusDiscoveryTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Commerce/MobileCommerceController.php
git commit -m "feat(minor-accounts): add minor bonus metadata to merchants list"
```

---

## Task 5: Mobile API - Merchant Bonus Details Endpoint

**Files:**
- Modify: `app/Http/Controllers/Api/Commerce/MobileCommerceController.php:537-564`

- [ ] **Step 1: Write failing test**

```php
public function test_merchant_bonus_details_returns_full_info(): void
{
    $this->seedMerchantPartner(['bonus_multiplier' => 2.0, 'min_age_allowance' => 12]);
    
    $response = $this->getJson('/api/v1/commerce/merchants/1/bonus-details');
    
    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonPath('data.bonus_multiplier', 2.0)
        ->assertJsonPath('data.min_age_allowance', 12);
}
```

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL - endpoint doesn't exist

- [ ] **Step 3: Add endpoint to MobileCommerceController**

After the `merchantDetail()` method, add:

```php
/**
 * Get merchant bonus details.
 */
public function merchantBonusDetails(string $merchantId): JsonResponse
{
    $partner = MerchantPartner::find($merchantId);

    if (! $partner) {
        return response()->json([
            'success' => false,
            'error'   => [
                'code'    => 'MERCHANT_NOT_FOUND',
                'message' => 'Merchant not found.',
            ],
        ], 404);
    }

    $details = app(MinorMerchantBonusService::class)->getBonusDetails((int) $merchantId);

    return response()->json([
        'success' => true,
        'data'    => $details,
    ]);
}
```

- [ ] **Step 4: Add route**

In routes/api.php, add:
```php
Route::get('/commerce/merchants/{merchantId}/bonus-details', [MobileCommerceController::class, 'merchantBonusDetails']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Api/MerchantBonusDiscoveryTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Commerce/MobileCommerceController.php routes/api.php
git commit -m "feat(minor-accounts): add merchant bonus details endpoint"
```

---

## Task 6: Internal API - Bonus Calculation Trigger

**Files:**
- Create: `app/Http/Controllers/Api/Commerce/MinorMerchantBonusController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Write failing test**

```php
public function test_internal bonus_endpoint_requires_api_key(): void
{
    $response = $this->postJson('/internal/minor-merchant-bonus/award', [
        'transaction_uuid' => 'trx-123',
        'merchant_partner_id' => 1,
        'minor_account_uuid' => 'minor-123',
        'amount_szl' => 25.00,
    ]);
    
    $response->assertStatus(401);
}

public function test_internal_bonus_endpoint_awards_points(): void
{
    $response = $this->postJson('/internal/minor-merchant-bonus/award', [
        'transaction_uuid' => 'trx-new',
        'merchant_partner_id' => 1,
        'minor_account_uuid' => 'minor-123',
        'amount_szl' => 25.00,
    ], ['X-Internal-Api-Key' => config('app.internal_api_key')]);
    
    $response->assertStatus(200)
        ->assertJsonPath('data.bonus_points_awarded', 5);
}
```

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL - endpoint doesn't exist

- [ ] **Step 3: Create controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Commerce;

use App\Domain\Account\Services\MinorMerchantBonusService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Throwable;

class MinorMerchantBonusController extends Controller
{
    public function __construct(
        private readonly MinorMerchantBonusService $bonusService,
    ) {
    }

    /**
     * Award bonus points for QR payment (internal only).
     */
    #[OA\Post(
        path: '/internal/minor-merchant-bonus/award',
        operationId: 'minorMerchantBonusAward',
        summary: 'Award bonus points for QR payment',
        description: 'Internal API to calculate and award bonus points for a completed QR payment.',
        tags: ['Internal'],
        security: [[]]
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['transaction_uuid', 'merchant_partner_id', 'minor_account_uuid', 'amount_szl'], properties: [
        new OA\Property(property: 'transaction_uuid', type: 'string', example: 'trx_abc123'),
        new OA\Property(property: 'merchant_partner_id', type: 'integer', example: 1),
        new OA\Property(property: 'minor_account_uuid', type: 'string', example: 'minor_uuid_123'),
        new OA\Property(property: 'amount_szl', type: 'number', example: 25.00),
        new OA\Property(property: 'minor_age', type: 'integer', nullable: true, example: 14),
    ]))]
    #[OA\Response(
        response: 200,
        description: 'Bonus awarded',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'bonus_points_awarded', type: 'integer', example: 5),
                new OA\Property(property: 'multiplier_applied', type: 'number', example: 2.0),
            ]),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized'
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error'
    )]
    public function award(Request $request): JsonResponse
    {
        $request->validate([
            'transaction_uuid'    => ['required', 'string'],
            'merchant_partner_id'  => ['required', 'integer'],
            'minor_account_uuid' => ['required', 'string'],
            'amount_szl'         => ['required', 'numeric', 'min:0'],
            'minor_age'           => ['nullable', 'integer', 'min:0', 'max:17'],
        ]);

        try {
            $result = $this->bonusService->awardBonus(
                (string) $request->input('transaction_uuid'),
                (int) $request->input('merchant_partner_id'),
                (string) $request->input('minor_account_uuid'),
                (float) $request->input('amount_szl'),
                $request->has('minor_age') ? (int) $request->input('minor_age') : null,
            );

            return response()->json([
                'success' => true,
                'data'    => [
                    'bonus_points_awarded' => $result['bonus_points_awarded'],
                    'multiplier_applied'   => $result['multiplier_applied'],
                    'reason'              => $result['reason'] ?? null,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'BONUS_CALCULATION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}
```

- [ ] **Step 4: Add route with internal auth**

In routes/api.php, add:
```php
Route::middleware('internal.api')->group(function () {
    Route::post('/minor-merchant-bonus/award', [MinorMerchantBonusController::class, 'award']);
});
```

Create middleware:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $providedKey = $request->header('X-Internal-Api-Key');
        $expectedKey = config('app.internal_api_key');

        if (empty($providedKey) || $providedKey !== $expectedKey) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid or missing API key'],
            ], 401);
        }

        return $next($request);
    }
}
```

Register in Kernel.php and add config key to .env.example.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Api/MinorMerchantBonusApiTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Commerce/MinorMerchantBonusController.php app/Http/Middleware/InternalApiAuth.php routes/api.php
git commit -m "feat(minor-accounts): add internal bonus award endpoint"
```

---

## Task 7: Filament Admin Enhancements

**Files:**
- Modify: `app/Filament/Admin/Resources/MerchantPartnerResource.php`

- [ ] **Step 1: Add fields to form**

In MerchantPartnerResource, update form():
```php
public static function form(Form $form): Form
{
    return $form
        ->schema([
            // Existing fields
            TextEntry::make('name'),
            Select::make('category')->options([...]),
            // ... existing fields ...
            
            // New minor-specific fields
            TextEntry::make('bonus_multiplier')
                ->numeric()
                ->default(2.0)
                ->helperText('Points multiplier (default 2.0, max 5.0)'),
            TextEntry::make('min_age_allowance')
                ->numeric()
                ->default(0)
                ->helperText('Minimum age for bonus eligibility'),
            CheckboxList::make('category_slugs')
                ->options([
                    'grocery' => 'Grocery',
                    'airtime' => 'Airtime',
                    'retail' => 'Retail',
                    'food_beverage' => 'Food & Beverage',
                ]),
            Toggle::make('is_active_for_minors')
                ->default(true),
            Textarea::make('bonus_terms')
                ->helperText('Terms displayed to minor users'),
        ]);
}
```

- [ ] **Step 2: Add table columns**

In table():
```php
TextColumn::make('bonus_multiplier')->sortable(),
TextColumn::make('min_age_allowance')->sortable(),
BooleanColumn::make('is_active_for_minors')->sortable(),
```

- [ ] **Step 3: Add filter**

```php
Filters\Filter::make('is_active_for_minors')
    ->query(fn ($query) => $query->where('is_active_for_minors', true))
    ->label('Minor-eligible only'),
```

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Admin/Resources/MerchantPartnerResource.php
git commit -m "feat(minor-accounts): enhance MerchantPartnerResource with minor fields"
```

---

## Task 8: Final Verification

- [ ] **Step 1: Run full test suite**

Run: `php artisan test --parallel`
Expected: All tests pass

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse --memory-limit=2G`
Expected: No errors

- [ ] **Step 3: Run code style**

Run: `./vendor/bin/php-cs-fixer fix --dry-run --diff`
Expected: No changes needed (or fix and commit)

- [ ] **Step 4: Final commit**

```bash
git commit -m "feat(minor-accounts): complete Phase 11 merchant QR API"
```

---

## Spec Coverage Check

| Spec Requirement | Task |
|---|---|
| GET /api/v1/commerce/merchants?include_minor_bonus=true | Task 4 |
| Bonus metadata in response | Task 4 |
| GET /api/v1/commerce/merchants/{partnerId}/bonus-details | Task 5 |
| POST /internal/minor-merchant-bonus/award | Task 6 |
| Internal API key guard | Task 6 |
| Filament minor fields | Task 7 |
| List filter for minor-eligible | Task 7 |

## Open Questions Resolution

See Phase 11 spec (lines 289-312) for pre-implementation decisions that should be confirmed:

1. **Transaction Trigger Point:** WHERE in codebase does QR payment complete → call bonus calculation? (EVENT LISTENER - not in this phase scope)
2. **merchant_partners tenant_id:** Added as nullable in Task 1
3. **Points Precision:** Using floor() per spec
4. **Platform cap:** Set at 5.0x in service constant

---

## Plan Complete

**Saved to:** `docs/superpowers/plans/2026-04-23-minor-accounts-phase12-merchant-qr-api-plan.md`

**Two execution options:**

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**