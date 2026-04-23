<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Services;

use App\Domain\Account\Events\MinorFamilyFundingAttemptInitiated;
use App\Domain\Account\Events\MinorFamilySupportTransferInitiated;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Domain\Account\Services\MinorFamilyFundingPolicy;
use App\Domain\Account\Services\MinorFamilyFundingPolicyResult;
use App\Domain\Account\Services\MinorFamilyIntegrationService;
use App\Domain\Account\Services\MinorNotificationService;
use App\Domain\MtnMomo\Services\MtnMomoFamilyFundingAdapter;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Domain\Shared\OperationRecord\OperationRecord;
use App\Domain\Shared\OperationRecord\OperationRecordService;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MinorFamilyIntegrationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMinorFamilyPhase9Schema();
        $this->resetPhase9aRows();

        Carbon::setTestNow(Carbon::parse('2026-04-23 10:15:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_outbound_support_transfer_creates_phase_9a_transfer_and_mtn_request_safely(): void
    {
        $actor = User::factory()->create();
        $minorOwner = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $minorOwner->uuid,
            'type' => 'minor',
        ]);

        $sourceAccount = Account::factory()->create([
            'user_uuid' => $actor->uuid,
            'type' => 'personal',
        ]);

        $access = Mockery::mock(MinorAccountAccessService::class);
        $access->shouldReceive('authorizeGuardian')
            ->once()
            ->with($actor, Mockery::on(fn (Account $account): bool => $account->is($minorAccount)), $sourceAccount->uuid)
            ->andReturn($sourceAccount);

        $policy = Mockery::mock(MinorFamilyFundingPolicy::class);
        $policy->shouldReceive('validateOutboundSupportTransfer')
            ->once()
            ->with(
                $actor,
                Mockery::on(fn (Account $account): bool => $account->is($minorAccount)),
                Mockery::on(fn (Account $account): bool => $account->is($sourceAccount)),
                'mtn_momo',
                '250.00',
            )
            ->andReturn(MinorFamilyFundingPolicyResult::allow());

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateOutboundDisbursement')
            ->once()
            ->with(Mockery::on(function (array $payload) use ($sourceAccount): bool {
                return $payload['idempotency_key'] === 'idem-transfer-1'
                    && $payload['recipient_msisdn'] === '+26876123456'
                    && $payload['amount'] === '250.00'
                    && $payload['asset_code'] === 'SZL'
                    && $payload['source_account_uuid'] === $sourceAccount->uuid;
            }))
            ->andReturn([
                'provider_name' => 'mtn_momo',
                'provider_reference_id' => 'provider-transfer-001',
                'provider_status' => 'pending',
                'transaction_type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
                'party_msisdn' => '+26876123456',
                'amount' => '250.00',
                'asset_code' => 'SZL',
                'note' => 'School support',
                'idempotency_key' => 'idem-transfer-1',
            ]);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')
            ->once()
            ->withArgs(function (
                string $minorAccountUuid,
                string $type,
                array $data,
                ?string $actorUserUuid,
                ?string $targetType,
                ?string $targetId,
            ) use ($minorAccount, $actor): bool {
                return $minorAccountUuid === $minorAccount->uuid
                    && $type === MinorNotificationService::TYPE_FAMILY_SUPPORT_TRANSFER_INITIATED
                    && $actorUserUuid === $actor->uuid
                    && $targetType === 'minor_family_support_transfer'
                    && is_string($targetId)
                    && ($data['provider_reference_id'] ?? null) === 'provider-transfer-001';
            });

        $service = $this->makeService($access, $policy, $adapter, $notifications);

        $beforeEvents = DB::table('stored_events')
            ->where('event_class', 'minor_family_support_transfer_initiated')
            ->count();

        $transfer = $service->createOutboundSupportTransfer($actor, $minorAccount, [
            'tenant_id' => 'tenant-test-1',
            'source_account_uuid' => $sourceAccount->uuid,
            'provider' => 'mtn_momo',
            'recipient_name' => 'Gogo Dlamini',
            'recipient_msisdn' => '+26876123456',
            'amount' => '250.00',
            'asset_code' => 'SZL',
            'note' => 'School support',
            'idempotency_key' => 'idem-transfer-1',
        ]);

        $this->assertSame(MinorFamilySupportTransfer::STATUS_PENDING_PROVIDER, $transfer->status);
        $this->assertSame('provider-transfer-001', $transfer->provider_reference_id);

        $this->assertDatabaseHas('minor_family_support_transfers', [
            'id' => $transfer->id,
            'tenant_id' => 'tenant-test-1',
            'minor_account_uuid' => $minorAccount->uuid,
            'actor_user_uuid' => $actor->uuid,
            'source_account_uuid' => $sourceAccount->uuid,
            'provider_name' => 'mtn_momo',
            'provider_reference_id' => 'provider-transfer-001',
            'idempotency_key' => 'idem-transfer-1',
            'status' => MinorFamilySupportTransfer::STATUS_PENDING_PROVIDER,
        ]);

        $this->assertDatabaseHas('mtn_momo_transactions', [
            'id' => $transfer->mtn_momo_transaction_id,
            'user_id' => $actor->id,
            'idempotency_key' => 'idem-transfer-1',
            'type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
            'status' => MtnMomoTransaction::STATUS_PENDING,
            'mtn_reference_id' => 'provider-transfer-001',
            'context_type' => MinorFamilySupportTransfer::class,
            'context_uuid' => $transfer->id,
        ]);

        $this->assertSame($beforeEvents + 1, DB::table('stored_events')
            ->where('event_class', 'minor_family_support_transfer_initiated')
            ->count());
    }

    public function test_public_funding_attempt_creates_phase_9a_attempt_and_mtn_collection_request_safely(): void
    {
        $creator = User::factory()->create();
        $minorOwner = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $minorOwner->uuid,
            'type' => 'minor',
        ]);

        $link = MinorFamilyFundingLink::query()->create([
            'tenant_id' => 'tenant-test-1',
            'minor_account_uuid' => $minorAccount->uuid,
            'created_by_user_uuid' => $creator->uuid,
            'created_by_account_uuid' => Account::factory()->create([
                'user_uuid' => $creator->uuid,
                'type' => 'personal',
            ])->uuid,
            'title' => 'Family support',
            'note' => 'Help Nomcebo',
            'token' => (string) Str::uuid(),
            'status' => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode' => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount' => '100.00',
            'target_amount' => null,
            'collected_amount' => '0.00',
            'asset_code' => 'SZL',
            'provider_options' => ['mtn_momo'],
            'expires_at' => now()->addDay(),
        ]);

        $access = Mockery::mock(MinorAccountAccessService::class);
        $policy = Mockery::mock(MinorFamilyFundingPolicy::class);
        $policy->shouldReceive('validateFundingAttempt')
            ->once()
            ->with(
                Mockery::on(fn (MinorFamilyFundingLink $model): bool => $model->is($link)),
                '100.00',
                'mtn_momo',
            )
            ->andReturn(MinorFamilyFundingPolicyResult::allow());

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateInboundCollection')
            ->once()
            ->with(Mockery::on(function (array $payload) use ($link): bool {
                return $payload['idempotency_key'] === hash('sha256', implode('|', [
                    $link->id,
                    '26876111111',
                    '100.00',
                    'mtn_momo',
                    '202604231015',
                ]))
                    && $payload['payer_msisdn'] === '+26876111111'
                    && $payload['amount'] === '100.00'
                    && $payload['asset_code'] === 'SZL';
            }))
            ->andReturn([
                'provider_name' => 'mtn_momo',
                'provider_reference_id' => 'provider-attempt-001',
                'provider_status' => 'pending',
                'transaction_type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
                'party_msisdn' => '+26876111111',
                'amount' => '100.00',
                'asset_code' => 'SZL',
                'note' => 'Help Nomcebo',
                'idempotency_key' => hash('sha256', implode('|', [
                    $link->id,
                    '26876111111',
                    '100.00',
                    'mtn_momo',
                    '202604231015',
                ])),
            ]);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')
            ->once()
            ->withArgs(function (
                string $minorAccountUuid,
                string $type,
                array $data,
                ?string $actorUserUuid,
                ?string $targetType,
                ?string $targetId,
            ) use ($minorAccount, $creator): bool {
                return $minorAccountUuid === $minorAccount->uuid
                    && $type === MinorNotificationService::TYPE_FAMILY_FUNDING_ATTEMPT_INITIATED
                    && $actorUserUuid === $creator->uuid
                    && $targetType === 'minor_family_funding_attempt'
                    && is_string($targetId)
                    && ($data['provider_reference_id'] ?? null) === 'provider-attempt-001';
            });

        $service = $this->makeService($access, $policy, $adapter, $notifications);

        $beforeEvents = DB::table('stored_events')
            ->where('event_class', 'minor_family_funding_attempt_initiated')
            ->count();

        $attempt = $service->createPublicFundingAttempt($link, [
            'sponsor_name' => 'MaDlamini',
            'sponsor_msisdn' => '+26876111111',
            'amount' => '100.00',
            'asset_code' => 'SZL',
            'provider' => 'mtn_momo',
        ]);

        $expectedDedupe = hash('sha256', implode('|', [
            $link->id,
            '26876111111',
            '100.00',
            'mtn_momo',
            '202604231015',
        ]));

        $this->assertSame(MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER, $attempt->status);
        $this->assertSame('provider-attempt-001', $attempt->provider_reference_id);
        $this->assertSame($expectedDedupe, $attempt->dedupe_hash);

        $this->assertDatabaseHas('minor_family_funding_attempts', [
            'id' => $attempt->id,
            'tenant_id' => 'tenant-test-1',
            'funding_link_uuid' => $link->id,
            'minor_account_uuid' => $minorAccount->uuid,
            'provider_name' => 'mtn_momo',
            'provider_reference_id' => 'provider-attempt-001',
            'dedupe_hash' => $expectedDedupe,
            'status' => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
        ]);

        $this->assertDatabaseHas('mtn_momo_transactions', [
            'id' => $attempt->mtn_momo_transaction_id,
            'user_id' => $creator->id,
            'idempotency_key' => $expectedDedupe,
            'type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
            'status' => MtnMomoTransaction::STATUS_PENDING,
            'mtn_reference_id' => 'provider-attempt-001',
            'context_type' => MinorFamilyFundingAttempt::class,
            'context_uuid' => $attempt->id,
        ]);

        $this->assertSame($beforeEvents + 1, DB::table('stored_events')
            ->where('event_class', 'minor_family_funding_attempt_initiated')
            ->count());
    }

    public function test_duplicate_idempotent_replay_does_not_create_duplicate_transfer_or_attempt(): void
    {
        $actor = User::factory()->create();
        $minorOwner = User::factory()->create();
        $minorAccount = Account::factory()->create([
            'user_uuid' => $minorOwner->uuid,
            'type' => 'minor',
        ]);
        $sourceAccount = Account::factory()->create([
            'user_uuid' => $actor->uuid,
            'type' => 'personal',
        ]);

        $creator = User::factory()->create();
        $link = MinorFamilyFundingLink::query()->create([
            'tenant_id' => 'tenant-test-1',
            'minor_account_uuid' => $minorAccount->uuid,
            'created_by_user_uuid' => $creator->uuid,
            'created_by_account_uuid' => Account::factory()->create([
                'user_uuid' => $creator->uuid,
                'type' => 'personal',
            ])->uuid,
            'title' => 'Family support',
            'note' => null,
            'token' => (string) Str::uuid(),
            'status' => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode' => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount' => '80.00',
            'target_amount' => null,
            'collected_amount' => '0.00',
            'asset_code' => 'SZL',
            'provider_options' => ['mtn_momo'],
            'expires_at' => now()->addDay(),
        ]);

        $access = Mockery::mock(MinorAccountAccessService::class);
        $access->shouldReceive('authorizeGuardian')
            ->once()
            ->andReturn($sourceAccount);

        $policy = Mockery::mock(MinorFamilyFundingPolicy::class);
        $policy->shouldReceive('validateOutboundSupportTransfer')
            ->once()
            ->andReturn(MinorFamilyFundingPolicyResult::allow());
        $policy->shouldReceive('validateFundingAttempt')
            ->once()
            ->andReturn(MinorFamilyFundingPolicyResult::allow());

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateOutboundDisbursement')
            ->once()
            ->andReturn([
                'provider_name' => 'mtn_momo',
                'provider_reference_id' => 'provider-transfer-replay',
                'provider_status' => 'pending',
                'transaction_type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
                'party_msisdn' => '+26876122222',
                'amount' => '80.00',
                'asset_code' => 'SZL',
                'note' => null,
                'idempotency_key' => 'idem-transfer-replay',
            ]);
        $adapter->shouldReceive('initiateInboundCollection')
            ->once()
            ->andReturn([
                'provider_name' => 'mtn_momo',
                'provider_reference_id' => 'provider-attempt-replay',
                'provider_status' => 'pending',
                'transaction_type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
                'party_msisdn' => '+26876133333',
                'amount' => '80.00',
                'asset_code' => 'SZL',
                'note' => null,
                'idempotency_key' => hash('sha256', implode('|', [
                    $link->id,
                    '26876133333',
                    '80.00',
                    'mtn_momo',
                    '202604231015',
                ])),
            ]);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')->twice();

        $service = $this->makeService($access, $policy, $adapter, $notifications);

        $firstTransfer = $service->createOutboundSupportTransfer($actor, $minorAccount, [
            'tenant_id' => 'tenant-test-1',
            'source_account_uuid' => $sourceAccount->uuid,
            'provider' => 'mtn_momo',
            'recipient_name' => 'Gogo Dlamini',
            'recipient_msisdn' => '+26876122222',
            'amount' => '80.00',
            'asset_code' => 'SZL',
            'idempotency_key' => 'idem-transfer-replay',
        ]);

        $replayedTransfer = $service->createOutboundSupportTransfer($actor, $minorAccount, [
            'tenant_id' => 'tenant-test-1',
            'source_account_uuid' => $sourceAccount->uuid,
            'provider' => 'mtn_momo',
            'recipient_name' => 'Gogo Dlamini',
            'recipient_msisdn' => '+26876122222',
            'amount' => '80.00',
            'asset_code' => 'SZL',
            'idempotency_key' => 'idem-transfer-replay',
        ]);

        $firstAttempt = $service->createPublicFundingAttempt($link, [
            'sponsor_name' => 'Replay Sponsor',
            'sponsor_msisdn' => '+26876133333',
            'amount' => '80.00',
            'asset_code' => 'SZL',
            'provider' => 'mtn_momo',
        ]);

        $replayedAttempt = $service->createPublicFundingAttempt($link, [
            'sponsor_name' => 'Replay Sponsor',
            'sponsor_msisdn' => '+26876133333',
            'amount' => '80.00',
            'asset_code' => 'SZL',
            'provider' => 'mtn_momo',
        ]);

        $this->assertSame($firstTransfer->id, $replayedTransfer->id);
        $this->assertSame($firstAttempt->id, $replayedAttempt->id);

        $this->assertSame(1, MinorFamilySupportTransfer::query()
            ->where('idempotency_key', 'idem-transfer-replay')
            ->count());
        $this->assertSame(1, MinorFamilyFundingAttempt::query()
            ->where('dedupe_hash', $firstAttempt->dedupe_hash)
            ->count());
        $this->assertSame(1, MtnMomoTransaction::query()
            ->where('context_type', MinorFamilySupportTransfer::class)
            ->where('context_uuid', $firstTransfer->id)
            ->count());
        $this->assertSame(1, MtnMomoTransaction::query()
            ->where('context_type', MinorFamilyFundingAttempt::class)
            ->where('context_uuid', $firstAttempt->id)
            ->count());
        $this->assertSame(1, OperationRecord::query()
            ->where('user_id', $actor->id)
            ->where('operation_type', 'minor_family_support_transfer')
            ->where('idempotency_key', 'idem-transfer-replay')
            ->count());
    }

    public function test_outbound_support_transfer_allows_same_idempotency_key_for_different_actors(): void
    {
        $firstActor = User::factory()->create();
        $secondActor = User::factory()->create();
        $minorOwner = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $minorOwner->uuid,
            'type' => 'minor',
        ]);

        $firstSourceAccount = Account::factory()->create([
            'user_uuid' => $firstActor->uuid,
            'type' => 'personal',
        ]);

        $secondSourceAccount = Account::factory()->create([
            'user_uuid' => $secondActor->uuid,
            'type' => 'personal',
        ]);

        $access = Mockery::mock(MinorAccountAccessService::class);
        $access->shouldReceive('authorizeGuardian')
            ->once()
            ->with($firstActor, Mockery::type(Account::class), $firstSourceAccount->uuid)
            ->andReturn($firstSourceAccount);
        $access->shouldReceive('authorizeGuardian')
            ->once()
            ->with($secondActor, Mockery::type(Account::class), $secondSourceAccount->uuid)
            ->andReturn($secondSourceAccount);

        $policy = Mockery::mock(MinorFamilyFundingPolicy::class);
        $policy->shouldReceive('validateOutboundSupportTransfer')
            ->twice()
            ->andReturn(MinorFamilyFundingPolicyResult::allow());

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateOutboundDisbursement')
            ->once()
            ->andReturn([
                'provider_name' => 'mtn_momo',
                'provider_reference_id' => 'provider-transfer-actor-1',
                'provider_status' => 'pending',
                'transaction_type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
                'party_msisdn' => '+26876120001',
                'amount' => '60.00',
                'asset_code' => 'SZL',
                'note' => null,
                'idempotency_key' => 'idem-transfer-shared',
            ]);
        $adapter->shouldReceive('initiateOutboundDisbursement')
            ->once()
            ->andReturn([
                'provider_name' => 'mtn_momo',
                'provider_reference_id' => 'provider-transfer-actor-2',
                'provider_status' => 'pending',
                'transaction_type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
                'party_msisdn' => '+26876120002',
                'amount' => '60.00',
                'asset_code' => 'SZL',
                'note' => null,
                'idempotency_key' => 'idem-transfer-shared',
            ]);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')->twice();

        $service = $this->makeService($access, $policy, $adapter, $notifications);

        $firstTransfer = $service->createOutboundSupportTransfer($firstActor, $minorAccount, [
            'tenant_id' => 'tenant-test-1',
            'source_account_uuid' => $firstSourceAccount->uuid,
            'provider' => 'mtn_momo',
            'recipient_name' => 'Relative One',
            'recipient_msisdn' => '+26876120001',
            'amount' => '60.00',
            'asset_code' => 'SZL',
            'idempotency_key' => 'idem-transfer-shared',
        ]);

        $secondTransfer = $service->createOutboundSupportTransfer($secondActor, $minorAccount, [
            'tenant_id' => 'tenant-test-1',
            'source_account_uuid' => $secondSourceAccount->uuid,
            'provider' => 'mtn_momo',
            'recipient_name' => 'Relative Two',
            'recipient_msisdn' => '+26876120002',
            'amount' => '60.00',
            'asset_code' => 'SZL',
            'idempotency_key' => 'idem-transfer-shared',
        ]);

        $this->assertNotSame($firstTransfer->id, $secondTransfer->id);
        $this->assertSame(2, MinorFamilySupportTransfer::query()
            ->where('idempotency_key', 'idem-transfer-shared')
            ->count());
        $this->assertSame(1, MinorFamilySupportTransfer::query()
            ->where('actor_user_uuid', $firstActor->uuid)
            ->where('idempotency_key', 'idem-transfer-shared')
            ->count());
        $this->assertSame(1, MinorFamilySupportTransfer::query()
            ->where('actor_user_uuid', $secondActor->uuid)
            ->where('idempotency_key', 'idem-transfer-shared')
            ->count());
    }

    public function test_funding_link_idempotent_replay_does_not_create_duplicate_links(): void
    {
        $actor = User::factory()->create();
        $minorOwner = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $minorOwner->uuid,
            'type' => 'minor',
        ]);

        $actingAccount = Account::factory()->create([
            'user_uuid' => $actor->uuid,
            'type' => 'personal',
        ]);

        $access = Mockery::mock(MinorAccountAccessService::class);
        $access->shouldReceive('authorizeGuardian')
            ->once()
            ->andReturn($actingAccount);

        $policy = Mockery::mock(MinorFamilyFundingPolicy::class);
        $policy->shouldReceive('validateLinkCreation')
            ->once()
            ->andReturn(MinorFamilyFundingPolicyResult::allow());

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')->once();

        $service = $this->makeService($access, $policy, $adapter, $notifications);
        $beforeEvents = DB::table('stored_events')
            ->where('event_class', 'minor_family_funding_link_created')
            ->count();

        $firstLink = $service->createFundingLink($actor, $minorAccount, [
            'tenant_id' => 'tenant-test-1',
            'created_by_account_uuid' => $actingAccount->uuid,
            'title' => 'School trip',
            'note' => 'One-time support collection',
            'amount_mode' => 'capped',
            'target_amount' => '1000.00',
            'provider_options' => ['mtn_momo'],
            'expires_at' => now()->addDay()->toIso8601String(),
            'idempotency_key' => 'idem-funding-link-1',
        ]);

        $replayedLink = $service->createFundingLink($actor, $minorAccount, [
            'tenant_id' => 'tenant-test-1',
            'created_by_account_uuid' => $actingAccount->uuid,
            'title' => 'School trip',
            'note' => 'One-time support collection',
            'amount_mode' => 'capped',
            'target_amount' => '1000.00',
            'provider_options' => ['mtn_momo'],
            'expires_at' => now()->addDay()->toIso8601String(),
            'idempotency_key' => 'idem-funding-link-1',
        ]);

        $this->assertSame($firstLink->id, $replayedLink->id);
        $this->assertSame(1, MinorFamilyFundingLink::query()
            ->where('created_by_user_uuid', $actor->uuid)
            ->where('title', 'School trip')
            ->count());
        $this->assertSame(1, OperationRecord::query()
            ->where('user_id', $actor->id)
            ->where('operation_type', 'minor_family_funding_link')
            ->where('idempotency_key', 'idem-funding-link-1')
            ->count());
        $this->assertSame($beforeEvents + 1, DB::table('stored_events')
            ->where('event_class', 'minor_family_funding_link_created')
            ->count());
    }

    public function test_outbound_support_transfer_transient_provider_failure_does_not_burn_idempotency_key(): void
    {
        $actor = User::factory()->create();
        $minorOwner = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $minorOwner->uuid,
            'type' => 'minor',
        ]);

        $sourceAccount = Account::factory()->create([
            'user_uuid' => $actor->uuid,
            'type' => 'personal',
        ]);

        $access = Mockery::mock(MinorAccountAccessService::class);
        $access->shouldReceive('authorizeGuardian')
            ->twice()
            ->andReturn($sourceAccount);

        $policy = Mockery::mock(MinorFamilyFundingPolicy::class);
        $policy->shouldReceive('validateOutboundSupportTransfer')
            ->twice()
            ->andReturn(MinorFamilyFundingPolicyResult::allow());

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateOutboundDisbursement')
            ->once()
            ->andThrow(new \RuntimeException('transient provider failure'));
        $adapter->shouldReceive('initiateOutboundDisbursement')
            ->once()
            ->andReturn([
                'provider_name' => 'mtn_momo',
                'provider_reference_id' => 'provider-transfer-retry',
                'provider_status' => 'pending',
                'transaction_type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
                'party_msisdn' => '+26876123456',
                'amount' => '250.00',
                'asset_code' => 'SZL',
                'note' => 'School support',
                'idempotency_key' => 'idem-transfer-retry',
            ]);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')->once();

        $service = $this->makeService($access, $policy, $adapter, $notifications);

        try {
            $service->createOutboundSupportTransfer($actor, $minorAccount, [
                'tenant_id' => 'tenant-test-1',
                'source_account_uuid' => $sourceAccount->uuid,
                'provider' => 'mtn_momo',
                'recipient_name' => 'Gogo Dlamini',
                'recipient_msisdn' => '+26876123456',
                'amount' => '250.00',
                'asset_code' => 'SZL',
                'note' => 'School support',
                'idempotency_key' => 'idem-transfer-retry',
            ]);

            $this->fail('Expected the first provider initiation to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('transient provider failure', $exception->getMessage());
        }

        $this->assertDatabaseMissing('operation_records', [
            'user_id' => $actor->id,
            'operation_type' => 'minor_family_support_transfer',
            'idempotency_key' => 'idem-transfer-retry',
        ]);

        $transfer = $service->createOutboundSupportTransfer($actor, $minorAccount, [
            'tenant_id' => 'tenant-test-1',
            'source_account_uuid' => $sourceAccount->uuid,
            'provider' => 'mtn_momo',
            'recipient_name' => 'Gogo Dlamini',
            'recipient_msisdn' => '+26876123456',
            'amount' => '250.00',
            'asset_code' => 'SZL',
            'note' => 'School support',
            'idempotency_key' => 'idem-transfer-retry',
        ]);

        $this->assertSame('provider-transfer-retry', $transfer->provider_reference_id);
    }

    public function test_public_funding_attempt_transient_provider_failure_does_not_burn_dedupe_hash(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-23 10:30:00'));

        $creator = User::factory()->create();
        $minorOwner = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $minorOwner->uuid,
            'type' => 'minor',
        ]);

        $link = MinorFamilyFundingLink::query()->create([
            'tenant_id' => 'tenant-test-1',
            'minor_account_uuid' => $minorAccount->uuid,
            'created_by_user_uuid' => $creator->uuid,
            'created_by_account_uuid' => Account::factory()->create([
                'user_uuid' => $creator->uuid,
                'type' => 'personal',
            ])->uuid,
            'title' => 'Family support',
            'note' => 'Help Sipho',
            'token' => (string) Str::uuid(),
            'status' => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode' => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount' => '120.00',
            'target_amount' => null,
            'collected_amount' => '0.00',
            'asset_code' => 'SZL',
            'provider_options' => ['mtn_momo'],
            'expires_at' => now()->addDay(),
        ]);

        $access = Mockery::mock(MinorAccountAccessService::class);

        $policy = Mockery::mock(MinorFamilyFundingPolicy::class);
        $policy->shouldReceive('validateFundingAttempt')
            ->twice()
            ->andReturn(MinorFamilyFundingPolicyResult::allow());

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateInboundCollection')
            ->once()
            ->andThrow(new \RuntimeException('transient provider failure'));
        $adapter->shouldReceive('initiateInboundCollection')
            ->once()
            ->andReturn([
                'provider_name' => 'mtn_momo',
                'provider_reference_id' => 'provider-attempt-retry',
                'provider_status' => 'pending',
                'transaction_type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
                'party_msisdn' => '+26876123456',
                'amount' => '120.00',
                'asset_code' => 'SZL',
                'note' => 'Help Sipho',
                'idempotency_key' => hash('sha256', implode('|', [
                    $link->id,
                    '26876123456',
                    '120.00',
                    'mtn_momo',
                    '202604231030',
                ])),
            ]);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')->once();

        $service = $this->makeService($access, $policy, $adapter, $notifications);

        try {
            $service->createPublicFundingAttempt($link, [
                'sponsor_name' => 'Auntie',
                'sponsor_msisdn' => '+26876123456',
                'amount' => '120.00',
                'asset_code' => 'SZL',
                'provider' => 'mtn_momo',
            ]);

            $this->fail('Expected the first provider initiation to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('transient provider failure', $exception->getMessage());
        }

        $expectedDedupe = hash('sha256', implode('|', [
            $link->id,
            '26876123456',
            '120.00',
            'mtn_momo',
            '202604231030',
        ]));

        $this->assertDatabaseMissing('minor_family_funding_attempts', [
            'dedupe_hash' => $expectedDedupe,
            'status' => MinorFamilyFundingAttempt::STATUS_FAILED,
            'failed_reason' => 'provider_initiation_failed',
        ]);
        $this->assertDatabaseMissing('mtn_momo_transactions', [
            'idempotency_key' => $expectedDedupe,
            'status' => MtnMomoTransaction::STATUS_FAILED,
        ]);

        $retry = $service->createPublicFundingAttempt($link, [
            'sponsor_name' => 'Auntie',
            'sponsor_msisdn' => '+26876123456',
            'amount' => '120.00',
            'asset_code' => 'SZL',
            'provider' => 'mtn_momo',
        ]);

        $this->assertSame('provider-attempt-retry', $retry->provider_reference_id);
        $this->assertSame(MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER, $retry->status);
    }

    public function test_public_funding_attempt_rejects_asset_mismatch_against_link(): void
    {
        $creator = User::factory()->create();
        $minorOwner = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $minorOwner->uuid,
            'type' => 'minor',
        ]);

        $link = MinorFamilyFundingLink::query()->create([
            'tenant_id' => 'tenant-test-1',
            'minor_account_uuid' => $minorAccount->uuid,
            'created_by_user_uuid' => $creator->uuid,
            'created_by_account_uuid' => Account::factory()->create([
                'user_uuid' => $creator->uuid,
                'type' => 'personal',
            ])->uuid,
            'title' => 'Family support',
            'note' => 'Help Sipho',
            'token' => (string) Str::uuid(),
            'status' => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode' => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount' => '120.00',
            'target_amount' => null,
            'collected_amount' => '0.00',
            'asset_code' => 'SZL',
            'provider_options' => ['mtn_momo'],
            'expires_at' => now()->addDay(),
        ]);

        $access = Mockery::mock(MinorAccountAccessService::class);

        $policy = Mockery::mock(MinorFamilyFundingPolicy::class);
        $policy->shouldReceive('validateFundingAttempt')->never();

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateInboundCollection')->never();

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')->never();

        $service = $this->makeService($access, $policy, $adapter, $notifications);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('Requested asset code must match the funding link asset code.');

        $service->createPublicFundingAttempt($link, [
            'sponsor_name' => 'Auntie',
            'sponsor_msisdn' => '+26876123456',
            'amount' => '120.00',
            'asset_code' => 'USD',
            'provider' => 'mtn_momo',
        ]);
    }

    private function makeService(
        MinorAccountAccessService $access,
        MinorFamilyFundingPolicy $policy,
        MtnMomoFamilyFundingAdapter $adapter,
        MinorNotificationService $notifications,
    ): MinorFamilyIntegrationService {
        $telemetry = Mockery::mock(MaphaPayMoneyMovementTelemetry::class);
        $telemetry->shouldIgnoreMissing();

        return new MinorFamilyIntegrationService(
            accessService: $access,
            fundingPolicy: $policy,
            fundingAdapter: $adapter,
            notifications: $notifications,
            operationRecords: new OperationRecordService($telemetry),
        );
    }

    private function ensureMinorFamilyPhase9Schema(): void
    {
        if (! Schema::hasTable('minor_family_funding_links')) {
            DB::table('migrations')
                ->where('migration', '2026_04_23_100000_create_minor_family_funding_links_table')
                ->delete();
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_04_23_100000_create_minor_family_funding_links_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_family_funding_attempts')) {
            DB::table('migrations')
                ->where('migration', '2026_04_23_100100_create_minor_family_funding_attempts_table')
                ->delete();
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_04_23_100100_create_minor_family_funding_attempts_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_family_support_transfers')) {
            DB::table('migrations')
                ->where('migration', '2026_04_23_100200_create_minor_family_support_transfers_table')
                ->delete();
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_04_23_100200_create_minor_family_support_transfers_table.php',
                '--force' => true,
            ]);
        }

        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_04_23_100250_scope_minor_family_support_transfer_idempotency_unique.php',
            '--force' => true,
        ]);

        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_04_23_100350_update_minor_family_status_defaults_to_pending_provider.php',
            '--force' => true,
        ]);

        if (! Schema::hasColumns('mtn_momo_transactions', ['context_type', 'context_uuid'])) {
            DB::table('migrations')
                ->where('migration', '2026_04_23_100300_add_minor_family_context_to_mtn_momo_transactions_table')
                ->delete();
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_04_23_100300_add_minor_family_context_to_mtn_momo_transactions_table.php',
                '--force' => true,
            ]);
        }
    }

    private function resetPhase9aRows(): void
    {
        DB::table('minor_family_support_transfers')->delete();
        DB::table('minor_family_funding_attempts')->delete();
        DB::table('minor_family_funding_links')->delete();
        DB::table('mtn_momo_transactions')->delete();
        DB::table('operation_records')->delete();
    }
}
