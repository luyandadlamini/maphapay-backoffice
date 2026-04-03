<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\AuthorizedTransaction\Handlers;

use App\Domain\AuthorizedTransaction\Handlers\RequestMoneyHandler;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\MoneyRequest;
use App\Models\User;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class RequestMoneyHandlerTest extends DomainTestCase
{
    private RequestMoneyHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = app(RequestMoneyHandler::class);
    }

    #[Test]
    public function it_transitions_money_request_from_awaiting_otp_to_pending(): void
    {
        $user = User::factory()->create();

        $moneyRequest = MoneyRequest::query()->create([
            'id'                => (string) \Illuminate\Support\Str::uuid(),
            'requester_user_id' => $user->id,
            'recipient_user_id' => User::factory()->create()->id,
            'amount'            => '25.10',
            'asset_code'        => 'SZL',
            'status'            => MoneyRequest::STATUS_AWAITING_OTP,
        ]);

        $txn = AuthorizedTransaction::query()->create([
            'user_id'           => $user->id,
            'remark'            => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'trx'               => 'TRX-TEST-001',
            'payload'           => ['money_request_id' => $moneyRequest->id],
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
        ]);

        $result = $this->handler->handle($txn);

        $this->assertSame('TRX-TEST-001', $result['trx']);
        $this->assertSame('25.10', $result['amount']);
        $this->assertSame('SZL', $result['asset_code']);
        $this->assertSame($moneyRequest->id, $result['money_request_id']);

        $this->assertDatabaseHas('money_requests', [
            'id'     => $moneyRequest->id,
            'status' => MoneyRequest::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function it_throws_when_money_request_id_is_missing_from_payload(): void
    {
        $user = User::factory()->create();

        $txn = AuthorizedTransaction::query()->create([
            'user_id'           => $user->id,
            'remark'            => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'trx'               => 'TRX-TEST-002',
            'payload'           => [],
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing money_request_id/');

        $this->handler->handle($txn);
    }

    #[Test]
    public function it_throws_when_requester_does_not_match_transaction_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $moneyRequest = MoneyRequest::query()->create([
            'id'                => (string) \Illuminate\Support\Str::uuid(),
            'requester_user_id' => $owner->id,
            'recipient_user_id' => User::factory()->create()->id,
            'amount'            => '10.00',
            'asset_code'        => 'SZL',
            'status'            => MoneyRequest::STATUS_AWAITING_OTP,
        ]);

        $txn = AuthorizedTransaction::query()->create([
            'user_id'           => $otherUser->id,
            'remark'            => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'trx'               => 'TRX-TEST-003',
            'payload'           => ['money_request_id' => $moneyRequest->id],
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requester mismatch/');

        $this->handler->handle($txn);
    }

    #[Test]
    public function it_throws_when_money_request_is_not_in_awaiting_otp_state(): void
    {
        $user = User::factory()->create();

        $moneyRequest = MoneyRequest::query()->create([
            'id'                => (string) \Illuminate\Support\Str::uuid(),
            'requester_user_id' => $user->id,
            'recipient_user_id' => User::factory()->create()->id,
            'amount'            => '10.00',
            'asset_code'        => 'SZL',
            'status'            => MoneyRequest::STATUS_PENDING,
        ]);

        $txn = AuthorizedTransaction::query()->create([
            'user_id'           => $user->id,
            'remark'            => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'trx'               => 'TRX-TEST-004',
            'payload'           => ['money_request_id' => $moneyRequest->id],
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid money request state/');

        $this->handler->handle($txn);
    }

    #[Test]
    public function it_links_a_chat_request_message_when_chat_context_is_present(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create();

        $moneyRequest = MoneyRequest::query()->create([
            'id'                => (string) \Illuminate\Support\Str::uuid(),
            'requester_user_id' => $user->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => '10.00',
            'asset_code'        => 'SZL',
            'note'              => 'Dinner',
            'status'            => MoneyRequest::STATUS_AWAITING_OTP,
        ]);

        $txn = AuthorizedTransaction::query()->create([
            'user_id'           => $user->id,
            'remark'            => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'trx'               => 'TRX-TEST-005',
            'payload'           => [
                'money_request_id' => $moneyRequest->id,
                'chat_friend_id' => $recipient->id,
            ],
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
        ]);

        $result = $this->handler->handle($txn);

        $this->assertSame($moneyRequest->id, $result['money_request_id']);
        $this->assertTrue($result['chat_linked'] ?? false);
        $this->assertIsInt($result['chat_message_id'] ?? null);
    }
}
