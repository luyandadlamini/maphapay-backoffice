<?php
declare(strict_types=1);
namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorSpendApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorSpendApprovalControllerTest extends TestCase
{
    protected function connectionsToTransact(): array { return ['mysql', 'central']; }
    protected function shouldCreateDefaultAccountsInSetup(): bool { return false; }

    private User $guardian;
    private Account $minorAccount;
    private Account $guardianAccount;
    private MinorSpendApproval $approval;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardian = User::factory()->create();
        $child = User::factory()->create();
        $this->tenantId = (string) Str::uuid();

        DB::connection('central')->table('tenants')->insert([
            'id' => $this->tenantId, 'name' => 'T', 'plan' => 'default',
            'team_id' => null, 'trial_ends_at' => null,
            'created_at' => now(), 'updated_at' => now(), 'data' => json_encode([]),
        ]);

        $this->guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardian->uuid, 'type' => 'personal',
        ]);
        AccountMembership::create([
            'user_uuid' => $this->guardian->uuid, 'account_uuid' => $this->guardianAccount->uuid,
            'tenant_id' => $this->tenantId, 'account_type' => 'personal', 'role' => 'owner', 'status' => 'active',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid, 'type' => 'minor', 'tier' => 'grow',
            'permission_level' => 3, 'parent_account_id' => $this->guardianAccount->uuid,
        ]);
        AccountMembership::create([
            'user_uuid' => $this->guardian->uuid, 'account_uuid' => $this->minorAccount->uuid,
            'tenant_id' => $this->tenantId, 'account_type' => 'minor', 'role' => 'guardian', 'status' => 'active',
        ]);

        $recipientAccount = Account::factory()->create(['type' => 'personal']);

        $this->approval = MinorSpendApproval::create([
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'from_account_uuid'     => $this->minorAccount->uuid,
            'to_account_uuid'       => $recipientAccount->uuid,
            'amount'                => '150.00',
            'asset_code'            => 'SZL',
            'merchant_category'     => 'general',
            'status'                => 'pending',
            'expires_at'            => now()->addHours(24),
        ]);
    }

    #[Test]
    public function guardian_can_list_pending_approvals(): void
    {
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/minor-accounts/' . $this->minorAccount->uuid . '/approvals');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'amount', 'status', 'expires_at']]]);
        $this->assertEquals($this->approval->id, $response->json('data.0.id'));
    }

    #[Test]
    public function non_guardian_cannot_list_approvals(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/minor-accounts/' . $this->minorAccount->uuid . '/approvals');

        $response->assertForbidden();
    }

    #[Test]
    public function guardian_can_decline_an_approval(): void
    {
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/minor-accounts/approvals/' . $this->approval->id . '/decline');

        $response->assertOk()->assertJsonPath('data.status', 'declined');
        $this->assertDatabaseHas('minor_spend_approvals', [
            'id' => $this->approval->id, 'status' => 'declined',
        ]);
    }

    #[Test]
    public function non_guardian_cannot_decline_an_approval(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/minor-accounts/approvals/' . $this->approval->id . '/decline');

        $response->assertForbidden();
        $this->assertDatabaseHas('minor_spend_approvals', ['id' => $this->approval->id, 'status' => 'pending']);
    }

    #[Test]
    public function expired_approval_cannot_be_actioned(): void
    {
        $this->approval->forceFill(['expires_at' => now()->subHour()])->save();

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/minor-accounts/approvals/' . $this->approval->id . '/decline');

        $response->assertStatus(422);
        $this->assertStringContainsString('expired', strtolower($response->json('message') ?? ''));
    }

    #[Test]
    public function already_decided_approval_cannot_be_actioned_again(): void
    {
        $this->approval->forceFill(['status' => 'declined', 'decided_at' => now()])->save();

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/minor-accounts/approvals/' . $this->approval->id . '/decline');

        $response->assertStatus(422);
    }
}
