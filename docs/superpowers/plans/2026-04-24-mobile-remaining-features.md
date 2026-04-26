# Minor Accounts — Mobile Remaining Features (Server-Side Feature Flags & Lifecycle State)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two cross-repo fixes: (1) expose a `GET /api/feature-flags` endpoint so the backoffice-managed `Feature` model is consumable by mobile without an app rebuild; (2) include `lifecycle_status` in the minor account detail API response so mobile can block operations and display contextual messaging for suspended and closed accounts.

**Architecture:** New controller + route for feature flags (thin read-only endpoint over the existing `Feature` model). Surgical addition of `lifecycle_status` into two existing response shapes in `MinorAccountController`.

**Tech Stack:** PHP 8.4, Laravel 12, Pest.

**Findings addressed:** MINOR-P2-004 · MINOR-P2-005

---

## File Map

| Action | File | Finding |
|--------|------|---------|
| Create | `app/Http/Controllers/Api/FeatureFlagsController.php` | MINOR-P2-004 |
| Modify | `routes/api.php` | MINOR-P2-004 |
| Modify | `app/Http/Controllers/Api/MinorAccountController.php` | MINOR-P2-005 |
| Create | `tests/Feature/Http/Controllers/Api/FeatureFlagsControllerTest.php` | MINOR-P2-004 |

---

## Task 1 — Server-Side Feature Flags Endpoint (MINOR-P2-004)

### Context

`App\Models\Feature` already stores feature flags in a `features` table with `name`, `scope`, and `value` columns. The `Feature::getFlags()` method lists the canonical flag names. Mobile currently hard-codes these booleans in `featureGates.ts` — adding a single read-only endpoint lets backoffice operators change feature states without an app rebuild.

The endpoint returns a flat key→bool map so mobile can replace its static `featureGates.ts` object with a cached remote fetch.

- [ ] **Step 1.1 — Read the existing Feature model and routes**

```bash
cat app/Models/Feature.php
grep -n "feature.flag\|api/feature" routes/api.php | head -10
```

Confirm the `Feature` model has `name`, `value`, and `isActive()`. Note any existing `/api/feature-flags` route (there should be none).

- [ ] **Step 1.2 — Write the failing test first**

Create `tests/Feature/Http/Controllers/Api/FeatureFlagsControllerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns a flat key→bool feature flag map for an authenticated user', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['read', 'write', 'delete']);

    Feature::factory()->create(['name' => 'send-money-enabled', 'scope' => 'global', 'value' => true]);
    Feature::factory()->create(['name' => 'virtual-cards-enabled', 'scope' => 'global', 'value' => false]);

    $this->getJson('/api/feature-flags')
        ->assertOk()
        ->assertJsonStructure(['success', 'data' => ['flags', 'ttl_seconds']])
        ->assertJsonPath('data.flags.send-money-enabled', true)
        ->assertJsonPath('data.flags.virtual-cards-enabled', false);
});

it('returns defaults for flags that have no database row', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['read', 'write', 'delete']);

    $this->getJson('/api/feature-flags')
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('requires authentication', function (): void {
    $this->getJson('/api/feature-flags')->assertUnauthorized();
});
```

Run and confirm red:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Http/Controllers/Api/FeatureFlagsControllerTest.php --stop-on-failure
```

- [ ] **Step 1.3 — Create FeatureFlagsController**

Create `app/Http/Controllers/Api/FeatureFlagsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use Illuminate\Http\JsonResponse;

class FeatureFlagsController extends Controller
{
    private const TTL_SECONDS = 300; // 5 minutes — mobile should re-fetch after this

    public function index(): JsonResponse
    {
        $rows = Feature::all()->keyBy('name');

        $flags = [];
        foreach (Feature::getFlags() as $key => $label) {
            $row = $rows->get($key);
            $flags[$key] = $row instanceof Feature ? $row->isActive() : false;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'flags'       => $flags,
                'ttl_seconds' => self::TTL_SECONDS,
            ],
        ]);
    }
}
```

- [ ] **Step 1.4 — Register the route**

Open `routes/api.php`. Find the authenticated routes group (the one with `auth:sanctum` middleware). Add:

```php
Route::get('feature-flags', [\App\Http\Controllers\Api\FeatureFlagsController::class, 'index']);
```

Check for an existing `feature-flags` route first:

```bash
grep -n "feature.flag\|FeatureFlag" routes/api.php
```

- [ ] **Step 1.5 — Run tests green**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Http/Controllers/Api/FeatureFlagsControllerTest.php --stop-on-failure
```

Expected: All pass.

- [ ] **Step 1.6 — Commit**

```bash
git add app/Http/Controllers/Api/FeatureFlagsController.php \
        routes/api.php \
        tests/Feature/Http/Controllers/Api/FeatureFlagsControllerTest.php
git commit -m "feat(P2): add GET /api/feature-flags endpoint for mobile consumption

Exposes the backoffice Feature model as a flat key→bool map with a
TTL hint. Mobile can replace hard-coded featureGates.ts with a cached
remote fetch, enabling flag changes without an app rebuild.

Fixes MINOR-P2-004.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 2 — Include lifecycle_status in Account Detail Response (MINOR-P2-005)

### Context

`MinorAccountController` returns `account_type` in its create and update responses but never includes `lifecycle_status`. The lifecycle is tracked via `MinorAccountLifecycleTransition` — the most recent transition's `state` column (`pending`, `completed`, `blocked`) represents the current lifecycle state.

The fix: load the latest lifecycle transition for the account and include its `state` as `lifecycle_status` in all relevant response shapes. If no transition row exists (freshly created accounts), default to `'active'`.

- [ ] **Step 2.1 — Read the current response shapes**

```bash
grep -n "account_type\|account_tier\|permission_level\|response()->json" \
  app/Http/Controllers/Api/MinorAccountController.php | head -30
```

Identify every `response()->json([...])` call in `store()` and `updatePermissionLevel()` that returns account data. There are at least two: the 201 create response and the `updatePermissionLevel` 200 response.

- [ ] **Step 2.2 — Write the failing test first**

Add to `tests/Feature/Http/Controllers/Api/MinorAccountControllerTest.php` (or create the file if it does not exist):

```php
it('includes lifecycle_status in the create minor account response', function (): void {
    // ... set up guardian account, valid request
    $response = $this->postJson('/api/accounts/minor', [...])
        ->assertStatus(201);

    $response->assertJsonPath('data.account.lifecycle_status', 'active');
});

it('includes lifecycle_status in the update permission level response', function (): void {
    // ... set up minor account, guardian auth
    $response = $this->putJson("/api/accounts/minor/{$minorUuid}/permission-level", [...])
        ->assertOk();

    $response->assertJsonPath('data.lifecycle_status', 'active');
});
```

Run and confirm red:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Http/Controllers/Api/MinorAccountControllerTest.php \
  --filter=lifecycle_status --stop-on-failure
```

- [ ] **Step 2.3 — Add lifecycle_status to the store() response**

Open `app/Http/Controllers/Api/MinorAccountController.php`. Find the 201 response in `store()` (around line 148):

```php
// BEFORE
'account' => [
    'uuid'              => $account->uuid,
    'account_type'      => $account->type,
    'name'              => $account->name,
    'account_tier'      => $account->tier,
    'permission_level'  => $account->permission_level,
    'parent_account_id' => $account->parent_account_id,
],

// AFTER
'account' => [
    'uuid'              => $account->uuid,
    'account_type'      => $account->type,
    'name'              => $account->name,
    'account_tier'      => $account->tier,
    'permission_level'  => $account->permission_level,
    'parent_account_id' => $account->parent_account_id,
    'lifecycle_status'  => $account->lifecycleTransitions()->latest('effective_at')->value('state') ?? 'active',
],
```

- [ ] **Step 2.4 — Add lifecycle_status to the updatePermissionLevel() response**

Find the 200 response in `updatePermissionLevel()` (around line 251):

```php
// BEFORE
'data' => [
    'uuid'              => $account->uuid,
    'account_type'      => $account->type,
    'account_tier'      => $account->tier,
    'permission_level'  => $account->permission_level,
    'parent_account_id' => $account->parent_account_id,
],

// AFTER
'data' => [
    'uuid'              => $account->uuid,
    'account_type'      => $account->type,
    'account_tier'      => $account->tier,
    'permission_level'  => $account->permission_level,
    'parent_account_id' => $account->parent_account_id,
    'lifecycle_status'  => $account->lifecycleTransitions()->latest('effective_at')->value('state') ?? 'active',
],
```

- [ ] **Step 2.5 — Run tests green**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Feature/Http/Controllers/Api/MinorAccountControllerTest.php --stop-on-failure
```

Expected: All pass including the new lifecycle_status assertions.

- [ ] **Step 2.6 — Commit**

```bash
git add app/Http/Controllers/Api/MinorAccountController.php \
        tests/Feature/Http/Controllers/Api/MinorAccountControllerTest.php
git commit -m "fix(P2): include lifecycle_status in minor account API responses

store() and updatePermissionLevel() now include lifecycle_status
derived from the latest MinorAccountLifecycleTransition row, defaulting
to 'active' for accounts with no transition history. Mobile can use
this to block operations and display contextual messaging for
suspended/closed accounts.

Fixes MINOR-P2-005.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 3 — Final Regression Pass

- [ ] **Step 3.1 — Full minor test suite**

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/ --filter=Minor --parallel --stop-on-failure
```

- [ ] **Step 3.2 — PHPStan**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G
```

- [ ] **Step 3.3 — Code style**

```bash
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php
git add -u && git commit -m "style: apply php-cs-fixer after mobile feature endpoints

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Self-Review Checklist

- [x] MINOR-P2-004 (server-side feature flags) — Task 1
- [x] MINOR-P2-005 (mobile lifecycle state display) — Task 2
- [x] Test-first (red→green) for both tasks
- [x] Feature model and route registration pattern confirmed before writing controller
- [x] No mobile-side changes needed for the backoffice half of these fixes
