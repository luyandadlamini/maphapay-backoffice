<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Handlers;

use App\Domain\AuthorizedTransaction\Contracts\AuthorizedTransactionHandlerInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Domain\SocialMoney\Services\SocialRequestMessageService;
use App\Models\MoneyRequest;
use InvalidArgumentException;

/**
 * Finalizes request creation metadata. New requests are already pending; legacy
 * rows may still need promotion from awaiting_otp.
 */
class RequestMoneyHandler implements AuthorizedTransactionHandlerInterface
{
    public function __construct(
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
        private readonly SocialRequestMessageService $requestMessageService,
    ) {}

    public function handle(AuthorizedTransaction $transaction): array
    {
        $payload = $transaction->payload;
        $moneyRequestId = $payload['money_request_id'] ?? null;

        if (! is_string($moneyRequestId) || $moneyRequestId === '') {
            throw new InvalidArgumentException('RequestMoneyHandler: missing money_request_id in payload.');
        }

        /** @var MoneyRequest $moneyRequest */
        $moneyRequest = MoneyRequest::query()->whereKey($moneyRequestId)->firstOrFail();

        if ((int) $moneyRequest->requester_user_id !== (int) $transaction->user_id) {
            throw new InvalidArgumentException('RequestMoneyHandler: requester mismatch.');
        }

        if (! in_array($moneyRequest->status, [MoneyRequest::STATUS_PENDING, MoneyRequest::STATUS_AWAITING_OTP], true)) {
            throw new InvalidArgumentException('RequestMoneyHandler: invalid money request state.');
        }

        $fromStatus = $moneyRequest->status;
        if ($moneyRequest->status === MoneyRequest::STATUS_AWAITING_OTP) {
            $moneyRequest->update([
                'status' => MoneyRequest::STATUS_PENDING,
            ]);
            $moneyRequest->refresh();
        }

        $chatMessageId = null;
        $chatFriendId = $payload['chat_friend_id'] ?? null;
        if (is_numeric($chatFriendId)) {
            $chatMessageId = $this->requestMessageService->ensureForMoneyRequest(
                $moneyRequest,
                (int) $transaction->user_id,
                (int) $chatFriendId,
            );
        }

        if ($fromStatus !== MoneyRequest::STATUS_PENDING) {
            $this->telemetry->logMoneyRequestTransition($moneyRequest, $fromStatus, MoneyRequest::STATUS_PENDING, [
                'remark' => $transaction->remark,
                'authorized_transaction_trx' => $transaction->trx,
            ]);
        }

        $result = [
            'trx' => $transaction->trx,
            'amount' => $moneyRequest->amount,
            'asset_code' => $moneyRequest->asset_code,
            'money_request_id' => (string) $moneyRequest->id,
        ];

        if ($chatMessageId !== null) {
            $result['chat_message_id'] = $chatMessageId;
            $result['chat_linked'] = true;
        }

        return $result;
    }
}
