<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\VerificationProcess;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

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
            'trx'     => 'TRX-PINTEST1',
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

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $txn = $this->makePendingTransaction($user);

        $this->mock(WalletOperationsService::class, function ($mock): void {
            $mock->shouldReceive('transfer')->once()->andReturn('mock-transfer-id');
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
    public function test_wrong_pin_returns_422_with_status_false_envelope(): void
    {
        $user = $this->makeUserWithPin();
        $txn = $this->makePendingTransaction($user);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => $txn->trx,
            'pin' => '0000',
        ]);

        $response->assertStatus(422)
            ->assertExactJson([
                'status'  => false,
                'message' => 'Invalid transaction PIN.',
                'data'    => null,
            ]);
    }

    #[Test]
    public function test_pin_not_set_returns_422_with_descriptive_message(): void
    {
        $user = $this->makeUserWithoutPin();
        $txn = $this->makePendingTransaction($user);
        $txn->update(['trx' => 'TRX-NOPINTEST']);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => 'TRX-NOPINTEST',
            'pin' => self::PIN,
        ]);

        $response->assertStatus(422)
            ->assertExactJson([
                'status'  => false,
                'message' => 'Transaction PIN has not been set for this account.',
                'data'    => null,
            ]);
    }

    #[Test]
    public function test_unknown_trx_returns_404(): void
    {
        $user = $this->makeUserWithPin();

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => 'TRX-DOESNOTEXIST',
            'pin' => self::PIN,
        ]);

        $response->assertNotFound()
            ->assertJsonPath('status', false)
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function test_already_completed_transaction_returns_422(): void
    {
        $user = $this->makeUserWithPin();

        $txn = AuthorizedTransaction::create([
            'user_id'           => $user->id,
            'remark'            => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx'               => 'TRX-COMPLETED1',
            'payload'           => ['amount' => '5.00'],
            'status'            => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_PIN,
            'expires_at'        => now()->addHour(),
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'trx' => $txn->trx,
            'pin' => self::PIN,
        ]);

        $response->assertStatus(422);
    }
}
