<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources\AccountResource\Pages;

use App\Domain\Account\Models\AccountMembership;
use App\Filament\Admin\Resources\AccountResource\Pages\ListAccounts;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class ListAccountsTest extends TestCase
{
    /**
     * Disable DB transaction wrapping — AccountMembership rows are on the central
     * connection; mixing connections with transactions causes issues on MySQL.
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

    /** @var list<string> AccountMembership IDs seeded in this test. */
    private array $seededMembershipIds = [];

    /** @var list<string> Tenant IDs seeded in this test. */
    private array $seededTenantIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->isInMemorySqlite()) {
            $this->markTestSkipped(
                'ListAccounts cross-tenant tests require MySQL (AccountMembership uses central connection)'
            );
        }

        $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    }

    protected function tearDown(): void
    {
        if ($this->seededMembershipIds !== []) {
            try {
                DB::connection('central')
                    ->table('account_memberships')
                    ->whereIn('id', $this->seededMembershipIds)
                    ->delete();
            } catch (Throwable) {
                // best-effort cleanup
            }
        }

        if ($this->seededTenantIds !== []) {
            try {
                DB::connection('central')
                    ->table('tenants')
                    ->whereIn('id', $this->seededTenantIds)
                    ->delete();
            } catch (Throwable) {
                // best-effort cleanup
            }
        }

        $this->seededMembershipIds = [];
        $this->seededTenantIds = [];

        parent::tearDown();
    }

    private function seedTenantDirectly(string $tenantId): Tenant
    {
        $central = DB::connection('central');

        if (! $central->table('tenants')->where('id', $tenantId)->exists()) {
            $central->table('tenants')->insert([
                'id'            => $tenantId,
                'name'          => 'ListAccounts test tenant',
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
    public function list_page_renders_successfully_with_account_membership_rows(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('finance-lead');
        $this->actingAs($admin);

        $tenantA = $this->seedTenantDirectly((string) Str::uuid());
        $tenantB = $this->seedTenantDirectly((string) Str::uuid());

        $membershipA = AccountMembership::factory()->create([
            'tenant_id'    => $tenantA->id,
            'account_uuid' => (string) Str::uuid(),
            'account_type' => 'personal',
            'status'       => 'active',
            'display_name' => 'Alice Wallet',
        ]);
        $this->seededMembershipIds[] = $membershipA->id;

        $membershipB = AccountMembership::factory()->create([
            'tenant_id'    => $tenantB->id,
            'account_uuid' => (string) Str::uuid(),
            'account_type' => 'merchant',
            'status'       => 'suspended',
            'display_name' => 'Bob Business',
        ]);
        $this->seededMembershipIds[] = $membershipB->id;

        Livewire::test(ListAccounts::class)
            ->assertSuccessful();
    }

    #[Test]
    public function list_page_uses_account_membership_as_data_source(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('finance-lead');
        $this->actingAs($admin);

        $tenant = $this->seedTenantDirectly((string) Str::uuid());
        $accountUuid = (string) Str::uuid();

        $membership = AccountMembership::factory()->create([
            'tenant_id'    => $tenant->id,
            'account_uuid' => $accountUuid,
            'account_type' => 'personal',
            'status'       => 'active',
            'display_name' => 'Cross-Tenant Account',
        ]);
        $this->seededMembershipIds[] = $membership->id;

        // Verify the resource query uses AccountMembership (central DB)
        $query = \App\Filament\Admin\Resources\AccountResource::getEloquentQuery();
        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Builder::class,
            $query
        );

        $model = $query->getModel();
        $this->assertInstanceOf(AccountMembership::class, $model);
        $this->assertSame('central', $model->getConnectionName());
    }
}
