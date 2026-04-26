<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Services\ScaVerificationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class CoGuardianScaTest extends TestCase
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
            'id'            => $this->tenantId,
            'name'          => 'T',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        $guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardian->uuid,
            'type'      => 'personal',
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->guardian->uuid,
            'account_uuid' => $guardianAccount->uuid,
            'tenant_id'    => $this->tenantId,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
        ]);

        $child = User::factory()->create();
        $this->minorAccount = Account::factory()->create([
            'user_uuid'         => $child->uuid,
            'type'              => 'minor',
            'tier'              => 'rise',
            'permission_level'  => 3,
            'parent_account_id' => $guardianAccount->uuid,
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->guardian->uuid,
            'account_uuid' => $this->minorAccount->uuid,
            'tenant_id'    => $this->tenantId,
            'account_type' => 'minor',
            'role'         => 'guardian',
            'status'       => 'active',
        ]);
    }

    public function test_rejects_co_guardian_invite_creation_without_sca_verification(): void
    {
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->postJson(
            "/api/accounts/minor/{$this->minorAccount->uuid}/invite-co-guardian"
        );

        $response->assertStatus(428);
    }

    public function test_accepts_co_guardian_invite_creation_with_valid_sca(): void
    {
        $this->mock(ScaVerificationService::class, function ($mock): void {
            $mock->shouldReceive('verifyOtp')
                ->once()
                ->andReturn(true);
        });

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->postJson(
            "/api/accounts/minor/{$this->minorAccount->uuid}/invite-co-guardian",
            [
                'sca_token' => '123456',
                'sca_type'  => 'otp',
            ]
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'data' => ['code', 'expires_at'],
        ]);

        Mockery::close();
    }
}
