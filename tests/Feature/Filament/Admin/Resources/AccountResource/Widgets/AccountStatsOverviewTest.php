<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources\AccountResource\Widgets;

use App\Filament\Admin\Resources\AccountResource\Widgets\AccountStatsOverview;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;
use Throwable;

class AccountStatsOverviewTest extends TestCase
{
    /**
     * Disable DB transaction wrapping — we bypass model events to avoid the
     * Stancl CreateDatabase job failures on MySQL after migration-heavy setUp() runs.
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

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->isInMemorySqlite()) {
            $this->markTestSkipped(
                'AccountStatsOverview tenancy tests require MySQL (SQLite :memory: cannot share tables across connections)'
            );
        }

        $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    }

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
                // ignore reconnect failure; cleanup is best-effort
            }

            try {
                DB::connection('central')
                    ->table('tenants')
                    ->whereIn('id', $this->seededTenantIds)
                    ->delete();
            } catch (Throwable) {
                // best-effort cleanup
            }
        }

        $this->seededTenantIds = [];

        parent::tearDown();
    }

    /**
     * Insert a tenant row directly into the central DB without firing Stancl events.
     */
    private function seedTenantDirectly(string $tenantId, string $name = 'Stats test tenant'): Tenant
    {
        $central = DB::connection('central');

        if (! $central->table('tenants')->where('id', $tenantId)->exists()) {
            $central->table('tenants')->insert([
                'id'            => $tenantId,
                'name'          => $name,
                'plan'          => 'default',
                'team_id'       => null,
                'trial_ends_at' => null,
                'created_at'    => now(),
                'updated_at'    => now(),
                'data'          => json_encode([]),
            ]);
        }

        $this->seededTenantIds[] = $tenantId;

        /** @var Tenant */
        return Tenant::find($tenantId);
    }

    #[Test]
    public function dashboard_mode_renders_without_error_when_no_tenants_exist(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        // No $record passed — dashboard mode
        $component = Livewire::test(AccountStatsOverview::class);
        $component->assertHasNoErrors();
    }

    #[Test]
    public function dashboard_mode_renders_with_tenants_present(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        // Seed two tenant rows into the central DB without firing Stancl's CreateDatabase event.
        // In the test env UsesTenantConnection always routes to the default connection, so
        // the widget's per-tenant queries will run against the shared test DB — but the important
        // thing being tested is that the widget iterates Tenant::on('central')->lazy() without
        // crashing and returns correctly shaped stats.
        $this->seedTenantDirectly((string) Str::uuid(), 'Stats tenant A');
        $this->seedTenantDirectly((string) Str::uuid(), 'Stats tenant B');

        $component = Livewire::test(AccountStatsOverview::class);
        $component->assertHasNoErrors();

        // Verify the widget renders the expected stat headings
        $component->assertSeeHtml('Total Accounts');
        $component->assertSeeHtml('Total Balance');
        $component->assertSeeHtml('Frozen Accounts');
    }
}
