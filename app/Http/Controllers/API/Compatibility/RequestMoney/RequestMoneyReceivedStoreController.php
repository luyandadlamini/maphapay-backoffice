<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\RequestMoney;

use App\Domain\Account\Models\Account;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Http\Controllers\Controller;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * POST /api/request-money/received-store/{id} — recipient accepts a pending money request (Phase 5).
 */
class RequestMoneyReceivedStoreController extends Controller
{
    public function __construct(
        private readonly AuthorizedTransactionManager $authorizedTransactionManager,
    ) {
    }

    public function __invoke(Request $request, MoneyRequest $moneyRequest): JsonResponse
    {
        $validated = $request->validate([
            'verification_type' => ['sometimes', 'nullable', 'string', Rule::in(['sms', 'email', 'pin', 'none'])],
        ]);

        /** @var User $authUser */
        $authUser = $request->user();

        if ($moneyRequest->status !== MoneyRequest::STATUS_PENDING) {
            return $this->errorResponse('This money request is not pending.', 422);
        }

        if ((int) $moneyRequest->recipient_user_id !== (int) $authUser->getAuthIdentifier()) {
            return $this->errorResponse('You are not the recipient of this money request.', 422);
        }

        if ((int) $moneyRequest->requester_user_id === (int) $authUser->getAuthIdentifier()) {
            return $this->errorResponse('You cannot accept your own money request.', 422);
        }

        $fromAccount = Account::query()
            ->where('user_uuid', $authUser->uuid)
            ->orderBy('id')
            ->first();

        $requester = User::query()->find($moneyRequest->requester_user_id);
        if (! $requester) {
            return $this->errorResponse('Requester account not found.', 422);
        }

        $toAccount = Account::query()
            ->where('user_uuid', $requester->uuid)
            ->orderBy('id')
            ->first();

        if (! $fromAccount || $fromAccount->frozen) {
            return $this->errorResponse('Your wallet account was not found or is frozen.', 422);
        }

        if (! $toAccount) {
            return $this->errorResponse('Requester wallet account not found.', 422);
        }

        if ((float) $moneyRequest->amount <= 0) {
            return $this->errorResponse('This money request has an invalid amount (0).', 422);
        }

        $verificationType = match ($validated['verification_type'] ?? null) {
            'pin'   => AuthorizedTransaction::VERIFICATION_PIN,
            'none'  => AuthorizedTransaction::VERIFICATION_NONE,
            default => AuthorizedTransaction::VERIFICATION_OTP,
        };

        $payload = [
            'money_request_id'  => $moneyRequest->id,
            'requester_user_id' => (int) $moneyRequest->requester_user_id,
            'amount'            => $moneyRequest->amount,
            'asset_code'        => $moneyRequest->asset_code,
            'from_account_uuid' => $fromAccount->uuid,
            'to_account_uuid'   => $toAccount->uuid,
        ];

        $idempotencyKey = (string) $request->header('Idempotency-Key', '')
            ?: (string) $request->header('X-Idempotency-Key', '');

        $codeSentMessage = null;

        $txn = DB::transaction(function () use ($authUser, $payload, $verificationType, $validated, $idempotencyKey, &$codeSentMessage): AuthorizedTransaction|array {
            $txn = $this->authorizedTransactionManager->initiate(
                (int) $authUser->getAuthIdentifier(),
                AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
                $payload,
                $verificationType,
                $idempotencyKey,
            );

            if ($verificationType === AuthorizedTransaction::VERIFICATION_NONE) {
                $result = $this->authorizedTransactionManager->finalize($txn);
                return ['_none_result' => $result];
            }

            if ($verificationType === AuthorizedTransaction::VERIFICATION_OTP) {
                $this->authorizedTransactionManager->dispatchOtp($txn);
                $channel = ($validated['verification_type'] ?? 'sms') === 'email' ? 'email' : 'phone';
                $codeSentMessage = $channel === 'email'
                    ? 'A verification code has been sent to your email.'
                    : 'A verification code has been sent to your phone.';
            }

            return $txn;
        });

        if (is_array($txn) && isset($txn['_none_result'])) {
            return response()->json([
                'status' => 'success',
                'remark' => 'request_money_received',
                'data'   => array_merge(['next_step' => 'none'], $txn['_none_result']),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'remark' => 'request_money_received',
            'data'   => [
                'next_step'         => $verificationType === AuthorizedTransaction::VERIFICATION_PIN ? 'pin' : 'otp',
                'trx'               => $txn->trx,
                'code_sent_message' => $codeSentMessage,
            ],
        ]);
    }

    /**
     * @return array{status: string, remark: string, message: array<int, string>}
     */
    private function errorPayload(string $message): array
    {
        return [
            'status'  => 'error',
            'remark'  => 'request_money_received',
            'message' => [$message],
        ];
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json($this->errorPayload($message), $status);
    }
}
