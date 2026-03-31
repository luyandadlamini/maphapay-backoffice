# Code Review — Required Fixes

7 confirmed issues found across the 5-commit PR (Fund Management, Pockets/Budget APIs,
Virtual Card endpoints, PocketResource, Demo adapter). Fix all before merging.

---

## Issue 1 — BLOCKER: Linux deployment failure — `Api/` vs `API/` namespace casing

**Severity:** Blocks deploy on Laravel Cloud (Linux). macOS masks the mismatch.

**Problem:** 16 new controllers are git-tracked under `app/Http/Controllers/Api/Compatibility/`
(lowercase `i`) but their `namespace` declarations say `App\Http\Controllers\API\Compatibility\...`
(uppercase `API`). `php artisan route:cache` will fail with class-not-found on Linux.

**Affected files (all need `git mv`):**
```
app/Http/Controllers/Api/Compatibility/Budget/BudgetCategoriesDeleteController.php
app/Http/Controllers/Api/Compatibility/Budget/BudgetCategoriesStoreController.php
app/Http/Controllers/Api/Compatibility/Budget/BudgetCategoriesUpdateController.php
app/Http/Controllers/Api/Compatibility/Budget/BudgetUpdateController.php
app/Http/Controllers/Api/Compatibility/Pockets/PocketsAddFundsController.php
app/Http/Controllers/Api/Compatibility/Pockets/PocketsStoreController.php
app/Http/Controllers/Api/Compatibility/Pockets/PocketsUpdateController.php
app/Http/Controllers/Api/Compatibility/Pockets/PocketsUpdateRulesController.php
app/Http/Controllers/Api/Compatibility/Pockets/PocketsWithdrawFundsController.php
app/Http/Controllers/Api/Compatibility/VirtualCard/VirtualCardAddFundController.php
app/Http/Controllers/Api/Compatibility/VirtualCard/VirtualCardCancelController.php
app/Http/Controllers/Api/Compatibility/VirtualCard/VirtualCardEnsureDefaultController.php
app/Http/Controllers/Api/Compatibility/VirtualCard/VirtualCardListController.php
app/Http/Controllers/Api/Compatibility/VirtualCard/VirtualCardStoreAdditionalController.php
app/Http/Controllers/Api/Compatibility/VirtualCard/VirtualCardTransactionController.php
app/Http/Controllers/Api/Compatibility/VirtualCard/VirtualCardViewController.php
```

**Fix:** Two-step `git mv` for every file above, e.g.:
```bash
git mv app/Http/Controllers/Api/Compatibility/VirtualCard/VirtualCardCancelController.php \
       /tmp/VirtualCardCancelController.php
git mv /tmp/VirtualCardCancelController.php \
       app/Http/Controllers/API/Compatibility/VirtualCard/VirtualCardCancelController.php
```
Repeat for all 16 files. After moving, verify with:
```bash
git ls-files app/Http/Controllers/Api/Compatibility/
# Must return empty
```
The `routes/api-compat.php` imports already use the correct `API\` casing for these controllers
so no route file changes are needed — just the git mv.

---

## Issue 2 — BLOCKER: Pocket mutations always return 404

**Severity:** All Pocket update/withdraw/add-funds/update-rules endpoints are broken.

**Problem:** `PocketsController` (list) returns `'id' => (string) $pocket->id` which is the
ULID primary key (e.g. `01HV...`). The four mutation controllers look up the pocket with
`Pocket::where('uuid', $id)` — a separate char(36) UUID column. ULID != UUID, so the lookup
always returns null → 404.

**Files:**
- `app/Http/Controllers/API/Compatibility/Pockets/PocketsController.php` — line 26
- `app/Http/Controllers/Api/Compatibility/Pockets/PocketsUpdateController.php` — line 26
- `app/Http/Controllers/Api/Compatibility/Pockets/PocketsWithdrawFundsController.php` — line ~28
- `app/Http/Controllers/Api/Compatibility/Pockets/PocketsAddFundsController.php` — line ~28
- `app/Http/Controllers/Api/Compatibility/Pockets/PocketsUpdateRulesController.php` — line ~28

**Fix (option A — preferred):** Change the list controller to expose `uuid` as the `id`:
```php
// PocketsController.php line 26
'id' => $pocket->uuid,   // was: (string) $pocket->id
```
All mutation controllers already call `->where('uuid', $id)` so no other change needed.

**Fix (option B):** Change all mutation controllers to look up by primary key:
```php
$pocket = Pocket::where('id', $id)
    ->where('user_uuid', $user->uuid)
    ->first();
```
Option A is simpler.

---

## Issue 3 — BLOCKER: `HasUuids` trait on `ulid('id')` primary key corrupts inserts

**Severity:** Every `Pocket::create()` (and same for `UserBudget`, `BudgetCategory`,
`BudgetCategoryTransaction`) will either be rejected by MySQL strict mode or silently store
a truncated key. Laravel's `HasUuids` writes a 36-char UUID into the `id` column which
is defined as `ulid()` — char(26).

**Affected models:**
- `app/Domain/Mobile/Models/Pocket.php` — line 7, 14
- `app/Domain/Mobile/Models/UserBudget.php`
- `app/Domain/Mobile/Models/BudgetCategory.php`
- `app/Domain/Mobile/Models/BudgetCategoryTransaction.php`

**Fix:** Remove the `HasUuids` import and trait usage from all four models. The `id` column
is a ULID managed by the database default (`ulid('id')->primary()`). The `uuid` column is
populated explicitly by the store controllers. No trait is needed.

```php
// Remove these two lines from all four models:
use Illuminate\Database\Eloquent\Concerns\HasUuids;
// ...
use HasUuids;
```

If you need auto-generation of `uuid` on create, use a model `creating` observer or boot
method instead:
```php
protected static function boot(): void
{
    parent::boot();
    static::creating(function (self $model): void {
        $model->uuid ??= (string) \Illuminate\Support\Str::uuid();
    });
}
```

---

## Issue 4 — BLOCKER: VirtualCard controllers do not verify card ownership

**Severity:** Horizontal privilege escalation — any authenticated user can cancel, view,
or add funds to another user's card.

**Problem:** `VirtualCardCancelController`, `VirtualCardViewController`, and
`VirtualCardAddFundController` call `$cardService->getCard($id)` without checking that
the returned card belongs to the authenticated user. `$user` is fetched but never used
as an ownership filter.

**Files:**
- `app/Http/Controllers/Api/Compatibility/VirtualCard/VirtualCardCancelController.php` — lines 15–21
- `app/Http/Controllers/Api/Compatibility/VirtualCard/VirtualCardViewController.php` — lines 15–24
- `app/Http/Controllers/Api/Compatibility/VirtualCard/VirtualCardAddFundController.php` — lines 15–24

**Fix:** After `getCard($id)`, assert that the card's stored `user_id` matches the
authenticated user. `DemoCardIssuerAdapter` stores `user_id` in `metadata` on issue:

```php
$user = request()->user();
$card = $cardService->getCard($id);

if (! $card || ($card->metadata['user_id'] ?? null) !== $user->uuid) {
    return response()->json(['message' => 'Card not found'], 404);
}
```

Apply the same guard to `VirtualCardAddFundController` and `VirtualCardViewController`.

---

## Issue 5 — BLOCKER: Card-issuer webhook endpoints have no signature validation

**Severity:** Security regression. Three endpoints accept unauthenticated POST requests
that trigger JIT funding authorisations and settlement processing.

**Problem:** The `webhook.signature:marqeta` middleware was removed from the card-issuer
webhook route group in commit `e12b2869`. No replacement was added. The endpoints
`/webhooks/card-issuer/authorization`, `/settlement`, and `/transaction` now have only
rate limiting.

**File:** `app/Domain/CardIssuance/Routes/api.php` — lines 37–42

**Fix:** Add a demo-mode webhook guard. The simplest safe approach is a shared secret
checked via a middleware or inline in the route:

Option A — add a `demo` case to `ValidateWebhookSignature` middleware:
```php
// app/Http/Middleware/ValidateWebhookSignature.php
case 'demo':
    $secret = config('cardissuance.webhook_secret');
    if (! $secret || $request->header('X-Webhook-Secret') !== $secret) {
        abort(401);
    }
    break;
```
Then update the route:
```php
->middleware(['api.rate_limit:webhook', 'webhook.signature:demo'])
```
And add `CARD_ISSUER_WEBHOOK_SECRET` to `.env.example` and the cardissuance config.

Option B — if this is development-only and you deliberately want open webhooks, add an
explicit `App::isProduction()` guard in the controller and document it clearly.

---

## Issue 6 — BLOCKER: `remark` legacy alias in all VirtualCard compat controllers

**Severity:** Violates the compat layer API contract defined in CLAUDE.md.

**CLAUDE.md rule:** *"Never add legacy aliases (`trx_type`, `remark`, `trx`, `details`,
`remarks`). Return domain model field names directly."*

**Problem:** All 7 VirtualCard compat controllers return a top-level `'remark'` key in
both success and error responses (11 occurrences total).

**Affected files (all in `app/Http/Controllers/Api/Compatibility/VirtualCard/`):**
- `VirtualCardListController.php` — line 46
- `VirtualCardViewController.php` — lines 23, 42
- `VirtualCardAddFundController.php` — lines 28, 37
- `VirtualCardCancelController.php` — lines 19, 32
- `VirtualCardEnsureDefaultController.php` — lines 24, 59
- `VirtualCardStoreAdditionalController.php` — line 27
- `VirtualCardTransactionController.php` — line 15

**Fix:** Remove the `'remark'` key from every response array. Use `'message'` (already
present in most responses) as the human-readable status string:

```php
// Before:
return response()->json([
    'remark' => 'Cards retrieved successfully',
    'status' => 'success',
    'message' => ['Virtual cards retrieved successfully'],
    'data'   => ['cards' => $formattedCards],
]);

// After:
return response()->json([
    'status'  => 'success',
    'message' => 'Cards retrieved successfully',
    'data'    => ['cards' => $formattedCards],
]);
```

---

## Issue 7 — WARNING: `test_fundings` migration runs unconditionally in production

**Severity:** Creates a test-only table in production and exposes the `FundAccountPage`
admin UI on live data with no safeguard.

**File:** `database/migrations/2026_03_31_000001_create_test_fundings_table.php`

**Fix (option A — recommended):** Gate the migration behind an environment check:
```php
public function up(): void
{
    if (app()->isProduction()) {
        return;
    }
    Schema::create('test_fundings', function (Blueprint $table): void {
        // ...
    });
}

public function down(): void
{
    if (app()->isProduction()) {
        return;
    }
    Schema::dropIfExists('test_fundings');
}
```

**Fix (option B):** Move `TestFunding`, `FundAccountPage`, and this migration into a
`local`/`staging` only service provider that is not registered in production.

---

## Verification checklist (run after fixes)

```bash
# 1. Confirm no files remain under Api/ (lowercase)
git ls-files app/Http/Controllers/Api/
# Expected: empty output

# 2. Route cache must succeed
php artisan route:cache

# 3. Static analysis
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G

# 4. Code style
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php

# 5. Tests
./vendor/bin/pest --parallel --stop-on-failure
```
