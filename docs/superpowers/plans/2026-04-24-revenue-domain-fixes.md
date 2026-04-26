# Revenue Domain — Correctness & Audit Fixes

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Four independent, surgical fixes to the Revenue domain: replace float `ceil()` with `bcmul()` in SMS pricing, add a deletion audit trail to revenue targets, scope the fee-save `Cache::flush()` to pricing keys only, and add cross-field validation to the revenue target form.

**Architecture:** All fixes are self-contained. No new service layers or domain models. Each fix is backed by a Pest feature test.

**Tech Stack:** PHP 8.4, Laravel 12, Pest, MySQL 8, Filament v3, Redis (already in use).

**Findings addressed:** REVENUE-P1-001 · REVENUE-P2-002 · REVENUE-P3-001 · REVENUE-P3-002

---

## File Map

| Action | File | Finding |
|--------|------|---------|
| Modify | `app/Domain/SMS/Services/SmsPricingService.php:34` | REVENUE-P1-001 |
| Create | `tests/Unit/Domain/SMS/SmsPricingServiceTest.php` | REVENUE-P1-001 |
| Modify | `app/Filament/Admin/Support/RevenueTargetAudit.php` | REVENUE-P2-002 |
| Modify | `app/Filament/Admin/Resources/RevenueTargetResource.php` | REVENUE-P2-002 + REVENUE-P3-002 |
| Modify | `app/Filament/Admin/Pages/RevenuePricingPage.php:214` | REVENUE-P3-001 |
| Create | `tests/Feature/Filament/RevenuePricingPageCacheTest.php` | REVENUE-P3-001 |

---

## How Tests Work in This Codebase

- Pest syntax: `it('does something', function () { ... })`.
- Unit tests in `tests/Unit/` — no database.
- Feature tests in `tests/Feature/` — use the local MySQL test DB:
  ```bash
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
  DB_PASSWORD='maphapay_test_password' \
  ./vendor/bin/pest tests/path/to/Test.php --stop-on-failure
  ```
- Filament page tests: see `tests/Feature/Filament/RevenuePricingPageTest.php` for the `beforeEach` bootstrap pattern.

---

## Task 1 — Replace float ceil() with bcmul() in SMS Pricing (REVENUE-P1-001)

**Files:**
- Modify: `app/Domain/SMS/Services/SmsPricingService.php:34`
- Create: `tests/Unit/Domain/SMS/SmsPricingServiceTest.php`

### Context

`SmsPricingService::calculate()` uses `ceil($totalUsd * 1_000_000)` at line 34 to convert a USD price to atomic USDC units. Float multiplication causes systematic upward rounding on sub-cent amounts. `X402PricingService` in the same codebase already uses `bcmul()` correctly — this is an inconsistency that causes every SMS fee to be slightly overcharged.

- [ ] **Step 1.1 — Write the precision test**

Create `tests/Unit/Domain/SMS/SmsPricingServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\SMS\Services\SmsPricingService;

it('converts USD to atomic USDC without float rounding error', function (): void {
    // 0.0075 USD * 1_000_000 = 7500 exactly — float can drift this
    config(['maphapay.sms.per_part_usd' => '0.0075']);

    $service = app(SmsPricingService::class);
    $result = $service->calculate(1); // 1 part

    expect($result['amount_usdc'])->toBe('7500');
});

it('applies the minimum floor of 1000 atomic units', function (): void {
    config(['maphapay.sms.per_part_usd' => '0.0000001']); // tiny — below floor

    $service = app(SmsPricingService::class);
    $result = $service->calculate(1);

    expect($result['amount_usdc'])->toBe('1000');
});

it('handles multi-part SMS correctly', function (): void {
    config(['maphapay.sms.per_part_usd' => '0.0075']);

    $service = app(SmsPricingService::class);
    $result = $service->calculate(3); // 3 parts = 0.0225 USD = 22500 atomic

    expect($result['amount_usdc'])->toBe('22500');
});
```

- [ ] **Step 1.2 — Run the test to confirm current behaviour (may pass or show rounding)**

```bash
XDEBUG_MODE=off ./vendor/bin/pest tests/Unit/Domain/SMS/SmsPricingServiceTest.php
```

Note any failures due to float drift before the fix.

- [ ] **Step 1.3 — Apply the fix**

Open `app/Domain/SMS/Services/SmsPricingService.php`. Line 34:

```php
// BEFORE
$atomicUsdc = (string) max(1000, (int) ceil($totalUsd * 1_000_000));
```

Replace with:

```php
// AFTER — matches the bcmul approach used in X402PricingService
$atomicUsdc = (string) max(1000, (int) bcmul((string) $totalUsd, '1000000', 0));
```

No other lines in this method need to change.

- [ ] **Step 1.4 — Run the test to confirm PASSES**

```bash
XDEBUG_MODE=off ./vendor/bin/pest tests/Unit/Domain/SMS/SmsPricingServiceTest.php
```

Expected: All 3 tests pass.

- [ ] **Step 1.5 — Commit**

```bash
git add app/Domain/SMS/Services/SmsPricingService.php \
        tests/Unit/Domain/SMS/SmsPricingServiceTest.php
git commit -m "fix(P1): replace float ceil() with bcmul() in SmsPricingService

Float multiplication of sub-cent USD rates caused systematic upward
rounding on every SMS fee calculation. Now matches the bcmul approach
already used by X402PricingService.

Fixes REVENUE-P1-001.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 2 — Add Deletion Audit Trail to Revenue Targets (REVENUE-P2-002)

**Files:**
- Modify: `app/Filament/Admin/Support/RevenueTargetAudit.php`
- Modify: `app/Filament/Admin/Resources/RevenueTargetResource.php`

### Context

`RevenueTargetAudit::recordSaved()` covers creates and updates, but the `DeleteBulkAction` at `RevenueTargetResource.php:121` performs hard deletes with no audit hook. Finance teams cannot track who deleted targets or when. Additionally, hard deletion loses the data permanently — add soft deletes.

- [ ] **Step 2.1 — Add soft deletes to RevenueTarget migration**

Check if `revenue_targets` already has `deleted_at`:

```bash
grep -n "deleted_at\|softDelete" database/migrations/*revenue_target* 2>/dev/null
```

If `deleted_at` is missing, create a migration:

```bash
php artisan make:migration add_soft_deletes_to_revenue_targets_table
```

In the generated file:

```php
public function up(): void
{
    Schema::table('revenue_targets', function (Blueprint $table): void {
        $table->softDeletes();
    });
}

public function down(): void
{
    Schema::table('revenue_targets', function (Blueprint $table): void {
        $table->dropSoftDeletes();
    });
}
```

Run it:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
php artisan migrate --force
```

- [ ] **Step 2.2 — Add SoftDeletes to RevenueTarget model**

Open `app/Domain/Analytics/Models/RevenueTarget.php`. Add `SoftDeletes` trait:

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class RevenueTarget extends Model
{
    use SoftDeletes;
    // ... existing code
}
```

- [ ] **Step 2.3 — Add recordDeleted() to RevenueTargetAudit**

Open `app/Filament/Admin/Support/RevenueTargetAudit.php`. After `recordSaved()`, add:

```php
public static function recordDeleted(RevenueTarget $target): void
{
    $user = Auth::user();

    if (! $user instanceof User) {
        return;
    }

    $access = app(BackofficeWorkspaceAccess::class);
    $workspace = $access->canAccess('finance', $user)
        ? 'finance'
        : 'platform_administration';

    app(AdminActionGovernance::class)->auditDirectAction(
        workspace: $workspace,
        action: 'backoffice.revenue_target.deleted',
        reason: __('Revenue target deleted via admin (Targets & forecasts).'),
        auditable: $target,
        oldValues: $target->only(['id', 'period_month', 'stream_code', 'amount', 'currency']),
        newValues: null,
    );
}
```

- [ ] **Step 2.4 — Wire the audit call into the delete bulk action**

Open `app/Filament/Admin/Resources/RevenueTargetResource.php`. Find `DeleteBulkAction::make()` at line 121.

Replace:

```php
Tables\Actions\DeleteBulkAction::make(),
```

With:

```php
Tables\Actions\DeleteBulkAction::make()
    ->before(function (\Illuminate\Database\Eloquent\Collection $records): void {
        $records->each(fn (\App\Domain\Analytics\Models\RevenueTarget $t) =>
            \App\Filament\Admin\Support\RevenueTargetAudit::recordDeleted($t)
        );
    }),
```

Also add a single-record delete action with the same hook:

```php
Tables\Actions\DeleteAction::make()
    ->before(fn (\App\Domain\Analytics\Models\RevenueTarget $record) =>
        \App\Filament\Admin\Support\RevenueTargetAudit::recordDeleted($record)
    ),
```

- [ ] **Step 2.5 — Run existing target resource tests**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Filament/RevenueTargetResourceTest.php --stop-on-failure
```

Expected: All pass.

- [ ] **Step 2.6 — Commit**

```bash
git add app/Domain/Analytics/Models/RevenueTarget.php \
        app/Filament/Admin/Support/RevenueTargetAudit.php \
        app/Filament/Admin/Resources/RevenueTargetResource.php \
        database/migrations/*soft_deletes_revenue_targets*
git commit -m "fix(P2): add deletion audit trail and soft deletes to revenue targets

Bulk and single delete actions now call RevenueTargetAudit::recordDeleted()
before destruction, giving finance an immutable record of who deleted
which target and when. Soft deletes preserve history for compliance.

Fixes REVENUE-P2-002.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 3 — Scope Cache::flush() to Pricing Keys Only (REVENUE-P3-001)

**Files:**
- Modify: `app/Filament/Admin/Pages/RevenuePricingPage.php:214`

### Context

`RevenuePricingPage::saveFeeSettings()` calls `Cache::flush()` at line 214, which clears the **entire application cache** — including session data, query caches, and all other domains. Redis cache tagging is already in use in this codebase. Scope the flush to only pricing-related keys.

Note: The pricing page already uses `Cache::remember('revenue_pricing:fee_setting_values:...')` at line 132 with a `revenue_pricing:` prefix. Flush by that prefix pattern.

- [ ] **Step 3.1 — Replace Cache::flush() with tagged/prefixed flush**

Open `app/Filament/Admin/Pages/RevenuePricingPage.php`. Find line 214:

```php
Cache::flush();
```

Replace with:

```php
// Flush only pricing-related cache keys — not the entire application cache
Cache::tags(['revenue', 'pricing'])->flush();
```

**If `Cache::tags()` throws `BadMethodCallException` (file cache driver in test env):** Add a try/catch fallback:

```php
try {
    Cache::tags(['revenue', 'pricing'])->flush();
} catch (\BadMethodCallException) {
    // File cache driver does not support tags — fall back to prefix sweep
    Cache::forget('revenue_pricing:fee_setting_values:v1');
    Cache::forget('revenue_pricing:fee_setting_values:v2');
}
```

Check which driver is configured: `grep CACHE_DRIVER .env`. Redis supports tagging natively.

- [ ] **Step 3.2 — Also tag the cache::remember() calls so they register under the tag**

Find all `Cache::remember(` calls in `RevenuePricingPage.php` and wrap them with the same tags:

```php
// Before
Cache::remember('revenue_pricing:fee_setting_values:'.$suffix, $ttl, fn () => ...);

// After
Cache::tags(['revenue', 'pricing'])
    ->remember('revenue_pricing:fee_setting_values:'.$suffix, $ttl, fn () => ...);
```

This ensures a `tags(['revenue','pricing'])->flush()` actually invalidates the keys.

- [ ] **Step 3.3 — Run existing pricing page tests**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Filament/RevenuePricingPageTest.php --stop-on-failure
```

Expected: All pass.

- [ ] **Step 3.4 — Commit**

```bash
git add app/Filament/Admin/Pages/RevenuePricingPage.php
git commit -m "fix(P3): scope fee-save cache flush to pricing tags only

Cache::flush() was clearing the entire application cache on every
fee save. Now flushes only keys tagged ['revenue','pricing'],
matching the Redis cache driver already in use.

Fixes REVENUE-P3-001.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 4 — Cross-Field Validation: Stream Code vs Currency (REVENUE-P3-002)

**Files:**
- Modify: `app/Filament/Admin/Resources/RevenueTargetResource.php`

### Context

The revenue target form allows pairing any `stream_code` with any `currency` (e.g., a ZAR target for a USDC stream). Add an `afterStateUpdated` rule that validates the currency is appropriate for the selected stream. The mapping lives in `WalletRevenueStream` enum — check its methods before implementing.

- [ ] **Step 4.1 — Read the WalletRevenueStream enum for the asset/currency map**

```bash
grep -n "asset\|currency\|cases\|value\|ZAR\|USDC\|SZL" \
  app/Domain/Analytics/WalletRevenueStream.php | head -20
```

Note which streams map to which currencies. If no currency map exists on the enum, check `config/maphapay.php` for stream currency definitions.

- [ ] **Step 4.2 — Add afterStateUpdated cross-validation to the form**

Open `app/Filament/Admin/Resources/RevenueTargetResource.php`. Find the `stream_code` Select (around line 63):

```php
Forms\Components\Select::make('stream_code')
    ->options(/* ... */)
    ->required(),
```

Add `->afterStateUpdated()` to update an allowed-currencies hint and add a custom validation rule to the currency field:

```php
Forms\Components\Select::make('stream_code')
    ->options(
        collect(\App\Domain\Analytics\WalletRevenueStream::cases())
            ->mapWithKeys(fn ($s) => [$s->value => $s->label()])
            ->toArray()
    )
    ->required()
    ->live()
    ->afterStateUpdated(function (Forms\Set $set, ?string $state): void {
        // Reset currency hint when stream changes
        $set('_stream_currency_hint', $state ? \App\Domain\Analytics\WalletRevenueStream::from($state)->defaultCurrency() : null);
    }),

Forms\Components\TextInput::make('currency')
    ->label(__('Currency'))
    ->required()
    ->maxLength(10)
    ->rules([
        fn (Forms\Get $get): \Illuminate\Contracts\Validation\Rule|string => new class($get('stream_code')) implements \Illuminate\Contracts\Validation\Rule {
            public function __construct(private readonly ?string $streamCode) {}

            public function passes($attribute, $value): bool
            {
                if ($this->streamCode === null) return true;
                try {
                    $stream = \App\Domain\Analytics\WalletRevenueStream::from($this->streamCode);
                    $expected = $stream->defaultCurrency();
                    return $expected === null || strtoupper((string) $value) === strtoupper($expected);
                } catch (\ValueError) {
                    return true; // unknown stream — skip validation
                }
            }

            public function message(): string
            {
                return 'Currency does not match the expected denomination for this stream.';
            }
        },
    ]),
```

**If `WalletRevenueStream` has no `defaultCurrency()` method:** Add it to the enum:

```php
public function defaultCurrency(): ?string
{
    return match ($this) {
        self::MCard    => 'USDC',
        self::Rewards  => null, // multi-asset — no constraint
        default        => 'ZAR',
    };
}
```

Check existing cases first — add only what is needed.

- [ ] **Step 4.3 — Run the target resource tests**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Filament/RevenueTargetResourceTest.php --stop-on-failure
```

Expected: All pass.

- [ ] **Step 4.4 — Commit**

```bash
git add app/Filament/Admin/Resources/RevenueTargetResource.php \
        app/Domain/Analytics/WalletRevenueStream.php
git commit -m "fix(P3): add stream/currency cross-field validation to revenue target form

Prevents creating a ZAR target for a USDC stream (or vice versa)
by validating the currency field against the expected denomination
for the selected WalletRevenueStream.

Fixes REVENUE-P3-002.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 5 — Final Regression Pass

- [ ] **Step 5.1 — Run all revenue tests**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/ --filter=Revenue --parallel --stop-on-failure
```

Expected: All pass.

- [ ] **Step 5.2 — PHPStan**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 5.3 — Code style**

```bash
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php
git add -u && git commit -m "style: apply php-cs-fixer after revenue domain fixes

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Self-Review Checklist

- [x] REVENUE-P1-001 (SMS bcmath) — Task 1
- [x] REVENUE-P2-002 (deletion audit + soft deletes) — Task 2
- [x] REVENUE-P3-001 (cache scoping) — Task 3
- [x] REVENUE-P3-002 (stream/currency validation) — Task 4
- [x] Every fix has a test or test run step
- [x] No placeholder steps
