<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\VerificationProcess;

use App\Domain\AuthorizedTransaction\Exceptions\TransactionNotFoundException;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransactionBiometricChallenge;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionBiometricService;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class ChallengeBiometricControllerTest extends ControllerTestCase
{
    private const ROUTE = '/api/verification-process/challenge/biometric';

    protected function setUp(): void
    {
        parent::setUp();

        config(['maphapay_migration.enable_verification' => true]);
    }

    #[Test]
    public function it_returns_a_transaction_biometric_challenge(): void
    {
        $user = User::factory()->create();

        $challenge = new AuthorizedTransactionBiometricChallenge([
            'challenge'  => str_repeat('a', 64),
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->mock(AuthorizedTransactionBiometricService::class, function ($mock) use ($user, $challenge): void {
            $mock->shouldReceive('issueChallengeForUser')
                ->once()
                ->with('TRX-BIO-1', $user->id, 'device-1', 'send_money', Mockery::type('string'))
                ->andReturn($challenge);
        });

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx'       => 'TRX-BIO-1',
            'device_id' => 'device-1',
            'remark'    => 'send_money',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('remark', 'send_money')
            ->assertJsonPath('data.trx', 'TRX-BIO-1')
            ->assertJsonPath('data.device_id', 'device-1')
            ->assertJsonPath('data.challenge', str_repeat('a', 64));
    }

    #[Test]
    public function it_returns_a_404_when_the_transaction_does_not_exist(): void
    {
        $user = User::factory()->create();

        $this->mock(AuthorizedTransactionBiometricService::class, function ($mock) use ($user): void {
            $mock->shouldReceive('issueChallengeForUser')
                ->once()
                ->with('TRX-MISSING', $user->id, 'device-1', null, Mockery::type('string'))
                ->andThrow(new TransactionNotFoundException('Transaction not found.'));
        });

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx'       => 'TRX-MISSING',
            'device_id' => 'device-1',
        ]);

        $response->assertNotFound()
            ->assertExactJson([
                'status'  => 'error',
                'remark'  => 'biometric_challenge',
                'message' => ['Transaction not found.'],
                'data'    => null,
            ]);
    }

    #[Test]
    public function it_returns_a_compatibility_error_envelope_for_invalid_requests(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => 'TRX-BAD',
        ]);

        $response->assertStatus(422)
            ->assertExactJson([
                'status'  => 'error',
                'remark'  => 'biometric_challenge',
                'message' => ['The device id field is required.'],
                'data'    => null,
            ]);
    }
}
