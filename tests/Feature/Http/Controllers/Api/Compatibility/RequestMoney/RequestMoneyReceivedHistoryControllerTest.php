<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\RequestMoney;

use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class RequestMoneyReceivedHistoryControllerTest extends ControllerTestCase
{
    #[Test]
    public function test_received_history_returns_recipient_requests_paginated(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $requester = User::factory()->create();
        $recipient = User::factory()->create();

        $idA = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $idA,
            'requester_user_id' => $requester->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '3.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($recipient, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/request-money/received-history?page=1');

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'remark' => 'request_money_received_history',
            ])
            ->assertJsonStructure([
                'data' => [
                    'requested_moneys' => [
                        'data',
                        'current_page',
                        'last_page',
                        'total',
                    ],
                ],
            ]);

        $this->assertCount(1, $response->json('data.requested_moneys.data'));
        $this->assertSame(1, $response->json('data.requested_moneys.total'));
    }

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config([
            'maphapay_migration.enable_request_money' => false,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $this->getJson('/api/request-money/received-history')
            ->assertNotFound();
    }
}
