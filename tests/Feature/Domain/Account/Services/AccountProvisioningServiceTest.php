<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Services\AccountProvisioningService;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\CreatesApplication;
use Throwable;

/**
 * End-to-end coverage for the AccountProvisioningService, which is the
 * single orchestrator responsible for the central directory quintet:
 *
 *   User -> Team (personal) -> Tenant -> tenant DB -> Account -> AccountMembership
 *
 * Production diagnostics on 2026-05-18 found 3 of 3 real human users had
 * only a User row — registration paths weren't producing the rest of the
 * quintet, which broke send-money recipient resolution after commit e326e01d
 * switched recipient lookup to the central AccountMembership directory.
 *
 * The service is the contract that closes that gap. Every User-creating
 * code path is required to call ensureProvisioned() to satisfy the
 * invariant.
 */
class AccountProvisioningServiceTest extends BaseTestCase
{
    use CreatesApplication;

    private AccountProvisioningService $service;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('central')->getPdo();
        } catch (Throwable $exception) {
            $this->markTestSkipped('Central database connection not available: ' . $exception->getMessage());
        }

        if (! Schema::connection('central')->hasTable('account_memberships')) {
            Artisan::call('migrate', [
                '--database' => 'central',
                '--force'    => true,
            ]);
        }

        if (! Schema::connection('central')->hasTable('account_audit_logs')) {
            Schema::connection('central')->create('account_audit_logs', function ($table): void {
                $table->uuid('id')->primary();
                $table->uuid('account_uuid');
                $table->uuid('actor_user_uuid');
                $table->string('action');
                $table->string('target_type')->nullable();
                $table->uuid('target_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['account_uuid', 'created_at']);
                $table->index('actor_user_uuid');
            });
        }

        $this->service = app(AccountProvisioningService::class);
    }

    public function test_ensure_provisioned_creates_full_quintet_for_fresh_user(): void
    {
        $user = $this->makeFreshUser('quintet+' . uniqid('', true) . '@example.test');

        try {
            $membership = $this->service->ensureProvisioned($user);
        } catch (QueryException $exception) {
            $this->markTestSkipped('Tenant DB privileges unavailable: ' . $exception->getMessage());
        }

        // 1. Personal Team exists and is owned by the user.
        $team = Team::query()
            ->where('user_id', $user->id)
            ->where('personal_team', true)
            ->first();
        $this->assertNotNull($team, 'Personal Team should be created for the user.');

        // 2. Central Tenant exists pointing at that Team.
        $tenant = Tenant::query()->where('team_id', $team->id)->first();
        $this->assertNotNull($tenant, 'Central Tenant should be created for the Team.');

        // 3. Account exists (factory uses the same connection as everything else
        //    in the testing environment per UsesTenantConnection::shouldUseDefaultConnection).
        $account = Account::query()
            ->where('user_uuid', $user->uuid)
            ->first();
        $this->assertNotNull($account, 'Account should be created for the user.');
        $this->assertSame('personal', (string) $account->type);

        // 4. AccountMembership exists, active, owner, central, pointing everything together.
        $this->assertSame($user->uuid, $membership->user_uuid);
        $this->assertSame($tenant->id, $membership->tenant_id);
        $this->assertSame($account->uuid, $membership->account_uuid);
        $this->assertSame('owner', $membership->role);
        $this->assertSame('active', $membership->status);
        $this->assertDatabaseHas('account_memberships', [
            'id'           => $membership->id,
            'user_uuid'    => $user->uuid,
            'tenant_id'    => $tenant->id,
            'account_uuid' => $account->uuid,
            'role'         => 'owner',
            'status'       => 'active',
        ], 'central');
    }

    public function test_ensure_provisioned_is_idempotent_and_returns_existing_membership(): void
    {
        $user = $this->makeFreshUser('idempotent+' . uniqid('', true) . '@example.test');

        try {
            $first = $this->service->ensureProvisioned($user);
        } catch (QueryException $exception) {
            $this->markTestSkipped('Tenant DB privileges unavailable: ' . $exception->getMessage());
        }

        $second = $this->service->ensureProvisioned($user);

        $this->assertSame($first->id, $second->id, 'Idempotent runs must return the same membership row.');

        $this->assertSame(
            1,
            Team::query()->where('user_id', $user->id)->where('personal_team', true)->count(),
            'Idempotent runs must not duplicate personal Teams.',
        );

        $teamId = Team::query()->where('user_id', $user->id)->where('personal_team', true)->value('id');
        $this->assertSame(
            1,
            Tenant::query()->where('team_id', $teamId)->count(),
            'Idempotent runs must not duplicate Tenants.',
        );

        $this->assertSame(
            1,
            Account::query()->where('user_uuid', $user->uuid)->count(),
            'Idempotent runs must not duplicate Accounts.',
        );

        $this->assertSame(
            1,
            AccountMembership::query()
                ->where('user_uuid', $user->uuid)
                ->where('status', 'active')
                ->count(),
            'Idempotent runs must not duplicate active memberships.',
        );
    }

    public function test_ensure_provisioned_repairs_partial_state_with_pre_existing_team(): void
    {
        $user = $this->makeFreshUser('partial+' . uniqid('', true) . '@example.test');

        // Caller already created a personal Team but never created a Tenant
        // or Account — exactly the OAuth/MobileOTP shape that the production
        // diagnostic surfaced.
        $team = Team::forceCreate([
            'user_id'       => $user->id,
            'name'          => $user->name . "'s Team",
            'personal_team' => true,
        ]);
        $user->ownedTeams()->save($team);

        try {
            $membership = $this->service->ensureProvisioned($user);
        } catch (QueryException $exception) {
            $this->markTestSkipped('Tenant DB privileges unavailable: ' . $exception->getMessage());
        }

        // Existing Team is reused, not duplicated.
        $this->assertSame(
            1,
            Team::query()->where('user_id', $user->id)->where('personal_team', true)->count(),
        );

        // Missing rows are now present.
        $this->assertNotNull(Tenant::query()->where('team_id', $team->id)->first());
        $this->assertNotNull(Account::query()->where('user_uuid', $user->uuid)->first());
        $this->assertSame('active', $membership->status);
        $this->assertSame('owner', $membership->role);
    }

    private function makeFreshUser(string $email): User
    {
        $user = User::factory()->make(['email' => $email]);
        $user->setConnection('central');
        $user->save();

        return $user;
    }
}
