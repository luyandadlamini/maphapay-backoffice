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
        $user = User::factory()->create();
        $team = Team::factory()->create([
            'user_id' => $user->id,
            'name' => 'Owner Team',
        ]);
        $tenant = Tenant::createFromTeam($team);

        return [$user, $tenant];
    }
}
