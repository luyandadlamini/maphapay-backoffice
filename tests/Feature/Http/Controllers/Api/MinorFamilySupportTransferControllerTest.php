<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Domain\Account\Services\MinorFamilyIntegrationService;
use App\Domain\Shared\OperationRecord\Exceptions\OperationInProgressException;
use App\Domain\Shared\OperationRecord\Exceptions\OperationPayloadMismatchException;
use App\Http\Middleware\ResolveAccountContext;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorFamilySupportTransferControllerTest extends TestCase
{
    private string $tenantId;

    private User $guardianUser;

    private User $coGuardianUser;

    private User $childUser;

    private User $outsiderUser;

    private Account $guardianAccount;

    private Account $coGuardianAccount;

    private Account $outsiderAccount;

    private Account $minorAccount;

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

        $this->withoutMiddleware(ResolveAccountContext::class);

        $this->ensurePhase9Schema();
        DB::table('minor_family_support_transfers')->delete();

        $this->tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id'            => $this->tenantId,
            'name'          => 'Minor Family Support Transfer Test Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        $this->guardianUser = User::factory()->create();
        $this->coGuardianUser = User::factory()->create();
        $this->childUser = User::factory()->create();
        $this->outsiderUser = User::factory()->create();

        $this->guardianAccount = $this->createOwnedPersonalAccount($this->guardianUser);
        $this->coGuardianAccount = $this->createOwnedPersonalAccount($this->coGuardianUser);
        $this->outsiderAccount = $this->createOwnedPersonalAccount($this->outsiderUser);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'         => $this->childUser->uuid,
            'type'              => 'minor',
            'permission_level'  => 3,
            'parent_account_id' => $this->guardianAccount->uuid,
        ]);

        $this->createMinorMembership($this->guardianUser, $this->minorAccount, 'guardian');
        $this->createMinorMembership($this->coGuardianUser, $this->minorAccount, 'co_guardian');
    }

    #[Test]
    public function guardian_can_list_family_support_transfers(): void
    {
        $transfer = $this->createTransfer();

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/family-support-transfers")
            ->assertOk()
            ->assertJsonPath('data.0.family_support_transfer_uuid', $transfer->id)
            ->assertJsonPath('data.0.provider', 'mtn_momo')
            ->assertJsonPath('data.0.recipient_name', 'Gogo Dlamini')
            ->assertJsonPath('data.0.recipient_msisdn_masked', '26876****56');
    }

    #[Test]
    public function co_guardian_can_list_family_support_transfers(): void
    {
        $transfer = $this->createTransfer();

        Sanctum::actingAs($this->coGuardianUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/family-support-transfers")
            ->assertOk()
            ->assertJsonPath('data.0.family_support_transfer_uuid', $transfer->id);
    }

    #[Test]
    public function child_can_list_family_support_transfers(): void
    {
        $transfer = $this->createTransfer();

        Sanctum::actingAs($this->childUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/family-support-transfers")
            ->assertOk()
            ->assertJsonPath('data.0.family_support_transfer_uuid', $transfer->id);
    }

    #[Test]
    public function guardian_can_create_a_family_support_transfer(): void
    {
        $transferId = (string) Str::uuid();

        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldReceive('createOutboundSupportTransfer')
            ->once()
            ->andReturn(new MinorFamilySupportTransfer([
                'id'                    => $transferId,
                'tenant_id'             => $this->tenantId,
                'minor_account_uuid'    => $this->minorAccount->uuid,
                'actor_user_uuid'       => $this->guardianUser->uuid,
                'source_account_uuid'   => $this->guardianAccount->uuid,
                'status'                => MinorFamilySupportTransfer::STATUS_PENDING_PROVIDER,
                'provider_name'         => 'mtn_momo',
                'recipient_name'        => 'Gogo Dlamini',
                'recipient_msisdn'      => '26876123456',
                'amount'                => '250.00',
                'asset_code'            => 'SZL',
                'note'                  => 'School support',
                'provider_reference_id' => 'provider-transfer-guardian',
                'idempotency_key'       => 'idem-transfer-guardian',
                'created_at'            => now(),
                'updated_at'            => now(),
            ]));
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->withHeaders([
            'Idempotency-Key' => 'idem-transfer-guardian',
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/family-support-transfers", [
            'source_account_uuid' => $this->guardianAccount->uuid,
            'provider'            => 'mtn_momo',
            'recipient_name'      => 'Gogo Dlamini',
            'recipient_msisdn'    => '26876123456',
            'amount'              => '250.00',
            'asset_code'          => 'SZL',
            'note'                => 'School support',
        ])->assertStatus(202)
            ->assertJsonPath('data.family_support_transfer_uuid', $transferId)
            ->assertJsonPath('data.minor_account_uuid', $this->minorAccount->uuid)
            ->assertJsonPath('data.status', MinorFamilySupportTransfer::STATUS_PENDING_PROVIDER)
            ->assertJsonPath('data.provider', 'mtn_momo')
            ->assertJsonPath('data.provider_reference_id', 'provider-transfer-guardian')
            ->assertJsonPath('data.amount', '250.00')
            ->assertJsonPath('data.asset_code', 'SZL');
    }

    #[Test]
    public function co_guardian_can_create_a_family_support_transfer(): void
    {
        $transferId = (string) Str::uuid();

        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldReceive('createOutboundSupportTransfer')
            ->once()
            ->withArgs(function (User $actor, Account $minorAccount, array $attributes) {
                return $actor->is($this->coGuardianUser)
                    && $minorAccount->is($this->minorAccount)
                    && $attributes['source_account_uuid'] === $this->coGuardianAccount->uuid
                    && $attributes['idempotency_key'] === 'idem-transfer-123'
                    && $attributes['recipient_msisdn'] === '+26876123456';
            })
            ->andReturn(new MinorFamilySupportTransfer([
                'id'                    => $transferId,
                'tenant_id'             => $this->tenantId,
                'minor_account_uuid'    => $this->minorAccount->uuid,
                'actor_user_uuid'       => $this->coGuardianUser->uuid,
                'source_account_uuid'   => $this->coGuardianAccount->uuid,
                'status'                => MinorFamilySupportTransfer::STATUS_PENDING_PROVIDER,
                'provider_name'         => 'mtn_momo',
                'recipient_name'        => 'Gogo Dlamini',
                'recipient_msisdn'      => '+26876123456',
                'amount'                => '250.00',
                'asset_code'            => 'SZL',
                'note'                  => 'School support',
                'provider_reference_id' => 'provider-transfer-001',
                'idempotency_key'       => 'idem-transfer-123',
                'created_at'            => now(),
                'updated_at'            => now(),
            ]));
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->coGuardianUser, ['read', 'write', 'delete']);

        $this->withHeaders([
            'Idempotency-Key' => 'idem-transfer-123',
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/family-support-transfers", [
            'source_account_uuid' => $this->coGuardianAccount->uuid,
            'provider'            => 'mtn_momo',
            'recipient_name'      => 'Gogo Dlamini',
            'recipient_msisdn'    => '+26876123456',
            'amount'              => '250.00',
            'asset_code'          => 'SZL',
            'note'                => 'School support',
        ])->assertStatus(202)
            ->assertJsonPath('data.family_support_transfer_uuid', $transferId)
            ->assertJsonPath('data.minor_account_uuid', $this->minorAccount->uuid)
            ->assertJsonPath('data.status', MinorFamilySupportTransfer::STATUS_PENDING_PROVIDER)
            ->assertJsonPath('data.provider', 'mtn_momo')
            ->assertJsonPath('data.provider_reference_id', 'provider-transfer-001')
            ->assertJsonPath('data.amount', '250.00')
            ->assertJsonPath('data.asset_code', 'SZL');
    }

    #[Test]
    public function guardian_reusing_idempotency_key_with_different_payload_returns_conflict_with_stable_error_code_and_message(): void
    {
        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldReceive('createOutboundSupportTransfer')
            ->once()
            ->andThrow(new OperationPayloadMismatchException('Idempotency key reused with a different request payload.'));
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->withHeaders([
            'Idempotency-Key' => 'idem-transfer-mismatch-' . Str::uuid(),
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/family-support-transfers", [
            'source_account_uuid' => $this->guardianAccount->uuid,
            'provider'            => 'mtn_momo',
            'recipient_name'      => 'Gogo Dlamini',
            'recipient_msisdn'    => '+26876123456',
            'amount'              => '250.00',
            'asset_code'          => 'SZL',
            'note'                => 'School support',
        ])->assertStatus(409)
            ->assertJsonPath('error', 'Idempotency key already used')
            ->assertJsonPath('message', 'The provided idempotency key has already been used with different request parameters')
            ->assertJsonPath('error_code', 'idempotency_key_payload_mismatch');
    }

    #[Test]
    public function guardian_reusing_idempotency_key_while_operation_in_progress_returns_conflict_with_stable_error_code_and_message(): void
    {
        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldReceive('createOutboundSupportTransfer')
            ->once()
            ->andThrow(new OperationInProgressException());
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->withHeaders([
            'Idempotency-Key' => 'idem-transfer-pending-' . Str::uuid(),
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/family-support-transfers", [
            'source_account_uuid' => $this->guardianAccount->uuid,
            'provider'            => 'mtn_momo',
            'recipient_name'      => 'Gogo Dlamini',
            'recipient_msisdn'    => '+26876123456',
            'amount'              => '250.00',
            'asset_code'          => 'SZL',
            'note'                => 'School support',
        ])->assertStatus(409)
            ->assertJsonPath('error', 'Idempotency operation in progress')
            ->assertJsonPath('message', 'An identical operation with this idempotency key is still in progress. Please retry shortly.')
            ->assertJsonPath('error_code', 'idempotency_operation_in_progress');
    }

    #[Test]
    public function transfer_creation_requires_the_source_account_to_be_owned_by_the_actor(): void
    {
        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldNotReceive('createOutboundSupportTransfer');
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->withHeaders([
            'Idempotency-Key' => 'idem-transfer-unauthorized-source',
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/family-support-transfers", [
            'source_account_uuid' => $this->outsiderAccount->uuid,
            'provider'            => 'mtn_momo',
            'recipient_name'      => 'Gogo Dlamini',
            'recipient_msisdn'    => '+26876123456',
            'amount'              => '250.00',
            'asset_code'          => 'SZL',
        ])->assertForbidden();
    }

    #[Test]
    public function child_cannot_create_a_family_support_transfer(): void
    {
        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldNotReceive('createOutboundSupportTransfer');
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->childUser, ['read', 'write', 'delete']);

        $this->withHeaders([
            'Idempotency-Key' => 'idem-transfer-child-blocked',
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/family-support-transfers", [
            'source_account_uuid' => $this->guardianAccount->uuid,
            'provider'            => 'mtn_momo',
            'recipient_name'      => 'Gogo Dlamini',
            'recipient_msisdn'    => '+26876123456',
            'amount'              => '250.00',
            'asset_code'          => 'SZL',
        ])->assertForbidden();
    }

    #[Test]
    public function non_guardian_cannot_create_a_family_support_transfer(): void
    {
        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldNotReceive('createOutboundSupportTransfer');
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->outsiderUser, ['read', 'write', 'delete']);

        $this->withHeaders([
            'Idempotency-Key' => 'idem-transfer-outsider-blocked',
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/family-support-transfers", [
            'source_account_uuid' => $this->outsiderAccount->uuid,
            'provider'            => 'mtn_momo',
            'recipient_name'      => 'Gogo Dlamini',
            'recipient_msisdn'    => '+26876123456',
            'amount'              => '250.00',
            'asset_code'          => 'SZL',
        ])->assertForbidden();
    }

    #[Test]
    public function transfer_creation_requires_an_idempotency_key_header(): void
    {
        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldNotReceive('createOutboundSupportTransfer');
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/family-support-transfers", [
            'source_account_uuid' => $this->guardianAccount->uuid,
            'provider'            => 'mtn_momo',
            'recipient_name'      => 'Gogo Dlamini',
            'recipient_msisdn'    => '+26876123456',
            'amount'              => '250.00',
            'asset_code'          => 'SZL',
        ])->assertStatus(422)
            ->assertJsonPath('message.0', 'Idempotency-Key header is required for family support transfer requests.');
    }

    private function createOwnedPersonalAccount(User $user): Account
    {
        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'type'      => 'personal',
        ]);

        AccountMembership::query()->create([
            'id'           => (string) Str::uuid(),
            'user_uuid'    => $user->uuid,
            'tenant_id'    => $this->tenantId,
            'account_uuid' => $account->uuid,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
            'joined_at'    => now(),
        ]);

        return $account;
    }

    private function createMinorMembership(User $user, Account $minorAccount, string $role): void
    {
        AccountMembership::query()->create([
            'id'           => (string) Str::uuid(),
            'user_uuid'    => $user->uuid,
            'tenant_id'    => $this->tenantId,
            'account_uuid' => $minorAccount->uuid,
            'account_type' => 'minor',
            'role'         => $role,
            'status'       => 'active',
            'joined_at'    => now(),
        ]);
    }

    private function ensurePhase9Schema(): void
    {
        if (! Schema::hasTable('minor_family_support_transfers')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_04_23_100200_create_minor_family_support_transfers_table.php',
                '--force' => true,
            ]);
        }
    }

    private function createTransfer(): MinorFamilySupportTransfer
    {
        return MinorFamilySupportTransfer::query()->create([
            'tenant_id'             => $this->tenantId,
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'actor_user_uuid'       => $this->guardianUser->uuid,
            'source_account_uuid'   => $this->guardianAccount->uuid,
            'status'                => MinorFamilySupportTransfer::STATUS_SUCCESSFUL,
            'provider_name'         => 'mtn_momo',
            'recipient_name'        => 'Gogo Dlamini',
            'recipient_msisdn'      => '26876123456',
            'amount'                => '250.00',
            'asset_code'            => 'SZL',
            'note'                  => 'School support',
            'provider_reference_id' => 'provider-transfer-001',
            'idempotency_key'       => 'idem-transfer-' . Str::uuid(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }
}
