<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\RequestMoney;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Mobile\Models\MobileDevice;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class RequestMoneyReceivedTrustPolicyTest extends ControllerTestCase
{
    private User $requester;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requester = User::factory()->create(['kyc_status' => 'approved']);
        $this->recipient = User::factory()->create(['kyc_status' => 'approved']);
        $this->createAccount($this->requester);
        $this->createAccount($this->recipient);

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name' => 'Swazi Lilangeni',
                'type' => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

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

    private function pendingMoneyRequest(): MoneyRequest
    {
        return MoneyRequest::query()->create([
            'id' => (string) Str::uuid(),
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount' => '10.00',
            'asset_code' => 'SZL',
            'note' => null,
            'status' => MoneyRequest::STATUS_PENDING,
            'trx' => null,
        ]);
    }

    private function createTrustedDeviceForRecipient(): string
    {
        $deviceId = 'request-money-trusted-device-'.$this->recipient->id;

        MobileDevice::factory()
            ->trusted()
            ->ios()
            ->create([
                'user_id' => $this->recipient->id,
                'device_id' => $deviceId,
            ]);

        return $deviceId;
    }

    #[Test]
    public function test_request_money_accept_returns_step_up_and_does_not_create_authorization_for_untrusted_device(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
            'mobile.attestation.enabled' => false,
        ]);

        $moneyRequest = $this->pendingMoneyRequest();

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/request-money/received-store/{$moneyRequest->id}", [
            'verification_type' => 'sms',
        ]);

        $response->assertStatus(428)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'TRUST_POLICY_STEP_UP');

        $this->assertSame(
            0,
            AuthorizedTransaction::query()
                ->where('remark', AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED)
                ->where('user_id', $this->recipient->id)
                ->count(),
        );
    }

    #[Test]
    public function test_request_money_accept_returns_deny_when_attestation_is_required_but_missing(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
            'mobile.attestation.enabled' => true,
        ]);

        $moneyRequest = $this->pendingMoneyRequest();

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/request-money/received-store/{$moneyRequest->id}", [
            'verification_type' => 'sms',
            'device_type' => 'ios',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'TRUST_POLICY_DENY');

        $this->assertDatabaseHas('mobile_attestation_records', [
            'user_id' => $this->recipient->id,
            'action' => 'request_money.accept',
            'decision' => 'deny',
            'reason' => 'attestation_required',
        ]);
    }

    #[Test]
    public function test_request_money_accept_persists_allow_trust_decision_in_transaction_payload(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
            'mobile.attestation.enabled' => false,
        ]);

        $moneyRequest = $this->pendingMoneyRequest();
        $deviceId = $this->createTrustedDeviceForRecipient();

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/request-money/received-store/{$moneyRequest->id}", [
            'verification_type' => 'sms',
            'device_type' => 'ios',
            'device_id' => $deviceId,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $trx = (string) $response->json('data.trx');
        $txn = AuthorizedTransaction::query()->where('trx', $trx)->firstOrFail();

        $this->assertSame('allow', $txn->payload['_trust_decision'] ?? null);
        $this->assertNotNull($txn->payload['_trust_record_id'] ?? null);

        $this->assertDatabaseHas('mobile_attestation_records', [
            'id' => $txn->payload['_trust_record_id'] ?? null,
            'user_id' => $this->recipient->id,
            'action' => 'request_money.accept',
            'decision' => 'allow',
        ]);
    }
}
