<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\ScheduledSend;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\ScheduledSend;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class ScheduledSendStoreControllerTest extends ControllerTestCase
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
            'maphapay_migration.enable_scheduled_send' => true,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $scheduledFor = Carbon::now()->addDay()->toIso8601String();

        $response = $this->postJson('/api/scheduled-send/store', [
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '10.50',
            'scheduled_for'     => $scheduledFor,
            'verification_type' => 'sms',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'remark' => 'scheduled_send',
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
            'remark'  => AuthorizedTransaction::REMARK_SCHEDULED_SEND,
            'user_id' => $this->sender->id,
        ]);

        $this->assertDatabaseHas('scheduled_sends', [
            'sender_user_id'    => $this->sender->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '10.50',
            'asset_code'        => 'SZL',
            'status'            => ScheduledSend::STATUS_PENDING,
            'trx'               => $trx,
        ]);
    }

    #[Test]
    public function test_store_rejects_self_send(): void
    {
        config(['maphapay_migration.enable_scheduled_send' => true]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/scheduled-send/store', [
            'recipient_user_id' => $this->sender->id,
            'amount'            => '10.00',
            'scheduled_for'     => Carbon::now()->addDay()->toIso8601String(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('remark', 'scheduled_send')
            ->assertJsonPath('message.0', 'You cannot schedule a send to yourself.');
    }

    #[Test]
    public function test_store_rejects_frozen_sender_account(): void
    {
        config(['maphapay_migration.enable_scheduled_send' => true]);

        Account::query()
            ->where('user_uuid', $this->sender->uuid)
            ->update(['frozen' => true]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/scheduled-send/store', [
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '10.00',
            'scheduled_for'     => Carbon::now()->addDay()->toIso8601String(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('remark', 'scheduled_send')
            ->assertJsonPath('message.0', 'Sender wallet account not found or is frozen.');
    }

    #[Test]
    public function test_store_rejects_scheduled_for_more_than_one_year_ahead(): void
    {
        config(['maphapay_migration.enable_scheduled_send' => true]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/scheduled-send/store', [
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '1.00',
            'scheduled_for'     => Carbon::now()->addYears(2)->toIso8601String(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_for']);
    }

    #[Test]
    public function test_store_rejects_past_scheduled_for(): void
    {
        config([
            'maphapay_migration.enable_scheduled_send' => true,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/scheduled-send/store', [
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '1.00',
            'scheduled_for'     => Carbon::now()->subHour()->toIso8601String(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_for']);
    }

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config([
            'maphapay_migration.enable_scheduled_send' => false,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $this->postJson('/api/scheduled-send/store', [
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '10.00',
            'scheduled_for'     => Carbon::now()->addDay()->toIso8601String(),
        ])->assertNotFound();
    }
}
