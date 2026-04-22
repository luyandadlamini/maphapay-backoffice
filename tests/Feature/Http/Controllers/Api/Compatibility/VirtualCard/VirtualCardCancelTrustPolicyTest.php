<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\VirtualCard;

use App\Domain\CardIssuance\Enums\CardNetwork;
use App\Domain\CardIssuance\Enums\CardStatus;
use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Domain\CardIssuance\ValueObjects\VirtualCard;
use App\Domain\Mobile\Models\MobileDevice;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class VirtualCardCancelTrustPolicyTest extends ControllerTestCase
{
    protected User $virtualCardUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->virtualCardUser = User::factory()->create();

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

    private function createTrustedDeviceId(): string
    {
        $deviceId = 'trusted-card-device-' . $this->virtualCardUser->id;

        MobileDevice::factory()
            ->trusted()
            ->ios()
            ->create([
                'user_id'   => $this->virtualCardUser->id,
                'device_id' => $deviceId,
            ]);

        return $deviceId;
    }

    private function makeVirtualCard(string $cardToken): VirtualCard
    {
        return new VirtualCard(
            cardToken: $cardToken,
            last4: '4242',
            network: CardNetwork::VISA,
            status: CardStatus::ACTIVE,
            cardholderName: $this->virtualCardUser->name ?? 'Card Holder',
            expiresAt: new DateTimeImmutable('+3 years'),
            metadata: [
                'user_id' => $this->virtualCardUser->uuid,
            ],
        );
    }

    #[Test]
    public function test_virtual_card_cancel_returns_step_up_for_untrusted_device_and_keeps_card_active(): void
    {
        config([
            'mobile.attestation.enabled' => false,
        ]);

        Sanctum::actingAs($this->virtualCardUser, ['read', 'write', 'delete']);

        $cardToken = 'card-test-untrusted';

        $cardService = Mockery::mock(CardProvisioningService::class);
        $cardService->shouldReceive('getCard')
            ->once()
            ->with($cardToken)
            ->andReturn($this->makeVirtualCard($cardToken));
        $cardService->shouldReceive('cancelCard')->never();
        app()->instance(CardProvisioningService::class, $cardService);

        $response = $this->postJson("/api/virtual-card/cancel/{$cardToken}", [
            'device_type' => 'ios',
        ]);

        $response->assertStatus(428)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'TRUST_POLICY_STEP_UP');

        $this->assertDatabaseHas('mobile_attestation_records', [
            'user_id'  => $this->virtualCardUser->id,
            'action'   => 'virtual_card.cancel',
            'decision' => 'degrade',
        ]);

    }

    #[Test]
    public function test_virtual_card_cancel_returns_deny_when_attestation_is_required_but_missing(): void
    {
        config([
            'mobile.attestation.enabled' => true,
        ]);

        Sanctum::actingAs($this->virtualCardUser, ['read', 'write', 'delete']);

        $cardToken = 'card-test-attestation-required';

        $cardService = Mockery::mock(CardProvisioningService::class);
        $cardService->shouldReceive('getCard')
            ->once()
            ->with($cardToken)
            ->andReturn($this->makeVirtualCard($cardToken));
        $cardService->shouldReceive('cancelCard')->never();
        app()->instance(CardProvisioningService::class, $cardService);

        $response = $this->postJson("/api/virtual-card/cancel/{$cardToken}", [
            'device_type' => 'ios',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'TRUST_POLICY_DENY');

        $this->assertDatabaseHas('mobile_attestation_records', [
            'user_id'  => $this->virtualCardUser->id,
            'action'   => 'virtual_card.cancel',
            'decision' => 'deny',
            'reason'   => 'attestation_required',
        ]);
    }

    #[Test]
    public function test_virtual_card_cancel_allows_trusted_device_and_cancels_card(): void
    {
        config([
            'mobile.attestation.enabled' => false,
        ]);

        Sanctum::actingAs($this->virtualCardUser, ['read', 'write', 'delete']);

        $cardToken = 'card-test-trusted';
        $deviceId = $this->createTrustedDeviceId();

        $cardService = Mockery::mock(CardProvisioningService::class);
        $cardService->shouldReceive('getCard')
            ->once()
            ->with($cardToken)
            ->andReturn($this->makeVirtualCard($cardToken));
        $cardService->shouldReceive('cancelCard')
            ->once()
            ->with($cardToken, 'User requested cancellation')
            ->andReturn(true);
        app()->instance(CardProvisioningService::class, $cardService);

        $response = $this->postJson("/api/virtual-card/cancel/{$cardToken}", [
            'device_type' => 'ios',
            'device_id'   => $deviceId,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.card_id', $cardToken);

        $this->assertDatabaseHas('mobile_attestation_records', [
            'user_id'  => $this->virtualCardUser->id,
            'action'   => 'virtual_card.cancel',
            'decision' => 'allow',
        ]);

    }
}
