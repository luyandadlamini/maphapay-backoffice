<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\VerificationProcess;

use App\Domain\AuthorizedTransaction\Exceptions\TransactionNotFoundException;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionBiometricService;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\ControllerTestCase;

class VerifyBiometricControllerTest extends ControllerTestCase
{
    private const ROUTE = '/api/verification-process/verify/biometric';

    protected function setUp(): void
    {
        parent::setUp();

        config(['maphapay_migration.enable_verification' => true]);
    }

    #[Test]
    public function it_verifies_biometric_approval_and_finalizes_the_transaction(): void
    {
        $user = User::factory()->create();

        $this->mock(AuthorizedTransactionBiometricService::class, function ($mock) use ($user): void {
            $mock->shouldReceive('verifyChallengeForUser')
                ->once()
                ->with('TRX-BIO-VERIFY', $user->id, 'device-1', 'challenge-token', 'signed-payload', 'send_money', \Mockery::type('string'));
        });

        $this->mock(AuthorizedTransactionManager::class, function ($mock) use ($user): void {
            $mock->shouldReceive('verifyBiometric')
                ->once()
                ->with('TRX-BIO-VERIFY', $user->id)
                ->andReturn([
                    'trx' => 'TRX-BIO-VERIFY',
                    'amount' => '25.00',
                    'asset_code' => 'SZL',
                    'reference' => 'mock-transfer-id',
                ]);
        });

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => 'TRX-BIO-VERIFY',
            'device_id' => 'device-1',
            'challenge' => 'challenge-token',
            'signature' => 'signed-payload',
            'remark' => 'send_money',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('remark', 'send_money')
            ->assertJsonPath('data.trx', 'TRX-BIO-VERIFY')
            ->assertJsonPath('data.reference', 'mock-transfer-id');
    }

    #[Test]
    public function it_returns_a_404_when_the_transaction_is_missing(): void
    {
        $user = User::factory()->create();

        $this->mock(AuthorizedTransactionBiometricService::class, function ($mock) use ($user): void {
            $mock->shouldReceive('verifyChallengeForUser')
                ->once()
                ->with('TRX-MISSING', $user->id, 'device-1', 'challenge-token', 'signed-payload', null, \Mockery::type('string'))
                ->andThrow(new TransactionNotFoundException('Transaction not found.'));
        });

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => 'TRX-MISSING',
            'device_id' => 'device-1',
            'challenge' => 'challenge-token',
            'signature' => 'signed-payload',
        ]);

        $response->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'remark' => 'biometric_verified',
                'message' => ['Transaction not found.'],
                'data' => null,
            ]);
    }

    #[Test]
    public function it_returns_a_compatibility_error_envelope_for_biometric_failures(): void
    {
        $user = User::factory()->create();

        $this->mock(AuthorizedTransactionBiometricService::class, function ($mock) use ($user): void {
            $mock->shouldReceive('verifyChallengeForUser')
                ->once()
                ->with('TRX-BIO-VERIFY', $user->id, 'device-1', 'challenge-token', 'signed-payload', null, \Mockery::type('string'))
                ->andThrow(new RuntimeException('Unable to verify your identity. Please try again.'));
        });

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => 'TRX-BIO-VERIFY',
            'device_id' => 'device-1',
            'challenge' => 'challenge-token',
            'signature' => 'signed-payload',
        ]);

        $response->assertStatus(422)
            ->assertExactJson([
                'status' => 'error',
                'remark' => 'biometric_verified',
                'message' => ['Unable to verify your identity. Please try again.'],
                'data' => null,
            ]);
    }
}
