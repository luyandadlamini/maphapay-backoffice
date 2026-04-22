<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorSpendApproval;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2 Integration Test: Full Spend Enforcement Workflow.
 *
 * Exercises the complete lifecycle:
 * 1. Child tries blocked category → 422
 * 2. Child spends above approval threshold → 202 with approval ID
 * 3. Guardian lists pending approvals
 * 4. Guardian declines approval
 * 5. Expiry command runs (idempotent check)
 * 6. Guardian sets emergency allowance
 */
class MinorAccountPhase2IntegrationTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
    return ['mysql', 'central'];
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
    return false;
    }

    private User $parent;

    private User $child;

    private User $guardian;

    private User $recipient;

    private Account $parentAccount;

    private Account $minorAccount;

    private Account $recipientAccount;

    private Account $guardianAccount;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        config([
            'maphapay_migration.enable_send_money' => true,
            'mobile.attestation.enabled'           => false,
        ]);

        // Create tenant
        $this->tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id'            => $this->tenantId,
            'name'          => 'Test Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        // Create users
        $this->parent = User::factory()->create();
        $this->child = User::factory()->create();
        $this->guardian = User::factory()->create();
        $this->recipient = User::factory()->create();

        // Create parent account
        $this->parentAccount = Account::factory()->create([
            'user_uuid' => $this->parent->uuid,
            'type'      => 'personal',
            'balance'   => 5000,
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->parent->uuid,
            'account_uuid' => $this->parentAccount->uuid,
            'tenant_id'    => $this->tenantId,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
        ]);

        // Create guardian account (co-guardian scenario)
        $this->guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardian->uuid,
            'type'      => 'personal',
            'balance'   => 1000,
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->guardian->uuid,
            'account_uuid' => $this->guardianAccount->uuid,
            'tenant_id'    => $this->tenantId,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
        ]);

        // Create minor account (permission level 3 = can spend 500 SZL daily, 5000 SZL monthly)
        $this->minorAccount = Account::factory()->create([
            'user_uuid'          => $this->child->uuid,
            'type'               => 'minor',
            'tier'               => 'grow',
            'permission_level'   => 3,
            'parent_account_id'  => $this->parentAccount->uuid,
            'balance'            => 1000,
            'daily_limit'        => 500,
            'monthly_limit'      => 5000,
            'approval_threshold' => 100,
        ]);

        // Register parent as primary guardian
        AccountMembership::create([
            'user_uuid'    => $this->parent->uuid,
            'account_uuid' => $this->minorAccount->uuid,
            'tenant_id'    => $this->tenantId,
            'account_type' => 'minor',
            'role'         => 'guardian',
            'status'       => 'active',
        ]);

        // Register guardian as co-guardian
        AccountMembership::create([
            'user_uuid'    => $this->guardian->uuid,
            'account_uuid' => $this->minorAccount->uuid,
            'tenant_id'    => $this->tenantId,
            'account_type' => 'minor',
            'role'         => 'guardian',
            'status'       => 'active',
        ]);

        // Create trusted device for child to bypass device trust policy
        $deviceId = 'test-device-' . $this->child->id;
        try {
            MobileDevice::factory()
                ->trusted()
                ->ios()
                ->create([
                    'user_id'   => $this->child->id,
                    'device_id' => $deviceId,
                ]);
        } catch (Exception) {
            // Device creation may fail if table doesn't exist, that's OK
        }

        // Create recipient account
        $this->recipientAccount = Account::factory()->create([
            'user_uuid' => $this->recipient->uuid,
            'type'      => 'personal',
            'balance'   => 0,
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->recipient->uuid,
            'account_uuid' => $this->recipientAccount->uuid,
            'tenant_id'    => $this->tenantId,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
        ]);
    }

    /**
     * Full spend enforcement workflow.
     *
     * 1. Child tries blocked category (alcohol) → 422
     * 2. Child spends above approval threshold → 202 with approval ID
     * 3. Guardian lists pending approvals
     * 4. Guardian declines approval
     * 5. Expiry command runs (idempotent)
     * 6. Guardian sets emergency allowance
     */
    #[Test]
    public function full_spend_enforcement_workflow(): void
    {
        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);

        // === SCENARIO 1: Blocked category (alcohol) ===
        // Child tries to spend on alcohol category (blocked for all minor levels)
        $blockedResponse = $this->postJson(
            '/api/send-money/store',
            [
                'user'              => $this->recipient->mobile ?? $this->recipient->email,
                'amount'            => 50,
                'merchant_category' => 'alcohol',
            ]
        );

        // Should be rejected with 422 (Unprocessable Entity)
        $blockedResponse->assertStatus(422);
        $this->assertStringContainsString('not allowed', strtolower($blockedResponse->json('message') ?? ''));

        // === SCENARIO 2: Spend above approval threshold ===
        // Child spends 150 SZL, which exceeds the 100 SZL approval threshold
        // This should create a MinorSpendApproval and return 202
        $approvalNeededResponse = $this->withHeaders([
            'X-Device-ID' => 'test-device-' . $this->child->id,
        ])->postJson(
            '/api/send-money/store',
            [
                'user'   => $this->recipient->mobile ?? $this->recipient->email,
                'amount' => 150.00,
            ]
        );

        // Should return 202 Accepted (pending approval)
        $approvalNeededResponse->assertStatus(202);
        $approvalId = $approvalNeededResponse->json('data.approval_id');
        $this->assertNotNull($approvalId, 'Approval ID should be present in response');

        // Verify approval was created in database
        $this->assertDatabaseHas('minor_spend_approvals', [
            'id'                 => $approvalId,
            'minor_account_uuid' => $this->minorAccount->uuid,
            'amount'             => '150.00',
            'status'             => 'pending',
        ]);

        // === SCENARIO 3: Guardian lists pending approvals ===
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $listResponse = $this->getJson(
            '/api/minor-accounts/' . $this->minorAccount->uuid . '/approvals'
        );

        $listResponse->assertOk();
        $approvals = $listResponse->json('data');
        $this->assertCount(1, $approvals);
        $this->assertEquals($approvalId, $approvals[0]['id']);
        $this->assertEquals('pending', $approvals[0]['status']);

        // === SCENARIO 4: Guardian declines approval ===
        $declineResponse = $this->postJson(
            '/api/minor-accounts/approvals/' . $approvalId . '/decline'
        );

        $declineResponse->assertOk();
        $this->assertDatabaseHas('minor_spend_approvals', [
            'id'     => $approvalId,
            'status' => 'declined',
        ]);

        // Verify transfer was not executed (child balance unchanged)
        $this->assertDatabaseHas('accounts', [
            'uuid'    => $this->minorAccount->uuid,
            'balance' => 1000, // Balance should remain unchanged
        ]);

        // === SCENARIO 5: Run expiry command (idempotent) ===
        // Create another approval that will expire
        $expiringApproval = MinorSpendApproval::create([
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'guardian_account_uuid' => $this->parentAccount->uuid,
            'from_account_uuid'     => $this->minorAccount->uuid,
            'to_account_uuid'       => $this->recipientAccount->uuid,
            'amount'                => '200.00',
            'asset_code'            => 'SZL',
            'merchant_category'     => 'general',
            'status'                => 'pending',
            'expires_at'            => now()->subHour(), // Already expired
        ]);

        // Run the expiry command
        $exitCode = $this->artisan('minor:expire-approvals');
        $this->assertEquals(0, $exitCode);

        // Verify expired approval was marked as expired
        $this->assertDatabaseHas('minor_spend_approvals', [
            'id'     => $expiringApproval->id,
            'status' => 'expired',
        ]);

        // Run command again (idempotent check)
        $secondExitCode = $this->artisan('minor:expire-approvals');
        $this->assertEquals(0, $secondExitCode);

        // Verify status hasn't changed again
        $this->assertDatabaseHas('minor_spend_approvals', [
            'id'     => $expiringApproval->id,
            'status' => 'expired',
        ]);

        // === SCENARIO 6: Guardian sets emergency allowance ===
        $emergencyResponse = $this->putJson(
            '/api/accounts/minor/' . $this->minorAccount->uuid . '/emergency-allowance',
            ['amount' => 200]
        );

        $emergencyResponse->assertOk();
        $this->assertDatabaseHas('accounts', [
            'uuid'                        => $this->minorAccount->uuid,
            'emergency_allowance_amount'  => 200,
            'emergency_allowance_balance' => 200,
        ]);

        // Verify non-guardian cannot set emergency allowance
        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser, ['read', 'write', 'delete']);

        $forbiddenResponse = $this->putJson(
            '/api/accounts/minor/' . $this->minorAccount->uuid . '/emergency-allowance',
            ['amount' => 300]
        );

        $forbiddenResponse->assertForbidden();

        // Final verification: emergency allowance balance unchanged
        $this->assertDatabaseHas('accounts', [
            'uuid'                        => $this->minorAccount->uuid,
            'emergency_allowance_balance' => 200,
        ]);
    }

    /**
     * Verify that parent and co-guardian can both list and manage approvals.
     */
    #[Test]
    public function both_primary_and_co_guardian_can_manage_approvals(): void
    {
        // Child makes a spend that requires approval
        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'X-Device-ID' => 'test-device-' . $this->child->id,
        ])->postJson(
            '/api/send-money/store',
            [
                'user'   => $this->recipient->mobile ?? $this->recipient->email,
                'amount' => 150.00,
            ]
        );

        $response->assertStatus(202);
        $approvalId = $response->json('data.approval_id');

        // Primary guardian can list approvals
        Sanctum::actingAs($this->parent, ['read', 'write', 'delete']);
        $parentList = $this->getJson('/api/minor-accounts/' . $this->minorAccount->uuid . '/approvals');
        $parentList->assertOk();
        $this->assertCount(1, $parentList->json('data'));

        // Co-guardian can also list approvals
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);
        $coGuardianList = $this->getJson('/api/minor-accounts/' . $this->minorAccount->uuid . '/approvals');
        $coGuardianList->assertOk();
        $this->assertCount(1, $coGuardianList->json('data'));

        // Co-guardian can decline
        $declineResponse = $this->postJson(
            '/api/minor-accounts/approvals/' . $approvalId . '/decline'
        );
        $declineResponse->assertOk();

        // Parent cannot act on already-declined approval
        Sanctum::actingAs($this->parent, ['read', 'write', 'delete']);
        $secondDeclineAttempt = $this->postJson(
            '/api/minor-accounts/approvals/' . $approvalId . '/decline'
        );
        $secondDeclineAttempt->assertStatus(422);
    }

    /**
     * Verify that child cannot access the approval management endpoints.
     */
    #[Test]
    public function child_cannot_access_approval_management(): void
    {
        Sanctum::actingAs($this->child, ['read', 'write', 'delete']);

        $listResponse = $this->getJson('/api/minor-accounts/' . $this->minorAccount->uuid . '/approvals');
        $listResponse->assertForbidden();

        // Create an approval
        $approval = MinorSpendApproval::create([
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'guardian_account_uuid' => $this->parentAccount->uuid,
            'from_account_uuid'     => $this->minorAccount->uuid,
            'to_account_uuid'       => $this->recipientAccount->uuid,
            'amount'                => '150.00',
            'asset_code'            => 'SZL',
            'merchant_category'     => 'general',
            'status'                => 'pending',
            'expires_at'            => now()->addHours(24),
        ]);

        // Child cannot decline
        $declineResponse = $this->postJson('/api/minor-accounts/approvals/' . $approval->id . '/decline');
        $declineResponse->assertForbidden();
    }
}
