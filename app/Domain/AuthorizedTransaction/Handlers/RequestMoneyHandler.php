<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Handlers;

use App\Domain\AuthorizedTransaction\Contracts\AuthorizedTransactionHandlerInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\MoneyRequest;
use InvalidArgumentException;

/**
 * After OTP/PIN verification, promotes a money request from awaiting_otp to pending
 * (visible to recipient). No wallet movement.
 */
class RequestMoneyHandler implements AuthorizedTransactionHandlerInterface
{
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

        if ($moneyRequest->status !== MoneyRequest::STATUS_AWAITING_OTP) {
            throw new InvalidArgumentException('RequestMoneyHandler: invalid money request state.');
        }

        $moneyRequest->update([
            'status' => MoneyRequest::STATUS_PENDING,
        ]);

        return [
            'trx'        => $transaction->trx,
            'amount'     => $moneyRequest->amount,
            'asset_code' => $moneyRequest->asset_code,
        ];
    }
}
