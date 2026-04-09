<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\SendMoney;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Mobile\Models\MobileDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class SendMoneyTrustPolicyTest extends ControllerTestCase
{
    private User $sender;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maphapay_migration.enable_verification' => true,
        ]);

        $this->sender = User::factory()->create(['kyc_status' => 'approved']);
        $this->recipient = User::factory()->create(['kyc_status' => 'approved']);

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name' => 'Swazi Lilangeni',
                'type' => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        Account::factory()->create([
            'user_uuid' => $this->sender->uuid,
            'frozen' => false,
        ]);

        Account::factory()->create([
            'user_uuid' => $this->recipient->uuid,
            'frozen' => false,
        ]);

        if (! Schema::hasTable('mobile_attestation_records')) {
            Schema::create('mobile_attestation_records', function ($table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->uuid('mobile_device_id')->nullable();
                $table->string('action', 120);
                $table->string('decision', 30);
                $table->string('reason', 120);
                $table->boolean('attestation_enabled')->default(false);
                $table->boolean('attestation_verified')->default(false);
                $table->string('device_type', 30)->nullable();
                $table->string('device_id', 150)->nullable();
                $table->string('payload_hash', 64)->nullable();
                $table->string('request_path', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        DB::table('mobile_attestation_records')->delete();
    }

    private function createTrustedDeviceIdForSender(): string
    {
        $deviceId = 'trusted-device-'.$this->sender->id;

        MobileDevice::factory()
            ->trusted()
            ->ios()
            ->create([
                'user_id' => $this->sender->id,
                'device_id' => $deviceId,
            ]);

        return $deviceId;
    }

    #[Test]
    public function test_send_money_verification_none_returns_degrade_when_device_untrusted_and_does_not_create_authorization(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
            'mobile.attestation.enabled' => false,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '10.00',
            'verification_type' => 'none',
        ]);

        $response->assertStatus(428)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonPath('error.code', 'TRUST_POLICY_STEP_UP');

        $this->assertDatabaseHas('mobile_attestation_records', [
            'user_id' => $this->sender->id,
            'action' => 'send_money',
            'decision' => 'degrade',
        ]);

        $this->assertSame(
            0,
            AuthorizedTransaction::query()
                ->where('user_id', $this->sender->id)
                ->where('remark', AuthorizedTransaction::REMARK_SEND_MONEY)
                ->count(),
        );
    }

    #[Test]
    public function test_send_money_returns_a_posture_specific_degrade_reason_for_simulator_runtime_posture(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
            'mobile.attestation.enabled' => false,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '10.00',
            'verification_type' => 'none',
            'device_type' => 'ios',
            'device_posture_source' => 'runtime-observed',
            'device_posture_status' => 'simulator_or_emulator',
            'device_posture_reason' => 'non_physical_device',
        ]);

        $response->assertStatus(428)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonPath('error.code', 'TRUST_POLICY_STEP_UP')
            ->assertJsonPath('error.trust_reason', 'device_posture_untrusted');

        $recordId = DB::table('mobile_attestation_records')
            ->where('user_id', $this->sender->id)
            ->where('action', 'send_money')
            ->value('id');

        $this->assertNotNull($recordId);

        $metadata = DB::table('mobile_attestation_records')
            ->where('id', $recordId)
            ->value('metadata');

        $this->assertIsString($metadata);
        $decoded = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('runtime-observed', $decoded['device_posture']['source'] ?? null);
        $this->assertSame('simulator_or_emulator', $decoded['device_posture']['status'] ?? null);
        $this->assertSame('non_physical_device', $decoded['device_posture']['reason'] ?? null);
    }

    #[Test]
    public function test_send_money_runtime_posture_fallback_does_not_bypass_step_up_when_attestation_enforcement_is_disabled(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
            'mobile.attestation.enabled' => false,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '10.00',
            'verification_type' => 'none',
            'device_type' => 'ios',
            'attestation' => 'expo-runtime-attestation:{"mode":"runtime-posture"}',
            'attestation_status' => 'collected',
            'attestation_capability_mode' => 'runtime-posture',
            'attestation_capability_reason' => 'ios_app_attest_native_collection_unimplemented',
            'attestation_capability_available' => false,
            'device_posture_source' => 'runtime-observed',
            'device_posture_status' => 'physical_device',
            'device_posture_reason' => 'physical_device_confirmed',
        ]);

        $response->assertStatus(428)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonPath('error.code', 'TRUST_POLICY_STEP_UP')
            ->assertJsonPath('error.trust_reason', 'attestation_disabled_device_untrusted');

        $this->assertDatabaseHas('mobile_attestation_records', [
            'user_id' => $this->sender->id,
            'action' => 'send_money',
            'decision' => 'degrade',
            'reason' => 'attestation_disabled_device_untrusted',
        ]);

        $this->assertSame(
            0,
            AuthorizedTransaction::query()
                ->where('user_id', $this->sender->id)
                ->where('remark', AuthorizedTransaction::REMARK_SEND_MONEY)
                ->count(),
        );
    }

    #[Test]
    public function test_send_money_verification_none_denies_when_attestation_enabled_but_missing_and_does_not_create_authorization(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
            'mobile.attestation.enabled' => true,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '10.00',
            'verification_type' => 'none',
            'device_type' => 'ios',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonPath('error.code', 'TRUST_POLICY_DENY');

        $this->assertDatabaseHas('mobile_attestation_records', [
            'user_id' => $this->sender->id,
            'action' => 'send_money',
            'decision' => 'deny',
            'reason' => 'attestation_required',
        ]);

        $this->assertSame(
            0,
            AuthorizedTransaction::query()
                ->where('user_id', $this->sender->id)
                ->where('remark', AuthorizedTransaction::REMARK_SEND_MONEY)
                ->count(),
        );
    }

    #[Test]
    public function test_send_money_denies_with_explicit_provider_error_reason_when_attestation_collection_fails(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
            'mobile.attestation.enabled' => true,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '10.00',
            'verification_type' => 'none',
            'device_type' => 'ios',
            'attestation_status' => 'error',
            'attestation_capability_mode' => 'none',
            'attestation_capability_reason' => 'provider_error',
            'attestation_capability_available' => false,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonPath('error.code', 'TRUST_POLICY_DENY')
            ->assertJsonPath('error.trust_reason', 'attestation_provider_error');

        $this->assertDatabaseHas('mobile_attestation_records', [
            'user_id' => $this->sender->id,
            'action' => 'send_money',
            'decision' => 'deny',
            'reason' => 'attestation_provider_error',
        ]);
    }

    #[Test]
    public function test_send_money_otp_or_pin_flow_persists_allow_trust_decision_in_transaction_payload(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
            'mobile.attestation.enabled' => false,
        ]);

        $deviceId = $this->createTrustedDeviceIdForSender();

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '1000.00',
            'verification_type' => 'none',
            'device_id' => $deviceId,
            'device_type' => 'ios',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $trx = (string) $response->json('data.trx');
        $this->assertNotSame('', $trx);

        $txn = AuthorizedTransaction::query()->where('trx', $trx)->firstOrFail();
        $this->assertSame('allow', $txn->payload['_trust_decision'] ?? null);
        $this->assertNotNull($txn->payload['_trust_record_id'] ?? null);

        $this->assertDatabaseHas('mobile_attestation_records', [
            'id' => $txn->payload['_trust_record_id'] ?? null,
            'user_id' => $this->sender->id,
            'action' => 'send_money',
            'decision' => 'allow',
        ]);
    }

    #[Test]
    public function test_send_money_verify_pin_fails_when_legacy_pending_transaction_is_missing_trust_decision(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
            'mobile.attestation.enabled' => false,
        ]);

        $this->sender->update([
            'transaction_pin' => bcrypt('1234'),
            'transaction_pin_enabled' => true,
        ]);

        $txn = AuthorizedTransaction::query()->create([
            'user_id' => $this->sender->id,
            'remark' => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx' => 'TRX-TRUST-MISSING-1',
            'payload' => [
                'from_account_uuid' => 'from-account-uuid',
                'to_account_uuid' => 'to-account-uuid',
                'amount' => '10.00',
                'asset_code' => 'SZL',
            ],
            'status' => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_PIN,
            'expires_at' => now()->addMinutes(30),
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/verification-process/verify/pin', [
            'trx' => $txn->trx,
            'pin' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message.0', 'Transaction blocked by mobile trust policy. Missing trust decision.');
    }
}
