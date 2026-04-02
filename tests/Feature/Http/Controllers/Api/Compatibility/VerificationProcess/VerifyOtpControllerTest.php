<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\VerificationProcess;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\InternalP2pTransferService;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class VerifyOtpControllerTest extends ControllerTestCase
{
    private const OTP = '123456';

    private const ROUTE = '/api/verification-process/verify/otp';

    protected function setUp(): void
    {
        parent::setUp();

        config(['maphapay_migration.enable_verification' => true]);
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makePendingTransaction(User $user): AuthorizedTransaction
    {
        return AuthorizedTransaction::create([
            'user_id'           => $user->id,
            'remark'            => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx'               => 'TRX-OTPTEST1',
            'payload'           => [
                'from_account_uuid' => '00000000-0000-0000-0000-000000000001',
                'to_account_uuid'   => '00000000-0000-0000-0000-000000000002',
                'amount'            => '10.00',
                'asset_code'        => 'SZL',
            ],
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
            'otp_hash'          => Hash::make(self::OTP),
            'otp_sent_at'       => now(),
            'otp_expires_at'    => now()->addMinutes(10),
            'expires_at'        => now()->addHour(),
        ]);
    }

    #[Test]
    public function test_valid_otp_returns_200_and_completes_transaction(): void
    {
        $user = $this->makeUser();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $txn = $this->makePendingTransaction($user);

        $this->mock(InternalP2pTransferService::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturn([
                'amount' => '10.00',
                'asset_code' => 'SZL',
                'reference' => 'mock-transfer-id',
            ]);
        });

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx'    => $txn->trx,
            'otp'    => self::OTP,
            'remark' => 'send_money',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('remark', 'send_money')
            ->assertJsonStructure(['data' => ['trx', 'amount', 'asset_code', 'reference']]);
    }

    #[Test]
    public function test_wrong_otp_returns_422_with_error_envelope(): void
    {
        $user = $this->makeUser();
        $txn = $this->makePendingTransaction($user);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => $txn->trx,
            'otp' => '654321',
        ]);

        $response->assertStatus(422)
            ->assertExactJson([
                'status'  => 'error',
                'remark'  => 'otp_verified',
                'message' => ['Invalid OTP.'],
                'data'    => null,
            ]);
    }

    #[Test]
    public function test_unknown_trx_returns_404_error_envelope(): void
    {
        $user = $this->makeUser();

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => 'TRX-DOESNOTEXIST',
            'otp' => self::OTP,
        ]);

        $response->assertNotFound()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('remark', 'otp_verified')
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function test_too_many_invalid_otp_attempts_fail_the_authorized_transaction(): void
    {
        $user = $this->makeUser();
        $txn = $this->makePendingTransaction($user);
        $txn->update(['trx' => 'TRX-OTPFAILS1']);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson(self::ROUTE, [
                'trx' => 'TRX-OTPFAILS1',
                'otp' => '654321',
            ])->assertStatus(422);
        }

        $fifth = $this->postJson(self::ROUTE, [
            'trx' => 'TRX-OTPFAILS1',
            'otp' => '654321',
        ]);

        $fifth->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message.0', 'Verification attempt limit exceeded. This transaction has been cancelled.');

        $this->assertDatabaseHas('authorized_transactions', [
            'id'                    => $txn->id,
            'status'                => AuthorizedTransaction::STATUS_FAILED,
            'verification_failures' => 5,
            'failure_reason'        => 'Verification attempt limit exceeded.',
        ]);
    }
}
