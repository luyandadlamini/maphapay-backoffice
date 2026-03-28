<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\RequestMoney;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\MoneyRequest;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class RequestMoneyStoreControllerTest extends ControllerTestCase
{
    private User $requester;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requester = User::factory()->create();
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
    }

    #[Test]
    public function test_store_creates_money_request_and_returns_envelope(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/request-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '25.00',
            'note'              => 'Lunch',
            'verification_type' => 'sms',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'remark' => 'request_money',
            ])
            ->assertJsonPath('data.next_step', 'otp')
            ->assertJsonStructure([
                'data' => [
                    'trx',
                ],
            ]);

        $trx = $response->json('data.trx');
        $this->assertIsString($trx);

        $this->assertDatabaseHas('authorized_transactions', [
            'trx'     => $trx,
            'remark'  => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'user_id' => $this->requester->id,
        ]);

        $this->assertDatabaseHas('money_requests', [
            'trx'               => $trx,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'status'            => MoneyRequest::STATUS_AWAITING_OTP,
            'amount'            => '25.00',
        ]);
    }

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config([
            'maphapay_migration.enable_request_money' => false,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $this->postJson('/api/request-money/store', [
            'user'   => $this->recipient->email,
            'amount' => '10.00',
        ])->assertNotFound();
    }
}
