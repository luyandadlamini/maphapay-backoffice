<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\SendMoney;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class SendMoneyStoreControllerTest extends ControllerTestCase
{
    private User $sender;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = User::factory()->create();
        $this->recipient = User::factory()->create();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        Account::factory()->create([
            'user_uuid' => $this->sender->uuid,
            'frozen'    => false,
        ]);

        Account::factory()->create([
            'user_uuid' => $this->recipient->uuid,
            'frozen'    => false,
        ]);
    }

    #[Test]
    public function test_store_returns_success_envelope_with_otp_flow(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '10.50',
            'verification_type' => 'sms',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'remark' => 'send_money',
            ])
            ->assertJsonPath('data.next_step', 'otp')
            ->assertJsonStructure([
                'data' => [
                    'trx',
                    'code_sent_message',
                ],
            ]);

        $trx = $response->json('data.trx');
        $this->assertIsString($trx);
        $this->assertStringStartsWith('TRX-', $trx);

        $this->assertDatabaseHas('authorized_transactions', [
            'trx'     => $trx,
            'remark'  => AuthorizedTransaction::REMARK_SEND_MONEY,
            'user_id' => $this->sender->id,
        ]);
    }

    #[Test]
    public function test_store_pin_flow_omits_code_sent_message(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '1.00',
            'verification_type' => 'pin',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'pin')
            ->assertJsonPath('data.code_sent_message', null);
    }

    #[Test]
    public function test_store_rejects_non_string_amount(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user'   => $this->recipient->email,
            'amount' => 10.5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config([
            'maphapay_migration.enable_send_money' => false,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $this->postJson('/api/send-money/store', [
            'user'   => $this->recipient->email,
            'amount' => '10.00',
        ])->assertNotFound();
    }
}
