<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\ScheduledSend;

use App\Models\ScheduledSend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class ScheduledSendIndexControllerTest extends ControllerTestCase
{
    #[Test]
    public function test_index_returns_sender_scheduled_sends_paginated(): void
    {
        config([
            'maphapay_migration.enable_scheduled_send' => true,
        ]);

        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $idA = (string) Str::uuid();
        $idB = (string) Str::uuid();
        $when = Carbon::now()->addDays(2);

        ScheduledSend::query()->create([
            'id'                => $idA,
            'sender_user_id'    => $sender->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '1.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'scheduled_for'     => $when->copy()->addDay(),
            'status'            => ScheduledSend::STATUS_PENDING,
            'trx'               => null,
        ]);
        ScheduledSend::query()->create([
            'id'                => $idB,
            'sender_user_id'    => $sender->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '2.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'scheduled_for'     => $when,
            'status'            => ScheduledSend::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/scheduled-send/index?page=1');

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'remark' => 'scheduled_send_history',
            ])
            ->assertJsonStructure([
                'data' => [
                    'scheduled_sends' => [
                        'data',
                        'current_page',
                        'last_page',
                        'total',
                    ],
                ],
            ]);

        $data = $response->json('data.scheduled_sends.data');
        $this->assertCount(2, $data);
        $this->assertSame(2, $response->json('data.scheduled_sends.total'));
    }

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config([
            'maphapay_migration.enable_scheduled_send' => false,
        ]);

        Sanctum::actingAs(User::factory()->create(), ['read', 'write', 'delete']);

        $this->getJson('/api/scheduled-send/index')->assertNotFound();
    }
}
