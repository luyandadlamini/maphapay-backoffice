<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\AccountMembership;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesApplication;

class MultiAccountControllerTest extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('central')->getPdo();
        } catch (\Throwable $exception) {
            $this->markTestSkipped('Central database connection not available: ' . $exception->getMessage());
        }

        if (! Schema::connection('central')->hasTable('account_memberships')) {
            Artisan::call('migrate', [
                '--database' => 'central',
                '--force' => true,
            ]);
        }
    }

    public function test_lists_user_accounts_from_memberships(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();

        AccountMembership::query()->create([
            'user_uuid' => $user->uuid,
            'tenant_id' => $tenant->id,
            'account_uuid' => 'acc-personal',
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($user, ['read', 'write']);

        $response = $this->getJson('/api/accounts');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.account_type', 'personal')
            ->assertJsonPath('data.0.tenant_id', $tenant->id);
    }

    public function test_creates_merchant_account(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();

        AccountMembership::query()->create([
            'user_uuid' => $user->uuid,
            'tenant_id' => $tenant->id,
            'account_uuid' => 'acc-personal',
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($user, ['write']);

        $response = $this->postJson('/api/accounts/merchant', [
            'trade_name' => 'Lihle Market Stall',
            'merchant_category' => 'food_and_beverage',
            'classification' => 'informal',
            'settlement_method' => 'maphapay_wallet',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_type', 'merchant')
            ->assertJsonPath('data.role', 'owner');

        $this->assertDatabaseHas('account_memberships', [
            'user_uuid' => $user->uuid,
            'tenant_id' => $tenant->id,
            'account_type' => 'merchant',
            'role' => 'owner',
            'status' => 'active',
        ], 'central');
    }

    public function test_prevents_duplicate_merchant_account(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();

        AccountMembership::query()->create([
            'user_uuid' => $user->uuid,
            'tenant_id' => $tenant->id,
            'account_uuid' => 'acc-personal',
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        AccountMembership::query()->create([
            'user_uuid' => $user->uuid,
            'tenant_id' => $tenant->id,
            'account_uuid' => 'acc-merchant',
            'account_type' => 'merchant',
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($user, ['write']);

        $response = $this->postJson('/api/accounts/merchant', [
            'trade_name' => 'Another Stall',
            'merchant_category' => 'retail',
            'classification' => 'informal',
            'settlement_method' => 'maphapay_wallet',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['merchant']);
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function createUserAndTenant(): array
    {
        $user = User::factory()->create([
            'kyc_status' => 'approved',
        ]);
        $team = Team::factory()->create([
            'user_id' => $user->id,
            'name' => 'Owner Team',
        ]);
        $tenant = Tenant::createFromTeam($team);

        $tenant->run(function (): void {
            if (! Schema::connection('tenant')->hasTable('accounts')) {
                (require base_path('database/migrations/tenant/0001_01_01_000001_create_tenant_accounts_table.php'))->up();
            }

            if (! Schema::connection('tenant')->hasTable('ledgers')) {
                (require base_path('database/migrations/2024_08_28_154719_create_ledgers_table.php'))->up();
            }

            if (! Schema::connection('tenant')->hasColumn('accounts', 'display_name')) {
                (require base_path('database/migrations/tenant/2026_04_15_100001_add_multi_account_columns_to_accounts.php'))->up();
            }

            if (! Schema::connection('tenant')->hasTable('account_profiles_merchant')) {
                (require base_path('database/migrations/tenant/2026_04_15_100002_create_account_profiles_merchant_table.php'))->up();
            }

            if (! Schema::connection('tenant')->hasTable('account_audit_logs')) {
                (require base_path('database/migrations/tenant/2026_04_15_100003_create_account_audit_logs_table.php'))->up();
            }

            if (! Schema::connection('tenant')->hasColumn('accounts', 'account_number')) {
                (require base_path('database/migrations/tenant/2026_04_15_100004_add_account_number_to_tenant_accounts_table.php'))->up();
            }
        });

        return [$user, $tenant];
    }
}
