<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountAuditLog;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Services\MinorPointsService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class MinorPermissionLevelIdempotencyTest extends TestCase
{
    private User $guardian;

    private Account $minorAccount;

    private string $tenantId;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->guardian = User::factory()->create();
        $this->tenantId = (string) Str::uuid();

        DB::connection('central')->table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'T',
            'plan' => 'default',
            'team_id' => null,
            'trial_ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'data' => json_encode([]),
        ]);

        $guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardian->uuid,
            'type' => 'personal',
        ]);
        AccountMembership::create([
            'user_uuid' => $this->guardian->uuid,
            'account_uuid' => $guardianAccount->uuid,
            'tenant_id' => $this->tenantId,
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $child = User::factory()->create();
        $this->minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type' => 'minor',
            'tier' => 'rise',
            'permission_level' => 1,
            'parent_account_id' => $guardianAccount->uuid,
        ]);
        AccountMembership::create([
            'user_uuid' => $this->guardian->uuid,
            'account_uuid' => $this->minorAccount->uuid,
            'tenant_id' => $this->tenantId,
            'account_type' => 'minor',
            'role' => 'guardian',
            'status' => 'active',
        ]);
    }

    public function test_idempotent_permission_level_upgrade_awards_points_once(): void
    {
        $pointsServiceMock = Mockery::mock(MinorPointsService::class);
        $pointsServiceMock->shouldReceive('award')
            ->once()
            ->andReturnNull();
        $this->app->instance(MinorPointsService::class, $pointsServiceMock);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $idempotencyKey = (string) Str::uuid();

        // First call should succeed and award points
        $response1 = $this->putJson(
            "/api/accounts/minor/{$this->minorAccount->uuid}/permission-level",
            [
                'permission_level' => 3,
                'idempotency_key' => $idempotencyKey,
            ]
        );

        $response1->assertStatus(200)
            ->assertJsonPath('success', true);

        // Second call with same idempotency key should short-circuit
        $response2 = $this->putJson(
            "/api/accounts/minor/{$this->minorAccount->uuid}/permission-level",
            [
                'permission_level' => 3,
                'idempotency_key' => $idempotencyKey,
            ]
        );

        $response2->assertStatus(200)
            ->assertJsonPath('success', true);

        // Ensure only one audit log entry was created
        $auditCount = AccountAuditLog::query()
            ->where('account_uuid', $this->minorAccount->uuid)
            ->where('action', 'permission_level_changed')
            ->where('metadata->idempotency_key', $idempotencyKey)
            ->count();

        $this->assertSame(1, $auditCount);

        Mockery::close();
    }
}
