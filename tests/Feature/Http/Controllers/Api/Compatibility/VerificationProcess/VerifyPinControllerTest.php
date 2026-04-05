<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\VerificationProcess;

use App\Domain\AuthorizedTransaction\Exceptions\InvalidTransactionPinException;
use App\Domain\AuthorizedTransaction\Exceptions\TransactionNotFoundException;
use App\Domain\AuthorizedTransaction\Exceptions\TransactionPinNotSetException;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\ControllerTestCase;

#[Large]
class VerifyPinControllerTest extends ControllerTestCase
{
    private const PIN = '1234';

    private const ROUTE = '/api/verification-process/verify/pin';

    protected function setUp(): void
    {
        parent::setUp();

        config(['maphapay_migration.enable_verification' => true]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeUserWithPin(): User
    {
        return User::factory()->create([
            'transaction_pin' => self::PIN,
        ]);
    }

    private function makeUserWithoutPin(): User
    {
        return User::factory()->create([
            'transaction_pin' => null,
        ]);
    }

    private function makePendingTransaction(User $user): AuthorizedTransaction
    {
        return AuthorizedTransaction::create([
            'user_id' => $user->id,
            'remark'  => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx'     => 'TRX-PINTEST-' . Str::upper((string) Str::ulid()),
            'payload' => [
                'from_account_uuid' => '00000000-0000-0000-0000-000000000001',
                'to_account_uuid'   => '00000000-0000-0000-0000-000000000002',
                'amount'            => '10.00',
                'asset_code'        => 'SZL',
            ],
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_PIN,
            'expires_at'        => now()->addHour(),
        ]);
    }

    // ── Test cases ───────────────────────────────────────────────────────────

    #[Test]
    public function test_valid_pin_returns_200_and_completes_transaction(): void
    {
        $user = $this->makeUserWithPin();
        $txn = $this->makePendingTransaction($user);

        $this->mock(AuthorizedTransactionManager::class, function ($mock) use ($txn): void {
            $mock->shouldReceive('verifyPin')
                ->once()
                ->with($txn->trx, $txn->user_id, self::PIN)
                ->andReturnUsing(function () use ($txn): array {
                    $result = [
                        'trx'        => $txn->trx,
                        'amount'     => '10.00',
                        'asset_code' => 'SZL',
                        'reference'  => 'mock-transfer-id',
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
            'pin'    => self::PIN,
            'remark' => 'send_money',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('remark', 'send_money')
            ->assertJsonStructure(['data' => ['trx', 'amount', 'asset_code', 'reference']]);

        $this->assertDatabaseHas('authorized_transactions', [
            'id'     => $txn->id,
            'status' => AuthorizedTransaction::STATUS_COMPLETED,
        ]);
    }

    #[Test]
    public function test_wrong_pin_returns_422_with_error_envelope(): void
    {
        $user = $this->makeUserWithPin();
        $txn = $this->makePendingTransaction($user);

        $this->mock(AuthorizedTransactionManager::class, function ($mock) use ($txn): void {
            $mock->shouldReceive('verifyPin')
                ->once()
                ->with($txn->trx, $txn->user_id, '0000')
                ->andThrow(new InvalidTransactionPinException('Invalid transaction PIN.'));
        });

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => $txn->trx,
            'pin' => '0000',
        ]);

        $response->assertStatus(422)
            ->assertExactJson([
                'status'  => 'error',
                'remark'  => 'pin_verified',
                'message' => ['Invalid transaction PIN.'],
                'data'    => null,
            ]);

        $this->assertSame(
            1,
            (int) Cache::get(MaphaPayMoneyMovementTelemetry::METRIC_VERIFICATION_FAILURES_TOTAL, 0),
        );
    }

    #[Test]
    public function test_invalid_pin_format_returns_compatibility_error_envelope(): void
    {
        $user = $this->makeUserWithPin();
        $txn = $this->makePendingTransaction($user);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx'    => $txn->trx,
            'pin'    => '12',
            'remark' => 'send_money',
        ]);

        $response->assertStatus(422)
            ->assertExactJson([
                'status'  => 'error',
                'remark'  => 'send_money',
                'message' => ['The pin field must be 4 digits.'],
                'data'    => null,
            ]);
    }

    #[Test]
    public function test_pin_not_set_returns_422_with_descriptive_message(): void
    {
        $user = $this->makeUserWithoutPin();
        $txn = $this->makePendingTransaction($user);
        $txn->update(['trx' => 'TRX-NOPINTEST']);

        $this->mock(AuthorizedTransactionManager::class, function ($mock) use ($txn): void {
            $mock->shouldReceive('verifyPin')
                ->once()
                ->with('TRX-NOPINTEST', $txn->user_id, self::PIN)
                ->andThrow(new TransactionPinNotSetException('Transaction PIN has not been set for this account.'));
        });

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => 'TRX-NOPINTEST',
            'pin' => self::PIN,
        ]);

        $response->assertStatus(422)
            ->assertExactJson([
                'status'  => 'error',
                'remark'  => 'pin_verified',
                'message' => ['Transaction PIN has not been set for this account.'],
                'data'    => null,
            ]);
    }

    #[Test]
    public function test_unknown_trx_returns_404(): void
    {
        $user = $this->makeUserWithPin();

        $this->mock(AuthorizedTransactionManager::class, function ($mock) use ($user): void {
            $mock->shouldReceive('verifyPin')
                ->once()
                ->with('TRX-DOESNOTEXIST', $user->id, self::PIN)
                ->andThrow(new TransactionNotFoundException('Transaction not found.'));
        });

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => 'TRX-DOESNOTEXIST',
            'pin' => self::PIN,
        ]);

        $response->assertNotFound()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function test_too_many_invalid_pin_attempts_fail_the_authorized_transaction(): void
    {
        $user = $this->makeUserWithPin();
        $txn = $this->makePendingTransaction($user);

        $attempt = 0;
        $this->mock(AuthorizedTransactionManager::class, function ($mock) use ($txn, &$attempt): void {
            $mock->shouldReceive('verifyPin')
                ->times(5)
                ->andReturnUsing(function () use ($txn, &$attempt): never {
                    $attempt++;
                    $txn->forceFill([
                        'verification_failures' => $attempt,
                        'status'                => $attempt >= 5 ? AuthorizedTransaction::STATUS_FAILED : AuthorizedTransaction::STATUS_PENDING,
                        'failure_reason'        => $attempt >= 5 ? 'Verification attempt limit exceeded.' : 'Invalid transaction PIN.',
                    ])->save();

                    throw new RuntimeException(
                        $attempt >= 5
                            ? 'Verification attempt limit exceeded. This transaction has been cancelled.'
                            : 'Invalid transaction PIN.'
                    );
                });
        });

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson(self::ROUTE, [
                'trx' => $txn->trx,
                'pin' => '0000',
            ])->assertStatus(422);
        }

        $fifth = $this->postJson(self::ROUTE, [
            'trx' => $txn->trx,
            'pin' => '0000',
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
    public function test_already_completed_transaction_returns_422(): void
    {
        $user = $this->makeUserWithPin();

        $txn = AuthorizedTransaction::create([
            'user_id'           => $user->id,
            'remark'            => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx'               => 'TRX-COMPLETED-' . Str::upper((string) Str::ulid()),
            'payload'           => ['amount' => '5.00'],
            'status'            => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_PIN,
            'expires_at'        => now()->addHour(),
        ]);

        $this->mock(AuthorizedTransactionManager::class, function ($mock) use ($txn): void {
            $mock->shouldReceive('verifyPin')
                ->once()
                ->with($txn->trx, $txn->user_id, self::PIN)
                ->andThrow(new RuntimeException('Transaction is no longer pending.'));
        });

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => $txn->trx,
            'pin' => self::PIN,
        ]);

        $response->assertStatus(422);
    }
}
