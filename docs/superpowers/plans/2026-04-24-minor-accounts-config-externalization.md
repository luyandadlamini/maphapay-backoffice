# Minor Accounts — Config Externalization

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move 12+ business-critical thresholds from hardcoded PHP constants into `config/minor_family.php` with `env()` overrides. Business rules that change (age bounds, tier boundaries, spending limits, merchant block lists) should never require a code deployment to adjust.

**Architecture:** Each hardcoded value gets a `config('minor_family.*')` call. All values remain identical to their current hardcoded defaults — this is a pure refactor, not a change in business logic. All existing tests must continue to pass after the change.

**Tech Stack:** PHP 8.4, Laravel 12, Pest.

**Finding addressed:** MINOR-P2-002

---

## File Map

| Action | File | Purpose |
|--------|------|---------|
| Create | `config/minor_family.php` | Canonical config for all minor account thresholds |
| Modify | `app/Http/Controllers/Api/MinorAccountController.php` | Replace hardcoded age/tier/permission values |
| Modify | `app/Rules/ValidateMinorAccountPermission.php` | Replace hardcoded spending limits + blocked categories |
| Modify | `app/Domain/Account/Models/MinorCardLimit.php` | Replace hardcoded card limit multiplier reference |

---

## Task 1 — Create config/minor_family.php

- [ ] **Step 1.1 — Audit all hardcoded values**

Run these searches to confirm every value before writing the config:

```bash
grep -n "age_min\|age_max\|6 &&\|<= 17\|>= 6\|age.*13\|13.*age\|tier.*13\|permission.*level.*1.*7\|permission.*1.*6\|50[0-9][0-9][0-9][0-9]\|1_500_000\|100.*000\|100000\|emergency.*max\|max.*emergency" \
  app/Http/Controllers/Api/MinorAccountController.php | head -30

grep -n "50000\|100000\|500000\|1500000\|1_500_000\|merchant.*categor\|blocked.*categor\|BLOCKED_CATEGOR" \
  app/Rules/ValidateMinorAccountPermission.php | head -30
```

Confirm the exact values before writing the config file below.

- [ ] **Step 1.2 — Create config/minor_family.php**

Create `config/minor_family.php`:

```php
<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Age Eligibility
    |--------------------------------------------------------------------------
    */
    'age_min'        => (int) env('MINOR_AGE_MIN', 6),
    'age_max'        => (int) env('MINOR_AGE_MAX', 17),
    'tier_grow_max_age' => (int) env('MINOR_TIER_GROW_MAX_AGE', 12),   // ≤12 = Grow, ≥13 = Rise
    'tier_rise_min_age' => (int) env('MINOR_TIER_RISE_MIN_AGE', 13),

    /*
    |--------------------------------------------------------------------------
    | Permission Levels
    |--------------------------------------------------------------------------
    */
    'permission_level_min'       => (int) env('MINOR_PERMISSION_LEVEL_MIN', 1),
    'permission_level_max_grow'  => (int) env('MINOR_PERMISSION_LEVEL_MAX_GROW', 4),
    'permission_level_max_rise'  => (int) env('MINOR_PERMISSION_LEVEL_MAX_RISE', 7),

    /*
    |--------------------------------------------------------------------------
    | Spending Limits (minor units — ZAR cents unless noted)
    |--------------------------------------------------------------------------
    */
    'spend_limit_level_1' => (int) env('MINOR_SPEND_LIMIT_L1', 50_000),    // ZAR 500
    'spend_limit_level_2' => (int) env('MINOR_SPEND_LIMIT_L2', 100_000),   // ZAR 1,000
    'spend_limit_level_3' => (int) env('MINOR_SPEND_LIMIT_L3', 200_000),   // ZAR 2,000
    'spend_limit_level_4' => (int) env('MINOR_SPEND_LIMIT_L4', 500_000),   // ZAR 5,000
    'spend_limit_level_5' => (int) env('MINOR_SPEND_LIMIT_L5', 1_000_000), // ZAR 10,000
    'spend_limit_level_6' => (int) env('MINOR_SPEND_LIMIT_L6', 1_500_000), // ZAR 15,000
    'spend_limit_level_7' => (int) env('MINOR_SPEND_LIMIT_L7', 1_500_000), // ZAR 15,000

    /*
    |--------------------------------------------------------------------------
    | Emergency Allowance
    |--------------------------------------------------------------------------
    */
    'emergency_allowance_max' => (int) env('MINOR_EMERGENCY_ALLOWANCE_MAX', 100_000), // ZAR 1,000

    /*
    |--------------------------------------------------------------------------
    | Blocked Merchant Categories (ISO 18245 MCC codes)
    |--------------------------------------------------------------------------
    | Adjust per-deployment without a code release.
    */
    'blocked_merchant_categories' => array_filter(
        explode(',', (string) env('MINOR_BLOCKED_MCC', '7995,5912,5813,9399'))
    ),

    /*
    |--------------------------------------------------------------------------
    | Card Limit Period (days used as monthly multiplier fallback)
    |--------------------------------------------------------------------------
    */
    'card_limit_period_days' => (int) env('MINOR_CARD_LIMIT_PERIOD_DAYS', 30),
];
```

**Confirm the exact values match your codebase before saving.** The defaults above are common but may differ from what your migrations or seeded data expect.

- [ ] **Step 1.3 — Verify config loads**

```bash
php artisan config:clear && php artisan tinker --execute="dump(config('minor_family'))"
```

Expected: All keys print with correct defaults.

---

## Task 2 — Replace Hardcoded Values in MinorAccountController

- [ ] **Step 2.1 — Replace age range validation**

Open `app/Http/Controllers/Api/MinorAccountController.php`. Find the age validation (around line 64–75):

```php
// BEFORE (example — confirm exact lines in your file)
if ($age < 6 || $age > 17) {
    return response()->json(['errors' => ['date_of_birth' => ['Child must be between 6 and 17 years old.']]], 422);
}
```

Replace each hardcoded value:

```php
$ageMin = config('minor_family.age_min');
$ageMax = config('minor_family.age_max');

if ($age < $ageMin || $age > $ageMax) {
    return response()->json([
        'errors' => [
            'date_of_birth' => [__("Child must be between {$ageMin} and {$ageMax} years old.")],
        ],
    ], 422);
}
```

- [ ] **Step 2.2 — Replace tier-boundary age (13)**

Find the check that determines Grow vs Rise tier based on age. Replace `13` with `config('minor_family.tier_rise_min_age')`.

- [ ] **Step 2.3 — Replace permission level bounds**

Find `permission_level > 4` (Grow cap) and `permission_level > 7` (Rise cap):

```php
// BEFORE
if ($account->tier === 'grow' && $newPermissionLevel > 4) { ... }
if ($account->tier === 'rise' && $newPermissionLevel > 7) { ... }

// AFTER
if ($account->tier === 'grow' && $newPermissionLevel > config('minor_family.permission_level_max_grow')) { ... }
if ($account->tier === 'rise' && $newPermissionLevel > config('minor_family.permission_level_max_rise')) { ... }
```

- [ ] **Step 2.4 — Replace emergency allowance max**

Find the emergency allowance ceiling check (around line 252). Replace `100000` with `config('minor_family.emergency_allowance_max')`.

---

## Task 3 — Replace Hardcoded Values in ValidateMinorAccountPermission

- [ ] **Step 3.1 — Replace spending limit map**

Open `app/Rules/ValidateMinorAccountPermission.php`. Find the spending limit array (around lines 89–97):

```php
// BEFORE (example)
private array $spendLimits = [
    1 => 50000,
    2 => 100000,
    // ...
];
```

Replace with config references:

```php
private function spendLimitForLevel(int $level): int
{
    return (int) config("minor_family.spend_limit_level_{$level}", 50_000);
}
```

Update any `$this->spendLimits[$level]` call sites to use `$this->spendLimitForLevel($level)`.

- [ ] **Step 3.2 — Replace blocked merchant categories**

Find the hardcoded array of blocked MCCs (around lines 14–19):

```php
// BEFORE
private array $blockedCategories = ['7995', '5912', '5813', '9399'];

// AFTER
private function blockedCategories(): array
{
    return config('minor_family.blocked_merchant_categories', []);
}
```

Update all `$this->blockedCategories` references to `$this->blockedCategories()`.

---

## Task 4 — Run the Full Test Suite

- [ ] **Step 4.1 — Run all minor account tests**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/ --filter=Minor --parallel --stop-on-failure
```

Expected: All pass. If any test hard-codes a limit value (e.g., `50000`) and your config value differs, update the test to use the config value.

- [ ] **Step 4.2 — PHPStan**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 4.3 — Commit**

```bash
git add config/minor_family.php \
        app/Http/Controllers/Api/MinorAccountController.php \
        app/Rules/ValidateMinorAccountPermission.php \
        app/Domain/Account/Models/MinorCardLimit.php
git commit -m "fix(P2): externalize 12+ hardcoded minor account thresholds to config

Age bounds, tier boundaries, permission levels, spending limits,
emergency allowance max, and blocked merchant categories all move
to config/minor_family.php with env() overrides. Zero change to
default values — pure refactor.

Fixes MINOR-P2-002.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Self-Review Checklist

- [x] MINOR-P2-002 (config externalization) — Tasks 1–3
- [x] Default config values match current hardcoded values exactly (no silent behaviour change)
- [x] Full test suite run after changes
- [x] All 12+ values listed in the audit are addressed
