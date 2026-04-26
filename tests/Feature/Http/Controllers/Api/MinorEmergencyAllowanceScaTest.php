<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MinorEmergencyAllowanceScaTest extends TestCase
{
    private User $guardian;

    private Account $minorAccount;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->guardian = User::factory()->create();
        $this->tenantId = (string) Str::uuid();

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

    public function test_rejects_set_emergency_allowance_without_sca_verification(): void
    {
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->putJson(
            "/api/v1/accounts/minor/{$this->minorAccount->uuid}/emergency-allowance",
            ['amount' => 5000]
        );

        $response->assertStatus(428);
    }

    public function test_accepts_set_emergency_allowance_with_valid_sca(): void
    {
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->putJson(
            "/api/v1/accounts/minor/{$this->minorAccount->uuid}/emergency-allowance",
            [
                'amount'    => 5000,
                'sca_token' => '123456',
                'sca_type'  => 'otp',
            ]
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
