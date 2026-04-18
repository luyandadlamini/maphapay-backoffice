<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorSpendApproval;
use App\Domain\Asset\Models\Asset;
use App\Domain\Mobile\Models\MobileDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class MinorEmergencyBypassTest extends ControllerTestCase
{
    protected function connectionsToTransact(): array
    {
        return ['mysql', 'central'];
    }

    private User $parent;

    private User $child;

    private User $recipient;

    private Account $parentAccount;

    private Account $minorAccount;

    private Account $recipientAccount;

    private string $tenantId;

    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        config([
            'maphapay_migration.enable_send_money' => true,
            'mobile.attestation.enabled' => false,
        ]);

        Asset::updateOrCreate(
            ['code' => 'SZL'],
            [
                'name' => 'Swazi Lilangeni',
                'type' => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        $this->tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Test Tenant',
            'plan' => 'default',
            'team_id' => null,
            'trial_ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'data' => json_encode([]),
        ]);

        $this->parent = User::factory()->create();
        $this->child = User::factory()->create();
        $this->recipient = User::factory()->create();

        $this->parentAccount = Account::factory()->create([
            'user_uuid' => $this->parent->uuid,
            'type' => 'personal',
            'balance' => 5_000,
        ]);

        AccountMembership::create([
            'user_uuid' => $this->parent->uuid,
            'account_uuid' => $this->parentAccount->uuid,
            'tenant_id' => $this->tenantId,
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid' => $this->child->uuid,
            'type' => 'minor',
            'tier' => 'grow',
            'permission_level' => 3,
            'parent_account_id' => $this->parentAccount->uuid,
            'balance' => 1_000,
        ]);

        AccountMembership::create([
            'user_uuid' => $this->parent->uuid,
            'account_uuid' => $this->minorAccount->uuid,
            'tenant_id' => $this->tenantId,
            'account_type' => 'minor',
            'role' => 'guardian',
            'status' => 'active',
        ]);

        $this->recipientAccount = Account::factory()->create([
            'user_uuid' => $this->recipient->uuid,
            'type' => 'personal',
            'balance' => 0,
        ]);

        AccountMembership::create([
            'user_uuid' => $this->recipient->uuid,
            'account_uuid' => $this->recipientAccount->uuid,
            'tenant_id' => $this->tenantId,
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->deviceId = 'trusted-device-' . $this->child->id;
        MobileDevice::factory()
            ->trusted()
            ->ios()
            ->create([
                'user_id' => $this->child->id,
                'device_id' => $this->deviceId,
            ]);
    }

    #[Test]
    public function emergency_allowance_covering_request_bypasses_approval_and_decrements_balance(): void
    {
        $this->setEmergencyAllowance(200);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'X-Device-ID' => $this->deviceId,
        ])->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '150.00',
            'verification_type' => 'pin',
        ]);

        $response->assertOk();
        $this->assertNotEquals(202, $response->status());
        $this->assertDatabaseMissing('minor_spend_approvals', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'amount' => '150.00',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('accounts', [
            'uuid' => $this->minorAccount->uuid,
            'emergency_allowance_balance' => 50,
        ]);
    }

    #[Test]
    public function insufficient_emergency_allowance_creates_approval_and_does_not_decrement_balance(): void
    {
        $this->setEmergencyAllowance(100);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'X-Device-ID' => $this->deviceId,
        ])->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '150.00',
            'verification_type' => 'pin',
        ]);

        $response->assertStatus(202);
        $approvalId = $response->json('data.approval_id');
        $this->assertNotNull($approvalId);

        $this->assertDatabaseHas('minor_spend_approvals', [
            'id' => $approvalId,
            'minor_account_uuid' => $this->minorAccount->uuid,
            'amount' => '150.00',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('accounts', [
            'uuid' => $this->minorAccount->uuid,
            'emergency_allowance_balance' => 100,
        ]);
    }

    #[Test]
    public function zero_emergency_allowance_does_not_bypass_approval(): void
    {
        $this->setEmergencyAllowance(0);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'X-Device-ID' => $this->deviceId,
        ])->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '150.00',
            'verification_type' => 'pin',
        ]);

        $response->assertStatus(202);

        $this->assertDatabaseHas('minor_spend_approvals', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'amount' => '150.00',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('accounts', [
            'uuid' => $this->minorAccount->uuid,
            'emergency_allowance_balance' => 0,
        ]);
    }

    #[Test]
    public function resetting_emergency_allowance_refills_the_balance(): void
    {
        $this->setEmergencyAllowance(200);

        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);

        $this->withHeaders([
            'X-Device-ID' => $this->deviceId,
        ])->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '150.00',
            'verification_type' => 'pin',
        ])->assertOk();

        Sanctum::actingAs($this->parent, ['read', 'write', 'delete']);

        $response = $this->putJson(
            '/api/accounts/minor/' . $this->minorAccount->uuid . '/emergency-allowance',
            ['amount' => 200]
        );

        $response->assertOk()
            ->assertJsonPath('data.emergency_allowance_balance', 200);

        $this->assertDatabaseHas('accounts', [
            'uuid' => $this->minorAccount->uuid,
            'emergency_allowance_balance' => 200,
        ]);
    }

    private function setEmergencyAllowance(int $amount): void
    {
        Sanctum::actingAs($this->parent, ['read', 'write', 'delete']);

        $response = $this->putJson(
            '/api/accounts/minor/' . $this->minorAccount->uuid . '/emergency-allowance',
            ['amount' => $amount]
        );

        $response->assertOk();
    }
}
