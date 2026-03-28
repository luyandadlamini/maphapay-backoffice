<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\ScheduledSend;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\ScheduledSend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class ScheduledSendCancelControllerTest extends ControllerTestCase
{
    #[Test]
    public function test_cancel_sets_status_cancelled_for_sender(): void
    {
        config([
            'maphapay_migration.enable_scheduled_send' => true,
        ]);

        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $id = (string) Str::uuid();
        ScheduledSend::query()->create([
            'id'                => $id,
            'sender_user_id'    => $sender->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '5.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'scheduled_for'     => Carbon::now()->addWeek(),
            'status'            => ScheduledSend::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/scheduled-send/cancel/{$id}");

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'remark' => 'scheduled_send_cancel',
                'data'   => [],
            ]);

        $this->assertDatabaseHas('scheduled_sends', [
            'id'     => $id,
            'status' => ScheduledSend::STATUS_CANCELLED,
        ]);
    }

    #[Test]
    public function test_cancel_rejects_non_sender(): void
    {
        config([
            'maphapay_migration.enable_scheduled_send' => true,
        ]);

        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $other = User::factory()->create();

        $id = (string) Str::uuid();
        ScheduledSend::query()->create([
            'id'                => $id,
            'sender_user_id'    => $sender->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '5.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'scheduled_for'     => Carbon::now()->addWeek(),
            'status'            => ScheduledSend::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($other, ['read', 'write', 'delete']);

        $this->postJson("/api/scheduled-send/cancel/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('remark', 'scheduled_send_cancel');
    }

    #[Test]
    public function test_cancel_rejects_already_cancelled_send(): void
    {
        config(['maphapay_migration.enable_scheduled_send' => true]);

        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $id = (string) Str::uuid();
        ScheduledSend::query()->create([
            'id'                => $id,
            'sender_user_id'    => $sender->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '5.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'scheduled_for'     => Carbon::now()->addWeek(),
            'status'            => ScheduledSend::STATUS_CANCELLED,
            'trx'               => null,
        ]);

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        $this->postJson("/api/scheduled-send/cancel/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('remark', 'scheduled_send_cancel')
            ->assertJsonPath('message.0', 'This scheduled send is not pending.');
    }

    #[Test]
    public function test_cancel_propagates_to_pending_authorized_transaction(): void
    {
        config(['maphapay_migration.enable_scheduled_send' => true]);

        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $trx = 'TRX-TESTCANCEL';
        $id = (string) Str::uuid();

        ScheduledSend::query()->create([
            'id'                => $id,
            'sender_user_id'    => $sender->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '5.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'scheduled_for'     => Carbon::now()->addWeek(),
            'status'            => ScheduledSend::STATUS_PENDING,
            'trx'               => $trx,
        ]);

        AuthorizedTransaction::create([
            'user_id'           => $sender->id,
            'remark'            => AuthorizedTransaction::REMARK_SCHEDULED_SEND,
            'trx'               => $trx,
            'payload'           => ['scheduled_send_id' => $id],
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
            'expires_at'        => now()->addHour(),
        ]);

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        $this->postJson("/api/scheduled-send/cancel/{$id}")->assertOk();

        $this->assertDatabaseHas('scheduled_sends', [
            'id'     => $id,
            'status' => ScheduledSend::STATUS_CANCELLED,
        ]);

        $this->assertDatabaseHas('authorized_transactions', [
            'trx'    => $trx,
            'status' => AuthorizedTransaction::STATUS_CANCELLED,
        ]);
    }

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config([
            'maphapay_migration.enable_scheduled_send' => false,
        ]);

        $id = (string) Str::uuid();
        Sanctum::actingAs(User::factory()->create(), ['read', 'write', 'delete']);

        $this->postJson("/api/scheduled-send/cancel/{$id}")->assertNotFound();
    }
}
