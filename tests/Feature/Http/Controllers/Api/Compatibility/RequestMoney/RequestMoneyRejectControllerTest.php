<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\RequestMoney;

use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class RequestMoneyRejectControllerTest extends ControllerTestCase
{
    private User $requester;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requester = User::factory()->create();
        $this->recipient = User::factory()->create();
    }

    #[Test]
    public function test_reject_marks_request_rejected(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '5.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/reject/{$moneyRequestId}")
            ->assertOk()
            ->assertJson([
                'status' => 'success',
                'remark' => 'request_money_reject',
                'data'   => [],
            ]);

        $this->assertDatabaseHas('money_requests', [
            'id'     => $moneyRequestId,
            'status' => MoneyRequest::STATUS_REJECTED,
        ]);
    }

    #[Test]
    public function test_reject_fails_when_not_recipient(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $other = User::factory()->create();

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '5.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($other, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/reject/{$moneyRequestId}")
            ->assertStatus(422);
    }

    #[Test]
    public function test_reject_fails_when_already_rejected(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '5.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_REJECTED,
            'trx'               => null,
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/reject/{$moneyRequestId}")
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config([
            'maphapay_migration.enable_request_money' => false,
        ]);

        $moneyRequestId = (string) Str::uuid();

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/reject/{$moneyRequestId}")
            ->assertNotFound();
    }
}
