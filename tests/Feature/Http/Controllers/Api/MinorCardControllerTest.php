<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorCardRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesApplication;

class MinorCardControllerTest extends BaseTestCase
{
    use CreatesApplication;

    private User $guardian;

    private User $child;

    private Account $guardianAccount;

    private Account $minorAccount;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->guardian = User::factory()->create();
        $this->child = User::factory()->create();
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

        $this->guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardian->uuid,
            'type'      => 'personal',
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->guardian->uuid,
            'account_uuid' => $this->guardianAccount->uuid,
            'tenant_id'    => $this->tenantId,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'         => $this->child->uuid,
            'type'              => 'minor',
            'tier'              => 'rise',
            'permission_level'  => 3,
            'parent_account_id' => $this->guardianAccount->uuid,
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

    #[Test]
    public function guardian_can_create_card_request(): void
    {
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/v1/minor-cards/requests', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'network'            => 'visa',
            'requested_limits'   => [
                'daily' => '1500.00',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', MinorCardConstants::STATUS_PENDING_APPROVAL)
            ->assertJsonPath('minor_account_uuid', $this->minorAccount->uuid);

        MinorCardRequest::query()->delete();
    }

    #[Test]
    public function non_guardian_cannot_create_request(): void
    {
        $nonGuardian = User::factory()->create();
        Sanctum::actingAs($nonGuardian, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/v1/minor-cards/requests', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'network'            => 'visa',
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function guardian_can_approve_request(): void
    {
        $request = MinorCardRequest::create([
            'minor_account_uuid'     => $this->minorAccount->uuid,
            'requested_by_user_uuid' => $this->child->uuid,
            'request_type'           => MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED,
            'status'                 => MinorCardConstants::STATUS_PENDING_APPROVAL,
            'requested_network'      => 'visa',
            'expires_at'             => now()->addHours(72),
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/v1/minor-cards/requests/{$request->uuid}/approve");

        $response->assertStatus(200);

        MinorCardRequest::query()->delete();
    }

    #[Test]
    public function non_guardian_cannot_approve_request(): void
    {
        $nonGuardian = User::factory()->create();

        $request = MinorCardRequest::create([
            'minor_account_uuid'     => $this->minorAccount->uuid,
            'requested_by_user_uuid' => $this->child->uuid,
            'request_type'           => MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED,
            'status'                 => MinorCardConstants::STATUS_PENDING_APPROVAL,
            'requested_network'      => 'visa',
            'expires_at'             => now()->addHours(72),
        ]);

        Sanctum::actingAs($nonGuardian, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/v1/minor-cards/requests/{$request->uuid}/approve");

        $response->assertStatus(403);

        MinorCardRequest::query()->delete();
    }

    #[Test]
    public function guardian_can_deny_request(): void
    {
        $request = MinorCardRequest::create([
            'minor_account_uuid'     => $this->minorAccount->uuid,
            'requested_by_user_uuid' => $this->child->uuid,
            'request_type'           => MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED,
            'status'                 => MinorCardConstants::STATUS_PENDING_APPROVAL,
            'requested_network'      => 'visa',
            'expires_at'             => now()->addHours(72),
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/v1/minor-cards/requests/{$request->uuid}/deny", [
            'reason' => 'Missing required documents',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', MinorCardConstants::STATUS_DENIED);

        MinorCardRequest::query()->delete();
    }

    #[Test]
    public function non_guardian_cannot_deny_request(): void
    {
        $nonGuardian = User::factory()->create();

        $request = MinorCardRequest::create([
            'minor_account_uuid'     => $this->minorAccount->uuid,
            'requested_by_user_uuid' => $this->child->uuid,
            'request_type'           => MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED,
            'status'                 => MinorCardConstants::STATUS_PENDING_APPROVAL,
            'requested_network'      => 'visa',
            'expires_at'             => now()->addHours(72),
        ]);

        Sanctum::actingAs($nonGuardian, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/v1/minor-cards/requests/{$request->uuid}/deny", [
            'reason' => 'Invalid request',
        ]);

        $response->assertStatus(403);

        MinorCardRequest::query()->delete();
    }

    #[Test]
    public function can_list_requests(): void
    {
        MinorCardRequest::create([
            'minor_account_uuid'     => $this->minorAccount->uuid,
            'requested_by_user_uuid' => $this->child->uuid,
            'request_type'           => MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED,
            'status'                 => MinorCardConstants::STATUS_PENDING_APPROVAL,
            'requested_network'      => 'visa',
            'expires_at'             => now()->addHours(72),
        ]);

        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/v1/minor-cards/requests?minor_account_uuid=' . $this->minorAccount->uuid);

        $response->assertStatus(200)
            ->assertJsonStructure(['requests']);

        MinorCardRequest::query()->delete();
    }
}
