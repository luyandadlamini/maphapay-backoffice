<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;
use Throwable;

class UserConnectionTest extends TestCase
{
    /**
     * Disable DB transaction wrapping — we initialize tenancy which switches connections,
     * and wrapping breaks across connection switches on MySQL.
     *
     * @return array<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    /** @var list<string> Tenant IDs seeded in this test; deleted in tearDown. */
    private array $seededTenantIds = [];

    protected function tearDown(): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->initialized) {
            $tenancy->end();
        }

        if ($this->seededTenantIds !== []) {
            try {
                DB::connection('central')->reconnect();
            } catch (Throwable) {
                // best-effort
            }

            try {
                DB::connection('central')
                    ->table('tenants')
                    ->whereIn('id', $this->seededTenantIds)
                    ->delete();
            } catch (Throwable) {
                // best-effort cleanup
            }

            $this->seededTenantIds = [];
        }

        parent::tearDown();
    }

    /**
     * Insert a tenant row directly into the central DB without firing Stancl events
     * (avoids CreateDatabase job failures on MySQL after migration-heavy setUp()).
     */
    private function seedTenantDirectly(): Tenant
    {
        $tenantId = (string) Str::uuid();

        DB::connection('central')->table('tenants')->insert([
            'id'            => $tenantId,
            'name'          => 'UserConnection test tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        $this->seededTenantIds[] = $tenantId;

        /** @var Tenant $tenant */
        $tenant = Tenant::on('central')->findOrFail($tenantId);

        return $tenant;
    }

    #[Test]
    public function test_user_is_pinned_to_central_connection(): void
    {
        $user = new User();
        $this->assertSame('central', $user->getConnectionName());
    }

    #[Test]
    public function test_user_queries_central_even_when_tenancy_is_initialized(): void
    {
        if ($this->isInMemorySqlite()) {
            $this->markTestSkipped(
                'User tenancy connection test requires MySQL (SQLite :memory: cannot share tables across connections)'
            );
        }

        $user = User::factory()->create();

        $tenant = $this->seedTenantDirectly();
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
