<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\RequestMoney;

use App\Domain\Account\Models\Account;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Http\Controllers\Controller;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * POST /api/request-money/received-store/{id} — recipient accepts a pending money request (Phase 5).
 */
class RequestMoneyReceivedStoreController extends Controller
{
    public function __construct(
        private readonly AuthorizedTransactionManager $authorizedTransactionManager,
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
    ) {}

    public function __invoke(Request $request, MoneyRequest $moneyRequest): JsonResponse
    {
        $validated = $request->validate([
            'verification_type' => ['sometimes', 'nullable', 'string', Rule::in(['sms', 'email', 'pin'])],
        ]);

        /** @var User $authUser */
        $authUser = $request->user();
        $idempotencyKey = (string) $request->header('Idempotency-Key', '')
            ?: (string) $request->header('X-Idempotency-Key', '');

        if ((int) $moneyRequest->recipient_user_id !== (int) $authUser->getAuthIdentifier()) {
            return $this->errorResponse($request, 'You are not the recipient of this money request.', 422, $moneyRequest);
        }

        if ((int) $moneyRequest->requester_user_id === (int) $authUser->getAuthIdentifier()) {
            return $this->errorResponse($request, 'You cannot accept your own money request.', 422, $moneyRequest);
        }

        $fromAccount = Account::query()
            ->where('user_uuid', $authUser->uuid)
            ->orderBy('id')
            ->first();

        $requester = User::query()->find($moneyRequest->requester_user_id);
        if (! $requester) {
            return $this->errorResponse($request, 'Requester account not found.', 422, $moneyRequest);
        }

        $toAccount = Account::query()
            ->where('user_uuid', $requester->uuid)
            ->orderBy('id')
            ->first();

        if (! $fromAccount || $fromAccount->frozen) {
            return $this->errorResponse($request, 'Your wallet account was not found or is frozen.', 422, $moneyRequest);
        }

        if (! $toAccount) {
            return $this->errorResponse($request, 'Requester wallet account not found.', 422, $moneyRequest);
        }

        if ((float) $moneyRequest->amount <= 0) {
            return $this->errorResponse($request, 'This money request has an invalid amount (0).', 422, $moneyRequest);
        }

        $verificationType = match ($validated['verification_type'] ?? null) {
            'pin' => AuthorizedTransaction::VERIFICATION_PIN,
            default => AuthorizedTransaction::VERIFICATION_OTP,
        };

        $this->telemetry->logEvent('request_money_accept_initiation_started', $this->telemetry->requestContext($request, [
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            'money_request_id' => $moneyRequest->id,
            'money_request_status' => $moneyRequest->status,
            'amount' => $moneyRequest->amount,
            'asset_code' => $moneyRequest->asset_code,
            'verification_type' => $verificationType,
            'idempotency_key_suffix' => $this->telemetry->maskIdempotencyKey($idempotencyKey),
        ]));

        try {
            [$txn, $codeSentMessage] = DB::transaction(function () use (
                $authUser,
                $moneyRequest,
                $fromAccount,
                $toAccount,
                $verificationType,
                $validated,
                $idempotencyKey,
                $request,
            ): array {
                /** @var MoneyRequest $lockedMoneyRequest */
                $lockedMoneyRequest = MoneyRequest::query()
                    ->whereKey($moneyRequest->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedMoneyRequest->status !== MoneyRequest::STATUS_PENDING) {
                    throw new RuntimeException('This money request is not pending.');
                }

                $existingPendingTxn = AuthorizedTransaction::query()
                    ->where('remark', AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED)
                    ->where('user_id', (int) $authUser->getAuthIdentifier())
                    ->where('status', AuthorizedTransaction::STATUS_PENDING)
                    ->where('payload->money_request_id', $lockedMoneyRequest->id)
                    ->first();

                if ($existingPendingTxn !== null) {
                    $this->telemetry->logDuplicateAcceptancePrevented(
                        $request,
                        $lockedMoneyRequest,
                        'active_authorization_exists',
                        $idempotencyKey,
                    );

                    throw new RuntimeException('A payment authorization for this money request is already in progress.');
                }

                $payload = [
                    'money_request_id' => $lockedMoneyRequest->id,
                    'requester_user_id' => (int) $lockedMoneyRequest->requester_user_id,
                    'amount' => $lockedMoneyRequest->amount,
                    'asset_code' => $lockedMoneyRequest->asset_code,
                    'from_account_uuid' => $fromAccount->uuid,
                    'to_account_uuid' => $toAccount->uuid,
                ];

                $txn = $this->authorizedTransactionManager->initiate(
                    (int) $authUser->getAuthIdentifier(),
                    AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
                    $payload,
                    $verificationType,
                    $idempotencyKey,
                );

                $codeSentMessage = null;
                if ($verificationType === AuthorizedTransaction::VERIFICATION_OTP) {
                    $this->authorizedTransactionManager->dispatchOtp($txn);
                    $channel = ($validated['verification_type'] ?? 'sms') === 'email' ? 'email' : 'phone';
                    $codeSentMessage = $channel === 'email'
                        ? 'A verification code has been sent to your email.'
                        : 'A verification code has been sent to your phone.';
                }

                return [$txn, $codeSentMessage];
            });
        } catch (RuntimeException $e) {
            return $this->errorResponse($request, $e->getMessage(), 422, $moneyRequest, [
                'idempotency_key_suffix' => $this->telemetry->maskIdempotencyKey($idempotencyKey),
            ]);
        } catch (Throwable $throwable) {
            $this->telemetry->logEvent('request_money_accept_initiation_failed', $this->telemetry->requestContext($request, [
                'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
                'money_request_id' => $moneyRequest->id,
                'idempotency_key_suffix' => $this->telemetry->maskIdempotencyKey($idempotencyKey),
                'message' => $this->telemetry->exceptionMessage($throwable),
            ]), 'error');

            throw $throwable;
        }

        $this->telemetry->logEvent('request_money_accept_initiation_succeeded', $this->telemetry->requestContext($request, [
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            'money_request_id' => $moneyRequest->id,
            'trx' => $txn->trx,
            'next_step' => $verificationType === AuthorizedTransaction::VERIFICATION_PIN ? 'pin' : 'otp',
            'idempotency_key_suffix' => $this->telemetry->maskIdempotencyKey($idempotencyKey),
        ]));

        return response()->json([
            'status' => 'success',
            'remark' => 'request_money_received',
            'data' => [
                'next_step' => $verificationType === AuthorizedTransaction::VERIFICATION_PIN ? 'pin' : 'otp',
                'trx' => $txn->trx,
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
            'status' => 'error',
            'remark' => 'request_money_received',
            'message' => [$message],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function errorResponse(
        Request $request,
        string $message,
        int $status,
        MoneyRequest $moneyRequest,
        array $context = [],
    ): JsonResponse {
        $this->telemetry->logEvent('request_money_accept_initiation_failed', $this->telemetry->requestContext($request, array_merge($context, [
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            'money_request_id' => $moneyRequest->id,
            'money_request_status' => $moneyRequest->status,
            'message' => $message,
            'status_code' => $status,
        ])), 'warning');

        return response()->json($this->errorPayload($message), $status);
    }
}
