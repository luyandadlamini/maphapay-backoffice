<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorFamilyFundingLink;
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

class MinorFamilyFundingLinkControllerTest extends TestCase
{
    private string $tenantId;

    private User $guardianUser;

    private User $coGuardianUser;

    private User $childUser;

    private Account $guardianAccount;

    private Account $coGuardianAccount;

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
        DB::table('minor_family_funding_links')->delete();

        $this->tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id'            => $this->tenantId,
            'name'          => 'Minor Family Funding Link Test Tenant',
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

        $this->guardianAccount = $this->createOwnedPersonalAccount($this->guardianUser);
        $this->coGuardianAccount = $this->createOwnedPersonalAccount($this->coGuardianUser);

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
    public function guardian_can_list_funding_links_for_the_minor_account(): void
    {
        MinorFamilyFundingLink::query()->create([
            'tenant_id'               => $this->tenantId,
            'minor_account_uuid'      => $this->minorAccount->uuid,
            'created_by_user_uuid'    => $this->guardianUser->uuid,
            'created_by_account_uuid' => $this->guardianAccount->uuid,
            'title'                   => 'School fund',
            'note'                    => 'For next term',
            'token'                   => (string) Str::uuid(),
            'status'                  => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode'             => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount'            => '150.00',
            'target_amount'           => null,
            'collected_amount'        => '0.00',
            'asset_code'              => 'SZL',
            'provider_options'        => ['mtn_momo'],
            'expires_at'              => now()->addDay(),
        ]);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links")
            ->assertOk()
            ->assertJsonPath('data.minor_account_uuid', $this->minorAccount->uuid)
            ->assertJsonPath('data.funding_links.0.title', 'School fund');
    }

    #[Test]
    public function co_guardian_can_list_funding_links_for_the_minor_account(): void
    {
        MinorFamilyFundingLink::query()->create([
            'tenant_id'               => $this->tenantId,
            'minor_account_uuid'      => $this->minorAccount->uuid,
            'created_by_user_uuid'    => $this->guardianUser->uuid,
            'created_by_account_uuid' => $this->guardianAccount->uuid,
            'title'                   => 'School fund',
            'note'                    => 'For next term',
            'token'                   => (string) Str::uuid(),
            'status'                  => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode'             => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount'            => '150.00',
            'target_amount'           => null,
            'collected_amount'        => '0.00',
            'asset_code'              => 'SZL',
            'provider_options'        => ['mtn_momo'],
            'expires_at'              => now()->addDay(),
        ]);

        Sanctum::actingAs($this->coGuardianUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links")
            ->assertOk()
            ->assertJsonPath('data.minor_account_uuid', $this->minorAccount->uuid)
            ->assertJsonPath('data.funding_links.0.title', 'School fund');
    }

    #[Test]
    public function guardian_can_create_a_funding_link_with_default_asset_code(): void
    {
        $linkId = (string) Str::uuid();
        $token = (string) Str::uuid();

        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldReceive('createFundingLink')
            ->once()
            ->withArgs(function (User $actor, Account $minorAccount, array $attributes) {
                return $actor->is($this->guardianUser)
                    && $minorAccount->is($this->minorAccount)
                    && $attributes['created_by_account_uuid'] === $this->guardianAccount->uuid
                    && ! array_key_exists('asset_code', $attributes);
            })
            ->andReturn(new MinorFamilyFundingLink([
                'id'                      => $linkId,
                'tenant_id'               => $this->tenantId,
                'minor_account_uuid'      => $this->minorAccount->uuid,
                'created_by_user_uuid'    => $this->guardianUser->uuid,
                'created_by_account_uuid' => $this->guardianAccount->uuid,
                'title'                   => 'Trip support',
                'note'                    => 'One-time support collection',
                'token'                   => $token,
                'status'                  => MinorFamilyFundingLink::STATUS_ACTIVE,
                'amount_mode'             => MinorFamilyFundingLink::AMOUNT_MODE_CAPPED,
                'fixed_amount'            => null,
                'target_amount'           => '1000.00',
                'collected_amount'        => '0.00',
                'asset_code'              => 'SZL',
                'provider_options'        => ['mtn_momo'],
                'expires_at'              => now()->addDay(),
                'created_at'              => now(),
                'updated_at'              => now(),
            ]));
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links", [
            'created_by_account_uuid' => $this->guardianAccount->uuid,
            'title'                   => 'Trip support',
            'note'                    => 'One-time support collection',
            'amount_mode'             => 'capped',
            'target_amount'           => '1000.00',
            'provider_options'        => ['mtn_momo'],
            'expires_at'              => now()->addDay()->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.funding_link_uuid', $linkId)
            ->assertJsonPath('data.minor_account_uuid', $this->minorAccount->uuid)
            ->assertJsonPath('data.status', MinorFamilyFundingLink::STATUS_ACTIVE)
            ->assertJsonPath('data.token', $token)
            ->assertJsonPath('data.public_url', "https://pay.maphapay.com/minor-support/{$token}")
            ->assertJsonPath('data.provider_options.0', 'mtn_momo')
            ->assertJsonPath('data.target_amount', '1000.00')
            ->assertJsonPath('data.fixed_amount', null)
            ->assertJsonPath('data.collected_amount', '0.00');
    }

    #[Test]
    public function co_guardian_can_create_a_funding_link(): void
    {
        $linkId = (string) Str::uuid();
        $token = (string) Str::uuid();

        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldReceive('createFundingLink')
            ->once()
            ->withArgs(function (User $actor, Account $minorAccount, array $attributes) {
                return $actor->is($this->coGuardianUser)
                    && $minorAccount->is($this->minorAccount)
                    && $attributes['created_by_account_uuid'] === $this->coGuardianAccount->uuid
                    && $attributes['title'] === 'Family support'
                    && $attributes['amount_mode'] === 'fixed'
                    && $attributes['fixed_amount'] === '100.00';
            })
            ->andReturn(new MinorFamilyFundingLink([
                'id'                      => $linkId,
                'tenant_id'               => $this->tenantId,
                'minor_account_uuid'      => $this->minorAccount->uuid,
                'created_by_user_uuid'    => $this->coGuardianUser->uuid,
                'created_by_account_uuid' => $this->coGuardianAccount->uuid,
                'title'                   => 'Family support',
                'note'                    => 'Birthday support',
                'token'                   => $token,
                'status'                  => MinorFamilyFundingLink::STATUS_ACTIVE,
                'amount_mode'             => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
                'fixed_amount'            => '100.00',
                'target_amount'           => null,
                'collected_amount'        => '0.00',
                'asset_code'              => 'SZL',
                'provider_options'        => ['mtn_momo'],
                'expires_at'              => now()->addDay(),
                'created_at'              => now(),
                'updated_at'              => now(),
            ]));

        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->coGuardianUser, ['read', 'write', 'delete']);

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links", [
            'created_by_account_uuid' => $this->coGuardianAccount->uuid,
            'title'                   => 'Family support',
            'note'                    => 'Birthday support',
            'amount_mode'             => 'fixed',
            'fixed_amount'            => '100.00',
            'asset_code'              => 'SZL',
            'provider_options'        => ['mtn_momo'],
            'expires_at'              => now()->addDay()->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.funding_link_uuid', $linkId)
            ->assertJsonPath('data.minor_account_uuid', $this->minorAccount->uuid)
            ->assertJsonPath('data.status', MinorFamilyFundingLink::STATUS_ACTIVE)
            ->assertJsonPath('data.token', $token)
            ->assertJsonPath('data.public_url', "https://pay.maphapay.com/minor-support/{$token}")
            ->assertJsonPath('data.provider_options.0', 'mtn_momo')
            ->assertJsonPath('data.fixed_amount', '100.00')
            ->assertJsonPath('data.target_amount', null)
            ->assertJsonPath('data.collected_amount', '0.00');
    }

    #[Test]
    public function guardian_can_replay_funding_link_creation_with_same_idempotency_key_and_payload(): void
    {
        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $payload = [
            'created_by_account_uuid' => $this->guardianAccount->uuid,
            'title'                   => 'School fundraiser',
            'note'                    => 'Class contribution',
            'amount_mode'             => 'fixed',
            'fixed_amount'            => '100.00',
            'asset_code'              => 'SZL',
            'provider_options'        => ['mtn_momo'],
            'expires_at'              => now()->addDay()->toIso8601String(),
        ];

        $idempotencyKey = 'idem-funding-link-replay-' . Str::uuid();

        $firstResponse = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links", $payload)
            ->assertCreated();

        $firstFundingLinkUuid = $firstResponse->json('data.funding_link_uuid');
        $firstToken = $firstResponse->json('data.token');

        $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links", $payload)
            ->assertCreated()
            ->assertJsonPath('data.funding_link_uuid', $firstFundingLinkUuid)
            ->assertJsonPath('data.token', $firstToken);

        $this->assertSame(1, MinorFamilyFundingLink::query()
            ->where('token', $firstToken)
            ->count());
        $this->assertSame(1, MinorFamilyFundingLink::query()
            ->where('id', $firstFundingLinkUuid)
            ->count());
    }

    #[Test]
    public function guardian_reusing_idempotency_key_with_different_payload_returns_conflict(): void
    {
        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $idempotencyKey = 'idem-funding-link-mismatch-' . Str::uuid();

        $firstPayload = [
            'created_by_account_uuid' => $this->guardianAccount->uuid,
            'title'                   => 'School fundraiser',
            'note'                    => 'Class contribution',
            'amount_mode'             => 'fixed',
            'fixed_amount'            => '100.00',
            'asset_code'              => 'SZL',
            'provider_options'        => ['mtn_momo'],
            'expires_at'              => now()->addDay()->toIso8601String(),
        ];

        $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links", $firstPayload)
            ->assertCreated();

        $mismatchedPayload = $firstPayload;
        $mismatchedPayload['title'] = 'Different fundraiser title';

        $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links", $mismatchedPayload)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'idempotency_key_payload_mismatch');
    }

    #[Test]
    public function guardian_reusing_idempotency_key_while_operation_in_progress_returns_conflict_with_stable_error_code(): void
    {
        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldReceive('createFundingLink')
            ->once()
            ->andThrow(new OperationInProgressException());
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->withHeaders([
            'Idempotency-Key' => 'idem-funding-link-pending-' . Str::uuid(),
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links", [
            'created_by_account_uuid' => $this->guardianAccount->uuid,
            'title'                   => 'School fundraiser',
            'note'                    => 'Class contribution',
            'amount_mode'             => 'fixed',
            'fixed_amount'            => '100.00',
            'asset_code'              => 'SZL',
            'provider_options'        => ['mtn_momo'],
            'expires_at'              => now()->addDay()->toIso8601String(),
        ])->assertStatus(409)
            ->assertJsonPath('error', 'Idempotency operation in progress')
            ->assertJsonPath('error_code', 'idempotency_operation_in_progress');
    }

    #[Test]
    public function guardian_reusing_idempotency_key_with_different_payload_returns_conflict_with_stable_error_code_when_service_throws_payload_mismatch(): void
    {
        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldReceive('createFundingLink')
            ->once()
            ->andThrow(new OperationPayloadMismatchException('Idempotency key reused with a different request payload.'));
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->withHeaders([
            'Idempotency-Key' => 'idem-funding-link-mismatch-' . Str::uuid(),
        ])->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links", [
            'created_by_account_uuid' => $this->guardianAccount->uuid,
            'title'                   => 'School fundraiser',
            'note'                    => 'Class contribution',
            'amount_mode'             => 'fixed',
            'fixed_amount'            => '100.00',
            'asset_code'              => 'SZL',
            'provider_options'        => ['mtn_momo'],
            'expires_at'              => now()->addDay()->toIso8601String(),
        ])->assertStatus(409)
            ->assertJsonPath('error', 'Idempotency key already used')
            ->assertJsonPath('error_code', 'idempotency_key_payload_mismatch');
    }

    #[Test]
    public function child_cannot_create_a_funding_link(): void
    {
        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldNotReceive('createFundingLink');
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->childUser, ['read', 'write', 'delete']);

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links", [
            'created_by_account_uuid' => $this->minorAccount->uuid,
            'title'                   => 'Blocked link',
            'amount_mode'             => 'fixed',
            'fixed_amount'            => '50.00',
            'asset_code'              => 'SZL',
            'provider_options'        => ['mtn_momo'],
            'expires_at'              => now()->addDay()->toIso8601String(),
        ])->assertForbidden();
    }

    #[Test]
    public function guardian_can_expire_a_funding_link(): void
    {
        $link = MinorFamilyFundingLink::query()->create([
            'tenant_id'               => $this->tenantId,
            'minor_account_uuid'      => $this->minorAccount->uuid,
            'created_by_user_uuid'    => $this->guardianUser->uuid,
            'created_by_account_uuid' => $this->guardianAccount->uuid,
            'title'                   => 'School fund',
            'note'                    => 'For next term',
            'token'                   => (string) Str::uuid(),
            'status'                  => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode'             => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount'            => '150.00',
            'target_amount'           => null,
            'collected_amount'        => '0.00',
            'asset_code'              => 'SZL',
            'provider_options'        => ['mtn_momo'],
            'expires_at'              => now()->addDay(),
        ]);

        $expired = tap($link->replicate())->forceFill([
            'id'         => $link->id,
            'status'     => MinorFamilyFundingLink::STATUS_EXPIRED,
            'expires_at' => now(),
            'updated_at' => now(),
        ]);

        $service = Mockery::mock(MinorFamilyIntegrationService::class);
        $service->shouldReceive('expireFundingLink')
            ->once()
            ->withArgs(function (User $actor, Account $minorAccount, MinorFamilyFundingLink $fundingLink) use ($link) {
                return $actor->is($this->guardianUser)
                    && $minorAccount->is($this->minorAccount)
                    && $fundingLink->is($link);
            })
            ->andReturn($expired);
        $this->app->instance(MinorFamilyIntegrationService::class, $service);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links/{$link->id}/expire")
            ->assertOk()
            ->assertJsonPath('data.funding_link_uuid', $link->id)
            ->assertJsonPath('data.status', MinorFamilyFundingLink::STATUS_EXPIRED);
    }

    #[Test]
    public function expiring_a_funding_link_from_a_different_minor_returns_not_found(): void
    {
        $otherMinor = Account::factory()->create([
            'user_uuid' => User::factory()->create()->uuid,
            'type'      => 'minor',
        ]);

        $foreignLink = MinorFamilyFundingLink::query()->create([
            'tenant_id'               => $this->tenantId,
            'minor_account_uuid'      => $otherMinor->uuid,
            'created_by_user_uuid'    => $this->guardianUser->uuid,
            'created_by_account_uuid' => $this->guardianAccount->uuid,
            'title'                   => 'Foreign link',
            'note'                    => null,
            'token'                   => (string) Str::uuid(),
            'status'                  => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode'             => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount'            => '50.00',
            'target_amount'           => null,
            'collected_amount'        => '0.00',
            'asset_code'              => 'SZL',
            'provider_options'        => ['mtn_momo'],
            'expires_at'              => now()->addDay(),
        ]);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/funding-links/{$foreignLink->id}/expire")
            ->assertNotFound();
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
        if (! Schema::hasTable('minor_family_funding_links')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_04_23_100000_create_minor_family_funding_links_table.php',
                '--force' => true,
            ]);
        }
    }
}
