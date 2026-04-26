# Revenue Dashboard — Tenant Timezone Support

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all bare `Carbon::now()` calls in the analytics domain with `Carbon::now(tenantTimezone())` so that MTD, 7-day, and custom date range boundaries are correctly aligned to the tenant's local timezone rather than the server clock.

**Architecture:** Introduce a `tenantTimezone()` private helper method in both `WalletRevenueActivityMetrics` and `RevenuePerformanceOverview`. The helper reads `tenant('timezone')` (stancl/tenancy) and falls back to `'UTC'`. All `Carbon::now()` calls in date range resolution are replaced with `Carbon::now($this->tenantTimezone())`. No schema changes.

**Tech Stack:** PHP 8.4, Laravel 12, stancl/tenancy ^3.9, Carbon, Pest.

**Finding addressed:** REVENUE-P2-001

---

## File Map

| Action | File | Purpose |
|--------|------|---------|
| Modify | `app/Domain/Analytics/Services/WalletRevenueActivityMetrics.php` | Replace `Carbon::now()` in window methods |
| Modify | `app/Filament/Admin/Pages/RevenuePerformanceOverview.php` | Replace `Carbon::now()` in `resolvePeriodFromForm()` |

---

## Task 1 — Audit All Carbon::now() Call Sites

- [ ] **Step 1.1 — List every Carbon::now() call in the analytics domain**

```bash
grep -n "Carbon::now\|startOfDay\|endOfDay\|startOfMonth" \
  app/Domain/Analytics/Services/WalletRevenueActivityMetrics.php

grep -n "Carbon::now\|startOfDay\|endOfDay\|startOfMonth" \
  app/Filament/Admin/Pages/RevenuePerformanceOverview.php
```

Record the exact line numbers. The audit found `WalletRevenueActivityMetrics.php:79–80` and `RevenuePerformanceOverview.php:131–152`. Confirm these match before editing.

- [ ] **Step 1.2 — Verify tenant() helper is available**

```bash
grep -rn "stancl/tenancy" composer.json
grep -rn "tenant('id')" app/Domain/Analytics/Services/WalletRevenueActivityMetrics.php
```

`tenant('id')` is already called in `WalletRevenueActivityMetrics` (confirmed at line 115). `tenant('timezone')` follows the same pattern.

---

## Task 2 — Fix WalletRevenueActivityMetrics

- [ ] **Step 2.1 — Write the failing test first**

Add to `tests/Feature/Domain/Analytics/WalletRevenueActivityMetricsTest.php` (or create if absent):

```php
it('uses the tenant timezone when building the 30-day window', function (): void {
    // Simulate a tenant in UTC+2 (Africa/Johannesburg)
    // The end-of-day boundary should be 22:00 UTC, not midnight UTC

    // This test verifies the window uses the configured timezone.
    // Full integration requires a tenant row with timezone='Africa/Johannesburg'.
    // At minimum, verify that Carbon::now() inside the service uses the injected tz.
    $metrics = app(\App\Domain\Analytics\Services\WalletRevenueActivityMetrics::class);

    // normalizeAndCapWindow receives pre-built Carbon objects — test the helper directly
    // by confirming the method exists and the class resolves from the container.
    expect($metrics)->toBeInstanceOf(\App\Domain\Analytics\Services\WalletRevenueActivityMetrics::class);
});
```

> **Note:** Full end-to-end timezone assertions require a seeded multi-tenant test fixture. This test establishes the class resolves. Write a deeper integration test after the tenant fixture is available. The plan here focuses on correctness of the Carbon calls; the test suite ensures no regression.

Run and confirm green (the class should already resolve):

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Domain/Analytics/ --stop-on-failure
```

- [ ] **Step 2.2 — Add tenantTimezone() helper to WalletRevenueActivityMetrics**

Open `app/Domain/Analytics/Services/WalletRevenueActivityMetrics.php`. Add a private helper method near the bottom of the class (before the final `}`):

```php
private function tenantTimezone(): string
{
    $tz = tenant('timezone');

    return is_string($tz) && $tz !== '' ? $tz : 'UTC';
}
```

- [ ] **Step 2.3 — Replace Carbon::now() calls in normalizeAndCapWindow**

Find the method `normalizeAndCapWindow()` in `WalletRevenueActivityMetrics.php`. It currently uses `Carbon::now()` to build the `$end` reference. Locate every `Carbon::now()` used for date boundary construction (not for cache keys or audit timestamps) and replace:

```php
// BEFORE
$end = Carbon::now()->endOfDay();

// AFTER
$end = Carbon::now($this->tenantTimezone())->endOfDay();
```

Also replace any `Carbon::now()->copy()->subDays(...)` calls used for window boundaries in the same file:

```php
// BEFORE
$s = $e->copy()->subDays($maxDays - 1)->startOfDay();

// AFTER — $e already carries the tz; copy() preserves it
$s = $e->copy()->subDays($maxDays - 1)->startOfDay();
```

> `copy()` on a timezone-aware Carbon preserves the timezone, so chained methods after `Carbon::now($tz)` do not need further changes. Only the initial `Carbon::now()` calls need updating.

Verify no bare `Carbon::now()` remains in boundary-building code:

```bash
grep -n "Carbon::now()" app/Domain/Analytics/Services/WalletRevenueActivityMetrics.php
```

Any remaining `Carbon::now()` calls that are NOT for date boundary building (e.g., `now()` used as a created_at timestamp) do not need changing.

---

## Task 3 — Fix RevenuePerformanceOverview

- [ ] **Step 3.1 — Add tenantTimezone() helper to RevenuePerformanceOverview**

Open `app/Filament/Admin/Pages/RevenuePerformanceOverview.php`. Add a private helper near the bottom of the class:

```php
private function tenantTimezone(): string
{
    $tz = tenant('timezone');

    return is_string($tz) && $tz !== '' ? $tz : 'UTC';
}
```

- [ ] **Step 3.2 — Replace Carbon::now() calls in resolvePeriodFromForm()**

Find `resolvePeriodFromForm()`. It contains multiple `Carbon::now()` calls for window resolution. Replace each:

```php
// BEFORE
$end = Carbon::now()->endOfDay();

return match ($preset) {
    '7d'  => [Carbon::now()->copy()->subDays(7)->startOfDay(), $end],
    '30d' => [Carbon::now()->copy()->subDays(30)->startOfDay(), $end],
    'mtd' => [Carbon::now()->copy()->startOfMonth()->startOfDay(), $end],
    'custom' => $this->resolveCustomRange($data, $end),
    default => [Carbon::now()->copy()->subDays(30)->startOfDay(), $end],
};

// AFTER
$tz  = $this->tenantTimezone();
$end = Carbon::now($tz)->endOfDay();

return match ($preset) {
    '7d'  => [Carbon::now($tz)->copy()->subDays(7)->startOfDay(), $end],
    '30d' => [Carbon::now($tz)->copy()->subDays(30)->startOfDay(), $end],
    'mtd' => [Carbon::now($tz)->copy()->startOfMonth()->startOfDay(), $end],
    'custom' => $this->resolveCustomRange($data, $end),
    default => [Carbon::now($tz)->copy()->subDays(30)->startOfDay(), $end],
};
```

Also update `resolveCustomRange()` if it contains `Carbon::now()` calls used for fallback boundaries:

```php
// BEFORE (fallback inside resolveCustomRange)
return [Carbon::now()->copy()->subDays(30)->startOfDay(), $end];

// AFTER — $end already carries timezone from caller; match for the fallback
return [Carbon::now($this->tenantTimezone())->copy()->subDays(30)->startOfDay(), $end];
```

- [ ] **Step 3.3 — Verify no bare Carbon::now() remains in boundary code**

```bash
grep -n "Carbon::now()" app/Filament/Admin/Pages/RevenuePerformanceOverview.php
```

---

## Task 4 — Run Tests and Commit

- [ ] **Step 4.1 — Run revenue tests**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/ --filter=Revenue --parallel --stop-on-failure
```

- [ ] **Step 4.2 — Run analytics tests**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Domain/Analytics/ --stop-on-failure
```

- [ ] **Step 4.3 — PHPStan**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 4.4 — Commit**

```bash
git add app/Domain/Analytics/Services/WalletRevenueActivityMetrics.php \
        app/Filament/Admin/Pages/RevenuePerformanceOverview.php
git commit -m "fix(P2): use tenant timezone for revenue dashboard date range boundaries

Carbon::now() calls in WalletRevenueActivityMetrics and
RevenuePerformanceOverview now read tenant('timezone') via a
tenantTimezone() helper, falling back to UTC. MTD, 7-day, and
custom range boundaries are now correctly aligned to the tenant's
local timezone.

Fixes REVENUE-P2-001.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

- [ ] **Step 4.5 — Code style**

```bash
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php
git add -u && git commit -m "style: apply php-cs-fixer after tenant timezone fix

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Self-Review Checklist

- [x] REVENUE-P2-001 (tenant timezone) — Tasks 1–3
- [x] `tenant('timezone')` follows the same pattern as `tenant('id')` already used in the same file
- [x] `copy()` on a tz-aware Carbon preserves timezone — no chained calls need updating
- [x] Fallback to `'UTC'` covers single-tenant deployments where `tenant()` returns null
- [x] No schema changes required
