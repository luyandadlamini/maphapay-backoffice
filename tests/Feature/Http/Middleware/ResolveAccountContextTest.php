<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Domain\Account\Models\AccountMembership;
use App\Http\Middleware\ResolveAccountContext;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesApplication;

class ResolveAccountContextTest extends BaseTestCase
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

        Route::middleware(['auth:sanctum', ResolveAccountContext::class])
            ->get('/__test/account-context', function (Request $request) {
                return response()->json([
                    'account_uuid' => $request->attributes->get('account_uuid'),
                    'account_type' => $request->attributes->get('account_type'),
                    'account_role' => $request->attributes->get('account_role'),
                    'tenant_id' => $request->attributes->get('tenant_id'),
                ]);
            });
    }

    public function test_resolves_account_from_header(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();

        AccountMembership::query()->create([
            'user_uuid' => $user->uuid,
            'tenant_id' => $tenant->id,
            'account_uuid' => 'acc-123',
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->withHeaders(['X-Account-Id' => 'acc-123'])
            ->getJson('/__test/account-context');

        $response->assertOk()
            ->assertJson([
                'account_uuid' => 'acc-123',
                'account_type' => 'personal',
                'account_role' => 'owner',
                'tenant_id' => $tenant->id,
            ]);
    }

    public function test_falls_back_to_personal_account_without_header(): void
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

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/__test/account-context');

        $response->assertOk()
            ->assertJson([
                'account_uuid' => 'acc-personal',
                'account_type' => 'personal',
                'account_role' => 'owner',
                'tenant_id' => $tenant->id,
            ]);
    }

    public function test_rejects_account_user_has_no_membership_for(): void
    {
        [$user] = $this->createUserAndTenant();

        Sanctum::actingAs($user, ['*']);

        $response = $this->withHeaders(['X-Account-Id' => 'not-my-account'])
            ->getJson('/__test/account-context');

        $response->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to this account.');
    }

    public function test_rejects_suspended_membership(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();

        AccountMembership::query()->create([
            'user_uuid' => $user->uuid,
            'tenant_id' => $tenant->id,
            'account_uuid' => 'acc-suspended',
            'account_type' => 'merchant',
            'role' => 'owner',
            'status' => 'suspended',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->withHeaders(['X-Account-Id' => 'acc-suspended'])
            ->getJson('/__test/account-context');

        $response->assertForbidden();
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
