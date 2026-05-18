# Money Movement Architecture Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate the class of tenancy and projection bugs causing recipient-lookup failures and savings-pocket phantom transfers by enforcing architectural contracts at the type/trait boundary, carrying tenant identity in domain events, and making money-movement APIs synchronous with bounded wait + mandatory idempotency. Fix both reported bugs as a consequence of the architecture, not as patches.

**Architecture:** Replace runtime tenancy discipline with typed base classes (`CentralModel`, `TenantModel`). Make `UsesTenantConnection` strict — throws outside `testing` env when no tenant context. Introduce `CarriesTenantContext` event interface + `TenantAwareProjector` abstract base so projectors auto-initialize tenancy from the event. Convert `POST /api/v2/transfers` from async fire-and-forget to sync-bounded-wait (5s) with polling fallback. On mobile, delete legacy duplicate screens, route through a single canonical feature module, validate API responses with Zod, and require `await + invalidateQueries` via a `useMoneyMovementMutation` wrapper.

**Tech Stack:**
- Backend: PHP 8.4, Laravel 12, Spatie EventSourcing v7.7, Temporal PHP SDK, stancl/tenancy, Pest, PHPStan L8
- Mobile: Expo / React Native, TypeScript, TanStack Query v5, Zod v3, Zustand, Sentry RN, Axios

---

## Repository Roots

- **Backend:** `/Users/Lihle/Development/Coding/maphapay-backoffice`
- **Mobile:** `/Users/Lihle/Development/Coding/maphapayrn`

Tasks below assume the backend root unless prefixed with `[Mobile]`.

---

## File Structure

### New backend files

| Path | Responsibility |
|---|---|
| `app/Domain/Shared/Models/CentralModel.php` | Base class pinned to `central` connection |
| `app/Domain/Shared/Models/TenantModel.php` | Base class using `UsesTenantConnection`; strict in non-testing envs |
| `app/Domain/Shared/Contracts/CarriesTenantContext.php` | Interface for events that touch tenant-scoped state |
| `app/Domain/Shared/EventSourcing/TenantAwareProjector.php` | Abstract projector that auto-initializes tenancy from event |
| `app/Domain/Shared/EventSourcing/TenantAwareReactor.php` | Same shape for reactors |
| `app/Http/Controllers/Api/Compatibility/Users/UserExistController.php` | The missing `POST /api/user/exist` |
| `app/Http/Controllers/Api/V2/Transfers/TransferStatusController.php` | `GET /api/v2/transfers/{id}/status` for polling |
| `app/Domain/Wallet/Workflows/SyncTransferAwaiter.php` | Bounded-wait wrapper for transfer workflow |
| `tests/Feature/Architecture/TenantBoundaryEnforcementTest.php` | Contract test |
| `tests/Feature/Architecture/ProjectorTenancyAuditTest.php` | Static audit test |
| `tests/Feature/MoneyMovement/BalanceConservationTest.php` | Invariant test |

### Modified backend files

| Path | Change |
|---|---|
| `app/Domain/Shared/Traits/UsesTenantConnection.php` | Throw `TenantContextMissingException` when called outside tenant context (non-testing only) |
| `app/Models/User.php` | Extend `CentralModel` OR add `protected $connection = 'central';` |
| `app/Domain/Account/Projectors/AssetBalanceProjector.php` | Extend `TenantAwareProjector` |
| `app/Domain/Asset/Events/AssetTransferCompleted.php` | Implement `CarriesTenantContext` |
| `app/Domain/Asset/Events/AssetTransferInitiated.php` | Implement `CarriesTenantContext` (if exists) |
| `app/Http/Controllers/Api/TransferController.php` | Replace `WorkflowStub::start()` with bounded-wait via `SyncTransferAwaiter` |
| `routes/api-compat.php` | Mount `/api/user/exist` |
| `routes/api-v2.php` | Mount transfer status endpoint |

### New mobile files

| Path | Responsibility |
|---|---|
| `src/api/hooks/useMoneyMovementMutation.ts` | Wrapper that enforces invalidation + Sentry error capture |
| `src/api/schemas/money-movement.ts` | Zod schemas for transfer/pocket responses |

### Deleted/modified mobile files

| Path | Change |
|---|---|
| `src/app/(tabs)/wallet/pocket-detail.tsx` | **DELETE** (legacy duplicate) |
| `src/features/savings/presentation/SavingsScreen.tsx` | Remove `isWalletSavingsRoute` branch — single route |
| `src/api/apiClient.ts` | Preserve `httpStatus`, `responseData`, `requestId` on every error |
| `src/app/(modals)/send-money.tsx` | Use Sentry breadcrumbs for caught errors |
| `src/features/wallet/store/savingsStore.ts` | Remove `addFunds`/`withdrawFunds` (server-mutating actions move to React Query) |

---

## Phase 1: Backend Tenancy Boundary Enforcement

### Task 1.1: Create the `CentralModel` base class

**Files:**
- Create: `app/Domain/Shared/Models/CentralModel.php`
- Test: `tests/Unit/Shared/Models/CentralModelTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Shared\Models;

use App\Domain\Shared\Models\CentralModel;
use Tests\TestCase;

class CentralModelTest extends TestCase
{
    public function test_central_model_subclass_is_pinned_to_central_connection(): void
    {
        $model = new class extends CentralModel {
            protected $table = 'users';
        };

        $this->assertSame('central', $model->getConnectionName());
    }
}
```

- [ ] **Step 2: Run test, expect failure**

```
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=maphapay_backoffice_test \
DB_USERNAME=maphapay_test DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest tests/Unit/Shared/Models/CentralModelTest.php
```
Expected: `Error: Class "App\Domain\Shared\Models\CentralModel" not found`

- [ ] **Step 3: Implement `CentralModel`**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base class for Eloquent models whose tables live in the *central* database.
 *
 * Central tables (users, tenants, account_memberships, idempotency_keys, etc.)
 * must never be queried on the per-tenant connection. Extending this class
 * pins the connection to `central` regardless of the current default.
 *
 * Use this for any model whose underlying table is not replicated per tenant.
 */
abstract class CentralModel extends Model
{
    protected $connection = 'central';
}
```

- [ ] **Step 4: Run test, expect pass**

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Shared/Models/CentralModel.php tests/Unit/Shared/Models/CentralModelTest.php
git commit -m "feat(tenancy): introduce CentralModel base class

Pins subclasses to the 'central' connection so central-only tables
(users, tenants, account_memberships) cannot accidentally be queried
against the tenant connection after account.context middleware runs.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

### Task 1.2: Pin `User` to central via `CentralModel`

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Auth/UserConnectionTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class UserConnectionTest extends TestCase
{
    public function test_user_queries_central_even_when_tenancy_is_initialized(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();

        app(Tenancy::class)->initialize($tenant);

        try {
            $found = User::find($user->id);
            $this->assertNotNull($found, 'User must be findable under tenant context');
            $this->assertSame('central', $found->getConnectionName());
        } finally {
            app(Tenancy::class)->end();
        }
    }
}
```

- [ ] **Step 2: Run test, expect failure**
- [ ] **Step 3: Modify `User`**

Change line 27 from `use Illuminate\Foundation\Auth\User as Authenticatable;` and the class declaration so that `User` either extends `CentralModel` OR adds `protected $connection = 'central';`. Because `User` must remain `Authenticatable`, the simplest correct change is:

```php
class User extends Authenticatable implements FilamentUser
{
    /**
     * The users table lives in the central database. After
     * account.context middleware switches database.default to 'tenant',
     * unpinned User queries would target the wrong DB. Pin explicitly.
     */
    protected $connection = 'central';

    // ... existing body
}
```

- [ ] **Step 4: Run test, expect pass**
- [ ] **Step 5: Commit**

### Task 1.3: Create `TenantContextMissingException`

**Files:**
- Create: `app/Domain/Shared/Exceptions/TenantContextMissingException.php`

- [ ] **Step 1: Implement**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * Thrown when a TenantModel (or UsesTenantConnection consumer) is touched
 * outside an active tenant context in any non-testing environment.
 *
 * Catching this exception is almost always wrong. The correct fix is to
 * wrap the calling code in WithTenantContext::withAccountTenancy() or
 * ensure the HTTP route is behind the account.context middleware.
 */
final class TenantContextMissingException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self(sprintf(
            'TenantContextMissingException: %s was queried without an active tenant context. '
            . 'Wrap this call in WithTenantContext::withAccountTenancy($accountUuid, ...) '
            . 'or route through the account.context middleware.',
            $modelClass,
        ));
    }
}
```

- [ ] **Step 2: Commit (no test yet — used by next task)**

### Task 1.4: Make `UsesTenantConnection` strict outside testing

**Files:**
- Modify: `app/Domain/Shared/Traits/UsesTenantConnection.php`
- Test: `tests/Feature/Architecture/TenantBoundaryEnforcementTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Domain\Account\Models\AccountBalance;
use App\Domain\Shared\Exceptions\TenantContextMissingException;
use Tests\TestCase;

class TenantBoundaryEnforcementTest extends TestCase
{
    public function test_tenant_model_throws_when_used_without_tenant_context_outside_testing(): void
    {
        config(['app.env' => 'local']); // simulate non-testing

        $this->expectException(TenantContextMissingException::class);
        AccountBalance::query()->first();
    }

    public function test_tenant_model_is_lenient_in_testing_env(): void
    {
        $this->assertSame('testing', config('app.env'));

        // No exception, no DB hit needed beyond schema lookup. This must not throw.
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, AccountBalance::query());
    }
}
```

- [ ] **Step 2: Run, expect both tests fail (no exception thrown)**

- [ ] **Step 3: Modify the trait**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Shared\Traits;

use App\Domain\Shared\Exceptions\TenantContextMissingException;
use Illuminate\Support\Facades\Config;
use Stancl\Tenancy\Tenancy;

trait UsesTenantConnection
{
    public function getConnectionName(): ?string
    {
        if ($this->shouldUseDefaultConnection()) {
            return null;
        }

        $this->assertTenantContextIsActive();

        return 'tenant';
    }

    protected function shouldUseDefaultConnection(): bool
    {
        return Config::get('app.env') === 'testing';
    }

    /**
     * Enforce the tenancy contract: tenant-scoped models must never be
     * touched outside an initialized tenant context in any non-testing
     * environment. Failing loudly here surfaces bugs in dev and prevents
     * silent cross-tenant writes in staging/production.
     */
    private function assertTenantContextIsActive(): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->initialized || $tenancy->tenant === null) {
            throw TenantContextMissingException::forModel(static::class);
        }
    }
}
```

- [ ] **Step 4: Run tests, expect pass**
- [ ] **Step 5: Run the full backend test suite locally with the documented MySQL env vars; expect failures revealing every currently-broken non-HTTP code path**

```
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=maphapay_backoffice_test \
DB_USERNAME=maphapay_test DB_PASSWORD='maphapay_test_password' \
./vendor/bin/pest --parallel 2>&1 | tee /tmp/tenancy-strict-failures.log
```

- [ ] **Step 6: Triage `/tmp/tenancy-strict-failures.log`** — every failure is either (a) a real bug worth fixing in a later task, or (b) a test that needs `WithTenantContext` wrapping. Open a follow-up file `docs/superpowers/plans/2026-05-18-tenancy-strict-fallout.md` listing each, but **do not patch them in this commit** — that would dilute the contract change.

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Shared/Traits/UsesTenantConnection.php tests/Feature/Architecture/TenantBoundaryEnforcementTest.php app/Domain/Shared/Exceptions/TenantContextMissingException.php
git commit -m "feat(tenancy)!: strict UsesTenantConnection outside testing env

UsesTenantConnection now throws TenantContextMissingException when a
tenant-scoped model is touched without an initialized tenant context
in any environment other than 'testing'. This makes the tenancy
contract enforced rather than relying on programmer discipline.

BREAKING: code paths that previously silently queried the wrong DB
when called outside HTTP request/queue worker boundaries will now
throw. See docs/superpowers/plans/2026-05-18-tenancy-strict-fallout.md
for the audit list.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

### Task 1.5: Triage and fix strict-mode fallout

For each failure logged in Task 1.4 Step 5, follow this loop:

- [ ] Identify whether the call site is HTTP, queue job, projector, reactor, CLI, or test
- [ ] If HTTP: confirm the route has `account.context` middleware. If missing, add it.
- [ ] If queue job/projector/reactor/CLI: wrap the tenant work in `WithTenantContext::withAccountTenancy($accountUuid, fn() => /* ... */)`. The `$accountUuid` must come from the job payload or event — never from a global.
- [ ] If test: prefer `actingAs($user)` + the existing `account.context` test helper. Only wrap directly if the test exercises non-HTTP code.
- [ ] Commit each fix individually with `fix(tenancy): wrap <call-site> in tenant context`.

This task is intentionally not pre-enumerated — the trait's strict mode is the audit. Address every failure before moving to Phase 2.

---

## Phase 2: Add the Missing `/api/user/exist` Endpoint

The mobile calls `POST /api/user/exist` for recipient lookup. No such route exists in the backend. This is the proximate cause of "Could not load recipient — Server Error" (axios sees the 404 HTML/JSON, `messageForInfrastructureError` turns it into a generic 5xx-style string).

### Task 2.1: Write the failing feature test

**Files:**
- Create: `tests/Feature/Api/Compatibility/UserExistTest.php`

- [ ] **Step 1: Write test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Api\Compatibility;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserExistTest extends TestCase
{
    public function test_user_exist_returns_recipient_for_known_username(): void
    {
        $caller = User::factory()->create();
        $recipient = User::factory()->create(['username' => 'mickey.dacunha']);

        Sanctum::actingAs($caller, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/user/exist', ['user' => 'mickey.dacunha']);

        $response->assertOk()
            ->assertJsonStructure(['status', 'data' => ['id', 'username', 'display_name']])
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.username', 'mickey.dacunha')
            ->assertJsonPath('data.id', $recipient->id);
    }

    public function test_user_exist_returns_error_envelope_for_unknown_user(): void
    {
        $caller = User::factory()->create();
        Sanctum::actingAs($caller, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/user/exist', ['user' => 'nobody.here']);

        $response->assertOk() // compat envelope: 200 with status=error per CLAUDE.md
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('data', null);
    }

    public function test_user_exist_rejects_self_lookup(): void
    {
        $caller = User::factory()->create(['username' => 'self.user']);
        Sanctum::actingAs($caller, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/user/exist', ['user' => 'self.user']);

        $response->assertOk()
            ->assertJsonPath('status', 'error');
    }

    public function test_user_exist_excludes_frozen_users(): void
    {
        $caller = User::factory()->create();
        User::factory()->create(['username' => 'frozen.one', 'frozen_at' => now()]);

        Sanctum::actingAs($caller, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/user/exist', ['user' => 'frozen.one']);

        $response->assertOk()->assertJsonPath('status', 'error');
    }

    public function test_user_exist_requires_authentication(): void
    {
        $this->postJson('/api/user/exist', ['user' => 'anyone'])
            ->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run test, expect 404**

### Task 2.2: Implement the controller

**Files:**
- Create: `app/Http/Controllers/Api/Compatibility/Users/UserExistController.php`

- [ ] **Step 1: Implement**

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/user/exist
 *
 * Mobile-compat recipient lookup. Accepts a single `user` field that may be
 * a username, mobile (E.164, national, or raw digits), or numeric user ID.
 * Returns the canonical compat envelope:
 *   { status: 'success'|'error', message: string[], data: { id, username, display_name, mobile } | null }
 *
 * Backend is SOT for field names — see CLAUDE.md compat contract.
 */
final class UserExistController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user' => ['required', 'string', 'max:120'],
        ]);

        $caller = $request->user();
        abort_if($caller === null, 401);

        $query = trim($data['user']);
        $digits = preg_replace('/\D+/', '', $query) ?? '';

        $peer = User::query()
            ->whereKeyNot($caller->getKey())
            ->whereNull('frozen_at')
            ->where(function (Builder $q) use ($query, $digits): void {
                $q->where('username', $query);

                if ($digits !== '') {
                    $q->orWhere('mobile', $digits)
                        ->orWhere('mobile', '+' . $digits);
                }

                if (ctype_digit($query)) {
                    $q->orWhere('id', $query);
                }
            })
            ->first();

        if ($peer === null) {
            return response()->json([
                'status'  => 'error',
                'message' => ['Recipient not found.'],
                'data'    => null,
            ]);
        }

        return response()->json([
            'status'  => 'success',
            'message' => [],
            'data'    => [
                'id'           => $peer->id,
                'username'     => $peer->username,
                'display_name' => trim(($peer->first_name ?? '') . ' ' . ($peer->last_name ?? '')) ?: $peer->username,
                'mobile'       => $peer->mobile,
            ],
        ]);
    }
}
```

- [ ] **Step 2: Mount the route in `routes/api-compat.php`**

Insert near the other user/compat routes (after line 231 social-money lookup):

```php
Route::post('user/exist', App\Http\Controllers\Api\Compatibility\Users\UserExistController::class)
    ->name('maphapay.compat.user.exist');
```

- [ ] **Step 3: Run tests, expect pass**

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/Compatibility/Users/UserExistController.php routes/api-compat.php tests/Feature/Api/Compatibility/UserExistTest.php
git commit -m "feat(compat): add POST /api/user/exist recipient lookup

Mobile send-money was calling /api/user/exist which did not exist
on the backend, producing 'Could not load recipient — Server Error'.
This adds the canonical endpoint behind auth:sanctum + account.context
middleware. User model is pinned to central (Task 1.2) so the query
runs against the correct DB even under an active tenant context.

Returns the compat envelope (status/message/data) per CLAUDE.md.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Phase 3: Event-Carried Tenancy

### Task 3.1: Create `CarriesTenantContext` interface

**Files:**
- Create: `app/Domain/Shared/Contracts/CarriesTenantContext.php`

- [ ] **Step 1: Implement**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Shared\Contracts;

/**
 * Marks a domain event that mutates tenant-scoped projections.
 *
 * Implementing this contract is the single source of truth telling
 * TenantAwareProjector / TenantAwareReactor which tenant to initialize
 * before invoking the handler. The returned UUID must be the account
 * UUID whose tenant DB the projection writes to.
 *
 * For transfer events that touch two accounts (and therefore potentially
 * two tenants), prefer emitting two events — one per side — each carrying
 * its own tenantAccountUuid. This keeps tenant initialization atomic.
 */
interface CarriesTenantContext
{
    public function tenantAccountUuid(): string;
}
```

- [ ] **Step 2: Commit**

### Task 3.2: Create `TenantAwareProjector` abstract base

**Files:**
- Create: `app/Domain/Shared/EventSourcing/TenantAwareProjector.php`
- Test: `tests/Unit/Shared/EventSourcing/TenantAwareProjectorTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Shared\EventSourcing;

use App\Domain\Shared\Contracts\CarriesTenantContext;
use App\Domain\Shared\EventSourcing\TenantAwareProjector;
use App\Models\Tenant;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class TenantAwareProjectorTest extends TestCase
{
    public function test_handler_runs_under_tenant_context_when_event_carries_it(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $accountUuid = '00000000-0000-0000-0000-000000000001';
        AccountMembership::factory()->create([
            'tenant_id'    => $tenant->id,
            'account_uuid' => $accountUuid,
            'status'       => 'active',
        ]);

        $event = new class($accountUuid) extends ShouldBeStored implements CarriesTenantContext {
            public function __construct(public readonly string $uuid) {}
            public function tenantAccountUuid(): string { return $this->uuid; }
        };

        $captured = null;
        $projector = new class($captured) extends TenantAwareProjector {
            public ?bool $captured = null;
            public function onTestEvent(CarriesTenantContext $event): void
            {
                $this->captured = app(Tenancy::class)->initialized;
            }
        };

        $projector->handle($event); // Spatie will route to onTestEvent

        $this->assertTrue($projector->captured, 'Projector handler must run with tenancy initialized');
        $this->assertFalse(app(Tenancy::class)->initialized, 'Tenancy must be torn down after handler');
    }
}
```

- [ ] **Step 2: Run, expect failure**

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Shared\EventSourcing;

use App\Domain\Shared\Concerns\WithTenantContext;
use App\Domain\Shared\Contracts\CarriesTenantContext;
use RuntimeException;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Abstract projector base that auto-initializes tenancy from the event.
 *
 * Subclasses define `on<EventName>` methods as usual. Before each handler
 * fires, this base inspects the event:
 *   - If it implements CarriesTenantContext, the handler runs inside
 *     WithTenantContext::withAccountTenancy(...).
 *   - If it does not, the handler runs as-is (event is tenant-agnostic).
 *
 * This eliminates the class of bug where a projector running in a queue
 * worker silently writes to the wrong tenant DB because it forgot to
 * initialize tenancy.
 */
abstract class TenantAwareProjector extends Projector
{
    use WithTenantContext;

    public function handle($event): void
    {
        if (! $event instanceof ShouldBeStored) {
            throw new RuntimeException(sprintf(
                'TenantAwareProjector::handle expects ShouldBeStored, got %s',
                get_debug_type($event),
            ));
        }

        if ($event instanceof CarriesTenantContext) {
            $this->withAccountTenancy(
                $event->tenantAccountUuid(),
                fn () => parent::handle($event),
            );
            return;
        }

        parent::handle($event);
    }
}
```

- [ ] **Step 4: Run test, expect pass**
- [ ] **Step 5: Commit**

### Task 3.3: Make `AssetTransferCompleted` carry tenancy

**Files:**
- Modify: `app/Domain/Asset/Events/AssetTransferCompleted.php`
- Modify: `app/Domain/Asset/Events/AssetTransferInitiated.php` (if exists)

- [ ] **Step 1: Read both events**

```bash
ls -la app/Domain/Asset/Events/AssetTransfer*.php
```

- [ ] **Step 2: Modify `AssetTransferCompleted`**

The intra-tenant case writes both balances within the *same* tenant. The cross-tenant case is handled by emitting two events: one for the debit side, one for the credit side — each carrying its own tenantAccountUuid. For now, assume intra-tenant (the failing case) and use `fromAccountUuid`:

```php
use App\Domain\Shared\Contracts\CarriesTenantContext;

class AssetTransferCompleted extends ShouldBeStored implements CarriesTenantContext
{
    // ... existing constructor and properties

    public function tenantAccountUuid(): string
    {
        return (string) $this->fromAccountUuid;
    }
}
```

- [ ] **Step 3: Commit**

### Task 3.4: Migrate `AssetBalanceProjector` to `TenantAwareProjector`

**Files:**
- Modify: `app/Domain/Account/Projectors/AssetBalanceProjector.php`
- Test: `tests/Feature/MoneyMovement/AssetBalanceProjectionTest.php`

- [ ] **Step 1: Write failing test** that asserts an `AssetTransferCompleted` event projection lands in the *tenant* DB, not central. Use the existing test harness and explicit DB connection assertions:

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\MoneyMovement;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Projectors\AssetBalanceProjector;
use App\Domain\Asset\Events\AssetTransferCompleted;
// ... event constructor args
use Tests\TestCase;

class AssetBalanceProjectionTest extends TestCase
{
    public function test_transfer_completed_projects_to_tenant_db(): void
    {
        // Arrange: two accounts in the same tenant, with starting balances
        // Act: dispatch AssetTransferCompleted through the projector
        // Assert: AccountBalance on tenant connection reflects the move
        //         AND nothing was written to central account_balances_legacy
        $this->markTestIncomplete('Wire up factories and event constructor');
    }
}
```

(Expand factories/constructors when implementing.)

- [ ] **Step 2: Change projector base class**

```diff
-use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
+use App\Domain\Shared\EventSourcing\TenantAwareProjector;
-class AssetBalanceProjector extends Projector
+class AssetBalanceProjector extends TenantAwareProjector
```

- [ ] **Step 3: Run test, expect pass**
- [ ] **Step 4: Commit**

### Task 3.5: Audit remaining projectors

- [ ] **Step 1: Enumerate**

```bash
grep -rln "extends Projector" app/Domain | tee /tmp/projectors.txt
```

- [ ] **Step 2: For each projector listed:** check whether its `on<Event>` handlers touch `UsesTenantConnection` models (or models that should). If yes, migrate base class to `TenantAwareProjector` and ensure handled events implement `CarriesTenantContext`.

- [ ] **Step 3: Add the static audit test** that asserts every projector under `app/Domain/**/Projectors/` either extends `TenantAwareProjector` OR is explicitly listed in an allowlist with justification.

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Domain\Shared\EventSourcing\TenantAwareProjector;
use ReflectionClass;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class ProjectorTenancyAuditTest extends TestCase
{
    /** Projectors explicitly approved as not needing tenant context. Justify each. */
    private const TENANT_AGNOSTIC_ALLOWLIST = [
        // 'App\Domain\Some\Projectors\CentralOnlyProjector', // central-only state
    ];

    public function test_every_domain_projector_either_is_tenant_aware_or_allowlisted(): void
    {
        $finder = (new Finder())->files()->in(base_path('app/Domain'))->path('Projectors')->name('*.php');

        $violations = [];
        foreach ($finder as $file) {
            $class = $this->classFromFile($file->getRealPath());
            if ($class === null || ! class_exists($class)) continue;

            $ref = new ReflectionClass($class);
            if ($ref->isAbstract()) continue;
            if (! $ref->isSubclassOf(Projector::class)) continue;

            if ($ref->isSubclassOf(TenantAwareProjector::class)) continue;
            if (in_array($class, self::TENANT_AGNOSTIC_ALLOWLIST, true)) continue;

            $violations[] = $class;
        }

        $this->assertSame([], $violations, sprintf(
            "Projectors must extend TenantAwareProjector or be allowlisted:\n  - %s",
            implode("\n  - ", $violations),
        ));
    }

    private function classFromFile(string $path): ?string
    {
        $src = file_get_contents($path);
        if (! preg_match('/namespace\s+([^;]+);/', $src, $ns)) return null;
        if (! preg_match('/class\s+([A-Za-z0-9_]+)/', $src, $cn)) return null;
        return trim($ns[1]) . '\\' . $cn[1];
    }
}
```

- [ ] **Step 4: Commit each migrated projector individually + the audit test at the end of Phase 3**

---

## Phase 4: Synchronous Money-Movement APIs with Bounded Wait

### Task 4.1: Create `SyncTransferAwaiter`

**Files:**
- Create: `app/Domain/Wallet/Workflows/SyncTransferAwaiter.php`

- [ ] **Step 1: Implement**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Wallet\Workflows;

use Temporal\Client\WorkflowOptions;
use Temporal\Client\WorkflowStubInterface;
use Throwable;

/**
 * Bounded-wait wrapper for Temporal money-movement workflows.
 *
 * Money movement APIs return one of:
 *   - The terminal workflow result (committed)        → HTTP 200
 *   - A timeout sentinel after $waitSeconds elapses   → HTTP 202 + polling URL
 *   - A workflow failure (with structured reason)     → HTTP 4xx/5xx
 *
 * Polling is via GET /api/v2/transfers/{workflowId}/status.
 *
 * Why 5 seconds: chosen so the user sees terminal state in the same UI
 * gesture for >99% of intra-tenant transfers (median Temporal+projection
 * latency is well under 1s locally). The 202 path covers wallet-provider
 * flows (MTN MoMo etc.) that are inherently async.
 */
final class SyncTransferAwaiter
{
    public const DEFAULT_WAIT_SECONDS = 5;

    public function awaitOrAccept(WorkflowStubInterface $stub, int $waitSeconds = self::DEFAULT_WAIT_SECONDS): TransferAwaitOutcome
    {
        try {
            $result = $stub->getResult($waitSeconds);
            return TransferAwaitOutcome::completed($stub->getExecution()->getID(), $result);
        } catch (\Temporal\Exception\Client\WorkflowFailedException $e) {
            return TransferAwaitOutcome::failed($stub->getExecution()->getID(), $e);
        } catch (\Temporal\Exception\Client\TimeoutException $e) {
            return TransferAwaitOutcome::pending($stub->getExecution()->getID());
        } catch (Throwable $e) {
            // Unknown failure mode — surface but do not lose the workflow ID
            return TransferAwaitOutcome::failed($stub->getExecution()->getID(), $e);
        }
    }
}
```

- [ ] **Step 2: Create `TransferAwaitOutcome` value object**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Wallet\Workflows;

use Throwable;

final class TransferAwaitOutcome
{
    private function __construct(
        public readonly string $workflowId,
        public readonly string $state,           // 'completed' | 'pending' | 'failed'
        public readonly mixed $result = null,
        public readonly ?Throwable $error = null,
    ) {}

    public static function completed(string $id, mixed $result): self { return new self($id, 'completed', $result); }
    public static function pending(string $id): self                  { return new self($id, 'pending'); }
    public static function failed(string $id, Throwable $e): self     { return new self($id, 'failed', null, $e); }
}
```

- [ ] **Step 3: Commit**

### Task 4.2: Refactor `TransferController` to sync-bounded-wait

**Files:**
- Modify: `app/Http/Controllers/Api/TransferController.php` (around lines 200-238)

- [ ] **Step 1: Read current code, confirm line ranges**
- [ ] **Step 2: Write failing test asserting sync behavior**

Test must assert: 200 returned with terminal balance state, AND tenant `account_balances` reflect the move, AND idempotent replay returns the same workflowId.

- [ ] **Step 3: Replace `WorkflowStub::make(...)->start()` with awaiter**

```php
$stub = WorkflowStub::make(WalletTransferWorkflow::class);
$stub->start(/* args */);

$outcome = app(SyncTransferAwaiter::class)->awaitOrAccept($stub);

return match ($outcome->state) {
    'completed' => response()->json([
        'status' => 'success',
        'data'   => $outcome->result, // includes new balances
        'transfer_id' => $outcome->workflowId,
    ], 200),
    'pending'   => response()->json([
        'status'      => 'pending',
        'transfer_id' => $outcome->workflowId,
        'status_url'  => route('transfers.status', ['id' => $outcome->workflowId]),
    ], 202),
    'failed'    => response()->json([
        'status'  => 'error',
        'message' => [$this->safeErrorMessage($outcome->error)],
    ], 422),
};
```

- [ ] **Step 4: Run tests, expect pass**
- [ ] **Step 5: Commit**

### Task 4.3: Add transfer status polling endpoint

**Files:**
- Create: `app/Http/Controllers/Api/V2/Transfers/TransferStatusController.php`
- Modify: `routes/api-v2.php`

- [ ] **Step 1: Write feature test**: `GET /api/v2/transfers/{id}/status` returns terminal balance when the workflow has completed.
- [ ] **Step 2: Implement controller** querying Temporal for workflow state by ID.
- [ ] **Step 3: Mount route** with name `transfers.status`.
- [ ] **Step 4: Pass tests + commit**

### Task 4.4: Idempotency-Key enforcement for transfers

**Files:**
- Modify: `app/Http/Middleware/IdempotencyMiddleware.php` OR a new `RequireIdempotencyKey` middleware

- [ ] **Step 1: Write failing test** asserting `POST /api/v2/transfers` without `Idempotency-Key` returns 400.
- [ ] **Step 2: Add a `RequireIdempotencyKey` middleware** and mount it before `IdempotencyMiddleware` on transfer routes.
- [ ] **Step 3: Commit**

### Task 4.5: Balance conservation invariant test

**Files:**
- Create: `tests/Feature/MoneyMovement/BalanceConservationTest.php`

- [ ] **Step 1: Write a property-style test**: for a randomized series of transfers between N accounts within a tenant, the sum of all balances after every transfer remains constant (modulo provider fees, if any). Run 100 iterations.

This test should have been in the suite from day one. It would have caught the projector bug immediately.

- [ ] **Step 2: Commit**

---

## Phase 5: [Mobile] Delete Legacy Pocket-Detail Screen

Work in `/Users/Lihle/Development/Coding/maphapayrn`.

### Task 5.1: Verify nothing else references the legacy screen

- [ ] **Step 1:**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
grep -rn "(tabs)/wallet/pocket-detail\|wallet/pocket-detail" src
```

- [ ] **Step 2:** For every reference besides `SavingsScreen.tsx`, update it to point at the canonical `/app/savings/pocket/[id]` route.

### Task 5.2: Remove the routing branch

**Files:**
- Modify: `src/features/savings/presentation/SavingsScreen.tsx`

- [ ] **Step 1: Replace conditional routing with the single canonical route**

```diff
 const handlePocketPress = (pocket: SavingsPocket) => {
-  if (isWalletSavingsRoute) {
-    router.push({ pathname: '/(tabs)/wallet/pocket-detail', params: { id: pocket.id } });
-  } else {
-    router.push({ pathname: '/savings/pocket/[id]', params: { id: pocket.id } });
-  }
+  router.push({ pathname: '/savings/pocket/[id]', params: { id: pocket.id } });
 };
```

- [ ] **Step 2:** Remove the `isWalletSavingsRoute` prop and any dead code downstream.

### Task 5.3: Delete the legacy screen file

- [ ] **Step 1:**

```bash
git rm src/app/(tabs)/wallet/pocket-detail.tsx
```

- [ ] **Step 2: Run the mobile typecheck**

```bash
bun run typecheck   # or yarn/npm equivalent
```
Resolve any orphaned imports.

### Task 5.4: Remove dead Zustand actions

**Files:**
- Modify: `src/features/wallet/store/savingsStore.ts`

- [ ] **Step 1:** Delete `addFunds` and `withdrawFunds` actions. They mutate local state only and are now unused.
- [ ] **Step 2:** Typecheck — fix orphan callers (there should be none after Task 5.3).

### Task 5.5: Commit

```bash
git add -A
git commit -m "refactor(savings): delete legacy pocket-detail and route to canonical screen

The legacy /(tabs)/wallet/pocket-detail screen mutated local Zustand
state without making any API call, producing phantom transfers that
disappeared on app restart. The canonical PocketDetailScreen correctly
awaits the mutation and invalidates balance queries.

Routes through SavingsScreen now always go to /savings/pocket/[id].

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Phase 6: [Mobile] Typed API Boundary with Zod

### Task 6.1: Zod schemas for money movement responses

**Files:**
- Create: `src/api/schemas/money-movement.ts`

- [ ] **Step 1: Implement**

```typescript
import { z } from 'zod';

export const compatEnvelope = <T extends z.ZodTypeAny>(dataSchema: T) =>
  z.object({
    status: z.enum(['success', 'error', 'pending']),
    message: z.array(z.string()).default([]),
    data: dataSchema.nullable(),
  });

export const userExistDataSchema = z.object({
  id: z.union([z.string(), z.number()]),
  username: z.string(),
  display_name: z.string(),
  mobile: z.string().nullable().optional(),
});

export const userExistResponseSchema = compatEnvelope(userExistDataSchema);
export type UserExistResponse = z.infer<typeof userExistResponseSchema>;

export const transferOutcomeSchema = z.discriminatedUnion('status', [
  z.object({
    status: z.literal('success'),
    transfer_id: z.string(),
    data: z.object({
      from_balance: z.string(),
      to_balance: z.string(),
      asset_code: z.string(),
    }),
  }),
  z.object({
    status: z.literal('pending'),
    transfer_id: z.string(),
    status_url: z.string().url(),
  }),
  z.object({
    status: z.literal('error'),
    message: z.array(z.string()),
  }),
]);
export type TransferOutcome = z.infer<typeof transferOutcomeSchema>;
```

- [ ] **Step 2: Update `useSendMoney.ts:57`** to validate with `userExistResponseSchema.parse(data)` and surface validation errors to Sentry.

- [ ] **Step 3: Update transfer mutation** similarly.

- [ ] **Step 4: Commit**

### Task 6.2: API client preserves full error context

**Files:**
- Modify: `src/api/apiClient.ts` (around `mapAxiosError`)

- [ ] **Step 1:** Ensure `ApiException` always carries `httpStatus`, `responseData`, `requestId` (from `x-request-id` response header), and `errorCode` (from response body if present).

- [ ] **Step 2:** Add a global axios response interceptor that, on any 5xx, calls `Sentry.captureException` with the `requestId` as a tag and the `responseData` as breadcrumb context. This makes the next "Server Error" report instantly diagnosable.

- [ ] **Step 3:** Update send-money catch ([src/app/(modals)/send-money.tsx:139](../../maphapayrn/src/app/(modals)/send-money.tsx:139)) to:
  - Show user the safe message
  - Capture the full `ApiException` to Sentry with breadcrumbs

- [ ] **Step 4: Commit**

### Task 6.3: `useMoneyMovementMutation` wrapper

**Files:**
- Create: `src/api/hooks/useMoneyMovementMutation.ts`

- [ ] **Step 1: Implement**

```typescript
import { useMutation, type UseMutationOptions, useQueryClient, type QueryKey } from '@tanstack/react-query';
import * as Sentry from '@sentry/react-native';
import { z } from 'zod';
import type { ApiException } from '@/api/apiClient';

interface MoneyMovementMutationOptions<TVars, TData> extends Omit<UseMutationOptions<TData, ApiException, TVars>, 'mutationFn'> {
  mutationFn: (vars: TVars) => Promise<unknown>;
  responseSchema: z.ZodType<TData>;
  /** Query keys that MUST be invalidated after a successful mutation. */
  invalidates: QueryKey[];
  /** Sentry transaction name for breadcrumbs. */
  operationName: string;
}

/**
 * Wrapper around useMutation that enforces the money-movement contract:
 *   - Response is validated by a Zod schema (loud failure on shape drift)
 *   - On success, the listed query keys are invalidated (no stale balances)
 *   - On error, full ApiException context is captured to Sentry
 *
 * Use this for every mutation that moves money. Plain useMutation is
 * forbidden for money-movement flows by the ESLint rule in eslintrc.
 */
export function useMoneyMovementMutation<TVars, TData>(opts: MoneyMovementMutationOptions<TVars, TData>) {
  const qc = useQueryClient();
  return useMutation<TData, ApiException, TVars>({
    ...opts,
    mutationFn: async (vars) => {
      const raw = await opts.mutationFn(vars);
      const parsed = opts.responseSchema.safeParse(raw);
      if (!parsed.success) {
        Sentry.captureMessage(`Schema drift: ${opts.operationName}`, {
          level: 'error',
          extra: { issues: parsed.error.issues, raw },
        });
        throw new Error(`Response shape invalid for ${opts.operationName}`);
      }
      return parsed.data;
    },
    onSuccess: (...args) => {
      for (const key of opts.invalidates) {
        qc.invalidateQueries({ queryKey: key });
      }
      opts.onSuccess?.(...args);
    },
    onError: (err, vars, ctx) => {
      Sentry.captureException(err, {
        tags: { operation: opts.operationName },
        extra: { httpStatus: err.httpStatus, requestId: err.requestId, body: err.responseData },
      });
      opts.onError?.(err, vars, ctx);
    },
  });
}
```

- [ ] **Step 2:** Migrate `withdrawFundsMutation` and `addFundsMutation` in `usePockets.ts` to use this wrapper.

- [ ] **Step 3:** Migrate `transferMutation` and `sendMoneyMutation` similarly.

- [ ] **Step 4:** Add ESLint rule that forbids `useMutation` in files under `src/features/{send-money,savings,wallet,pockets,transfers}/**` — only `useMoneyMovementMutation` is allowed.

- [ ] **Step 5: Commit**

---

## Phase 7: Contract Tests Preventing Regression

### Task 7.1: Static audit — central-table models extend `CentralModel`

**Files:**
- Create: `tests/Feature/Architecture/CentralModelAuditTest.php`

- [ ] **Step 1: Implement** — enumerate all `extends Model` classes that map to a table also present in central migrations; assert each extends `CentralModel` OR uses `UsesTenantConnection`. Allowlist exceptions with justification.

### Task 7.2: Static audit — projectors are tenant-aware (already created in Task 3.5)

- [ ] Confirm it runs green in CI.

### Task 7.3: Balance conservation property test (already created in Task 4.5)

- [ ] Confirm it runs green in CI.

### Task 7.4: Mobile lint rule — money-movement mutations use the wrapper

- [ ] **Step 1:** Add `.eslintrc` rule documented in Task 6.3 Step 4.
- [ ] **Step 2:** Confirm `bun run lint` is green.

---

## Self-Review Checklist

- [x] **Spec coverage:** Both reported bugs have a task chain that fixes them (Issue 1: Phase 2; Issue 2 mobile: Phase 5; Issue 2 backend: Phase 3). Architecture pillars all have tasks (tenancy boundary: Phase 1; event-carried tenancy: Phase 3; sync money APIs: Phase 4; typed mobile boundary: Phase 6; contract tests: Phase 7).
- [x] **No placeholders for production code:** All production primitives (CentralModel, TenantAwareProjector, SyncTransferAwaiter, useMoneyMovementMutation) have full code. The audit/migration tasks (1.5, 3.5, 4.3, 4.4, 7.1) intentionally describe pattern + loop rather than enumerating files because the loop is the deliverable.
- [x] **Type consistency:** `CarriesTenantContext::tenantAccountUuid()` returns `string` — consumed by `WithTenantContext::withAccountTenancy(string $accountUuid, ...)`. `TransferAwaitOutcome::$state` is `'completed' | 'pending' | 'failed'` — consumed by `TransferController` `match`.
- [x] **TDD ordering:** Every code task has the failing-test step before the implementation step.
- [x] **Commit cadence:** One commit per task minimum; per-projector commits in 3.5; per-fix commits in 1.5.

---

## Risks & Notes

1. **Phase 1 Task 1.5 (strict-mode fallout) is the largest unknown.** Until the test suite runs under strict mode we don't know how many call sites are wrong. This is expected — that's the audit's job. Plan time accordingly.

2. **Cross-tenant transfers** (a future flow) need *two* `CarriesTenantContext` events — one per side. The current `AssetTransferCompleted` carries only `fromAccountUuid`. When the cross-tenant flow is built, split this event into `AssetTransferDebited` + `AssetTransferCredited`. Out of scope for this plan.

3. **Temporal sync-wait latency budget.** 5s wait is generous for intra-tenant flows but tight for wallet-provider flows (MTN takes 10–30s). Provider flows must explicitly use the 202+poll path — do NOT raise the global timeout. If a provider flow ends up on `TransferController`, return 202 immediately rather than waiting.

4. **Idempotency-Key requirement** is a contract break for any client that wasn't sending one. Confirm mobile already sends `Idempotency-Key` on transfer POSTs (check `apiClient.ts`). If not, ship the mobile change first.

5. **Mobile Zustand removal in Task 5.4** may break unrelated screens that rely on the legacy actions. Typecheck must be green before merging.
