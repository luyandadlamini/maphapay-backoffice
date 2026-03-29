<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\RequestMoney;

use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class RequestMoneyHistoryControllerTest extends ControllerTestCase
{
    #[Test]
    public function test_history_returns_requester_requests_paginated(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $requester = User::factory()->create(['kyc_status' => 'approved']);
        $recipient = User::factory()->create(['kyc_status' => 'approved']);

        $idA = (string) Str::uuid();
        $idB = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $idA,
            'requester_user_id' => $requester->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '1.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => null,
        ]);
        MoneyRequest::query()->create([
            'id'                => $idB,
            'requester_user_id' => $requester->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '2.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($requester, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/request-money/history?page=1');

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'remark' => 'request_money_history',
            ])
            ->assertJsonStructure([
                'data' => [
                    'request_moneys' => [
                        'data',
                        'current_page',
                        'last_page',
                        'total',
                    ],
                ],
            ]);

        $data = $response->json('data.request_moneys.data');
        $this->assertCount(2, $data);
        $this->assertSame(2, $response->json('data.request_moneys.total'));
    }

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config([
            'maphapay_migration.enable_request_money' => false,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $this->getJson('/api/request-money/history')
            ->assertNotFound();
    }
}
