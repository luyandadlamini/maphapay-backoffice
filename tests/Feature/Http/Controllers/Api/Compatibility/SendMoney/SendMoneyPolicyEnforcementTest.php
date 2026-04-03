<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\SendMoney;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class SendMoneyPolicyEnforcementTest extends ControllerTestCase
{
    private User $sender;

    private User $recipient;

    private Account $senderAccount;

    private Account $recipientAccount;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maphapay_migration.enable_send_money' => true,
            'maphapay_migration.money_movement.send_money.step_up_threshold' => '100.00',
        ]);

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name' => 'Swazi Lilangeni',
                'type' => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        $this->sender = User::factory()->create(['kyc_status' => 'approved', 'transaction_pin' => null]);
        $this->recipient = User::factory()->create(['kyc_status' => 'approved']);
        $this->senderAccount = Account::factory()->create([
            'user_uuid' => $this->sender->uuid,
            'frozen' => false,
        ]);
        $this->recipientAccount = Account::factory()->create([
            'user_uuid' => $this->recipient->uuid,
            'frozen' => false,
        ]);

        AccountBalance::query()->updateOrCreate(
            ['account_uuid' => $this->senderAccount->uuid, 'asset_code' => 'SZL'],
            ['balance' => 500_000],
        );
        AccountBalance::query()->updateOrCreate(
            ['account_uuid' => $this->recipientAccount->uuid, 'asset_code' => 'SZL'],
            ['balance' => 0],
        );
    }

    #[Test]
    public function it_finalizes_low_risk_send_money_synchronously_when_policy_allows_none(): void
    {
        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000009901',
        ])->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '10.00',
            'verification_type' => 'sms',
            'note' => 'Lunch split',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.next_step', 'none');

        $trx = $response->json('data.trx');
        $reference = $response->json('data.reference');

        $this->assertIsString($trx);
        $this->assertIsString($reference);

        $this->assertDatabaseHas('authorized_transactions', [
            'trx' => $trx,
            'status' => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
        ]);
        $this->assertDatabaseHas('asset_transfers', [
            'reference' => $reference,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('account_balances', [
            'account_uuid' => $this->senderAccount->uuid,
            'asset_code' => 'SZL',
            'balance' => 499_000,
        ]);
        $this->assertDatabaseHas('account_balances', [
            'account_uuid' => $this->recipientAccount->uuid,
            'asset_code' => 'SZL',
            'balance' => 1_000,
        ]);
    }

    #[Test]
    public function it_ignores_the_client_none_hint_and_requires_otp_when_policy_steps_up(): void
    {
        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000009902',
        ])->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '150.00',
            'verification_type' => 'none',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'otp');

        $trx = (string) $response->json('data.trx');
        $this->assertDatabaseHas('authorized_transactions', [
            'trx' => $trx,
            'status' => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
        ]);
        $this->assertDatabaseMissing('asset_transfers', [
            'reference' => $trx,
        ]);
    }

    #[Test]
    public function it_uses_pin_for_low_risk_send_money_when_the_sender_has_a_transaction_pin(): void
    {
        $this->sender->update(['transaction_pin' => '1234']);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user' => $this->recipient->email,
            'amount' => '25.00',
            'verification_type' => 'none',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'pin')
            ->assertJsonPath('data.code_sent_message', null);
    }
}
