<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\VerificationProcess;

use App\Domain\AuthorizedTransaction\Exceptions\TransactionNotFoundException;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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
            'trx'               => 'TRX-OTPTEST-' . Str::upper((string) Str::ulid()),
            'payload'           => [
                'from_account_uuid' => '00000000-0000-0000-0000-000000000001',
                'to_account_uuid'   => '00000000-0000-0000-0000-000000000002',
                'amount'            => '10.00',
                'asset_code'        => 'SZL',
            ],
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
            'otp_sent_at'       => now(),
            'otp_expires_at'    => now()->addMinutes(10),
            'expires_at'        => now()->addHour(),
        ]);
    }

    #[Test]
    public function test_valid_otp_returns_200_and_completes_transaction(): void
    {
        $user = $this->makeUser();
        $txn = $this->makePendingTransaction($user);

        $this->mock(AuthorizedTransactionManager::class, function ($mock) use ($txn): void {
            $mock->shouldReceive('verifyOtp')
                ->once()
                ->with($txn->trx, $txn->user_id, self::OTP)
                ->andReturnUsing(function () use ($txn): array {
                    $result = [
                        'trx' => $txn->trx,
                        'amount' => '10.00',
                        'asset_code' => 'SZL',
                        'reference' => 'mock-transfer-id',
                    ];

                    $txn->forceFill([
                        'status' => AuthorizedTransaction::STATUS_COMPLETED,
                        'result' => $result,
                    ])->save();

                    return $result;
                });
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

        $this->mock(AuthorizedTransactionManager::class, function ($mock) use ($txn): void {
            $mock->shouldReceive('verifyOtp')
                ->once()
                ->with($txn->trx, $txn->user_id, '654321')
                ->andThrow(new RuntimeException('Invalid OTP.'));
        });

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
    public function test_expired_otp_returns_422_and_expires_the_authorized_transaction(): void
    {
        $user = $this->makeUser();
        $txn = $this->makePendingTransaction($user);
        $txn->update([
            'trx'            => 'TRX-OTPEXPIRED1',
            'otp_expires_at' => now()->subMinute(),
        ]);

        $this->mock(AuthorizedTransactionManager::class, function ($mock) use ($txn): void {
            $mock->shouldReceive('verifyOtp')
                ->once()
                ->with('TRX-OTPEXPIRED1', $txn->user_id, self::OTP)
                ->andReturnUsing(function () use ($txn): never {
                    $txn->forceFill([
                        'status' => AuthorizedTransaction::STATUS_EXPIRED,
                        'failure_reason' => 'OTP has expired. Please request a new one.',
                    ])->save();

                    throw new RuntimeException('OTP has expired. Please request a new one.');
                });
        });

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => 'TRX-OTPEXPIRED1',
            'otp' => self::OTP,
        ]);

        $response->assertStatus(422)
            ->assertExactJson([
                'status'  => 'error',
                'remark'  => 'otp_verified',
                'message' => ['OTP has expired. Please request a new one.'],
                'data'    => null,
            ]);

        $this->assertDatabaseHas('authorized_transactions', [
            'id'            => $txn->id,
            'status'        => AuthorizedTransaction::STATUS_EXPIRED,
            'failure_reason'=> 'OTP has expired. Please request a new one.',
        ]);
    }

    #[Test]
    public function test_unknown_trx_returns_404_error_envelope(): void
    {
        $user = $this->makeUser();

        $this->mock(AuthorizedTransactionManager::class, function ($mock) use ($user): void {
            $mock->shouldReceive('verifyOtp')
                ->once()
                ->with('TRX-DOESNOTEXIST', $user->id, self::OTP)
                ->andThrow(new TransactionNotFoundException('Transaction not found.'));
        });

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

        $attempt = 0;
        $this->mock(AuthorizedTransactionManager::class, function ($mock) use ($txn, &$attempt): void {
            $mock->shouldReceive('verifyOtp')
                ->times(5)
                ->andReturnUsing(function () use ($txn, &$attempt): never {
                    $attempt++;
                    $txn->forceFill([
                        'verification_failures' => $attempt,
                        'status' => $attempt >= 5 ? AuthorizedTransaction::STATUS_FAILED : AuthorizedTransaction::STATUS_PENDING,
                        'failure_reason' => $attempt >= 5 ? 'Verification attempt limit exceeded.' : 'Invalid OTP.',
                    ])->save();

                    throw new RuntimeException(
                        $attempt >= 5
                            ? 'Verification attempt limit exceeded. This transaction has been cancelled.'
                            : 'Invalid OTP.'
                    );
                });
        });

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

    #[Test]
    public function test_invalid_otp_format_returns_compatibility_error_envelope(): void
    {
        $user = $this->makeUser();
        $txn = $this->makePendingTransaction($user);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => $txn->trx,
            'otp' => '123',
            'remark' => 'send_money',
        ]);

        $response->assertStatus(422)
            ->assertExactJson([
                'status' => 'error',
                'remark' => 'send_money',
                'message' => ['The otp field must be 6 digits.'],
                'data' => null,
            ]);
    }
}
