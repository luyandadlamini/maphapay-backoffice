<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\RequestMoney;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Domain\AuthorizedTransaction\Services\MoneyMovementVerificationPolicyResolver;
use App\Domain\Mobile\Services\HighRiskActionTrustPolicy;
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
        private readonly MoneyMovementVerificationPolicyResolver $verificationPolicyResolver,
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
        private readonly HighRiskActionTrustPolicy $trustPolicy,
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

        $asset = Asset::query()->where('code', $moneyRequest->asset_code)->first();
        if (! $asset) {
            return $this->errorResponse($request, "Unknown asset: {$moneyRequest->asset_code}", 422, $moneyRequest);
        }

        $policy = $this->verificationPolicyResolver->resolveRequestMoneyPolicy(
            user: $authUser,
            amount: (string) $moneyRequest->amount,
            asset: $asset,
            operationType: AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            clientHint: isset($validated['verification_type']) ? (string) $validated['verification_type'] : null,
            context: [
                'money_request_id' => $moneyRequest->id,
                'sender_account_uuid' => $fromAccount->uuid,
                'recipient_account_uuid' => $toAccount->uuid,
                'requester_user_id' => $requester->id,
            ],
        );
        $verificationType = $policy['verification_type'];

        $trust = $this->trustPolicy->evaluate($authUser, $request, 'request_money.accept');

        if (($trust['decision'] ?? 'allow') === 'deny') {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TRUST_POLICY_DENY',
                    'message' => 'Request denied by mobile trust policy.',
                    'trust_decision' => $trust['decision'] ?? 'deny',
                    'trust_reason' => $trust['reason'] ?? 'policy',
                    'trust_record_id' => $trust['record_id'] ?? null,
                ],
            ], 403);
        }

        if (in_array(($trust['decision'] ?? ''), ['step_up', 'degrade'], true)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TRUST_POLICY_STEP_UP',
                    'message' => 'Additional verification is required by mobile trust policy.',
                    'trust_decision' => $trust['decision'],
                    'trust_reason' => $trust['reason'] ?? 'policy',
                    'trust_record_id' => $trust['record_id'] ?? null,
                ],
            ], 428);
        }

        $this->telemetry->logEvent('request_money_accept_initiation_started', $this->telemetry->requestContext($request, [
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            'money_request_id' => $moneyRequest->id,
            'money_request_status' => $moneyRequest->status,
            'sender_account_uuid' => $fromAccount->uuid,
            'recipient_account_uuid' => $toAccount->uuid,
            'sender_user_id' => $authUser->id,
            'recipient_user_id' => $requester->id,
            'amount' => $moneyRequest->amount,
            'asset_code' => $moneyRequest->asset_code,
            'status' => AuthorizedTransaction::STATUS_PENDING,
            'verification_policy' => $policy['verification_type'],
            'risk_reason' => $policy['risk_reason'],
            'idempotency_key_suffix' => $this->telemetry->maskIdempotencyKey($idempotencyKey),
        ]));

        try {
            [$txn, $codeSentMessage] = DB::transaction(function () use (
                $authUser,
                $moneyRequest,
                $fromAccount,
                $toAccount,
                $policy,
                $verificationType,
                $validated,
                $idempotencyKey,
                $request,
                $trust,
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
                    // @phpstan-ignore argument.type
                    ->where('payload->money_request_id', $lockedMoneyRequest->id)
                    ->first();

                if ($existingPendingTxn !== null) {
                    $existingIdempotencyKey = (string) ($existingPendingTxn->payload['_idempotency_key'] ?? '');

                    if (
                        $idempotencyKey !== ''
                        && hash_equals($existingIdempotencyKey, $idempotencyKey)
                        && $existingPendingTxn->verification_type === $verificationType
                    ) {
                        $this->telemetry->logIdempotencyReplay($request, $idempotencyKey, [
                            'status_code' => 200,
                            'source' => 'authorized_transaction',
                        ]);

                        $codeSentMessage = null;
                        if ($existingPendingTxn->verification_type === AuthorizedTransaction::VERIFICATION_OTP) {
                            $channel = ($validated['verification_type'] ?? 'sms') === 'email' ? 'email' : 'phone';
                            $codeSentMessage = $channel === 'email'
                                ? 'A verification code has been sent to your email.'
                                : 'A verification code has been sent to your phone.';
                        }

                        return [$existingPendingTxn, $codeSentMessage];
                    }

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
                    '_verification_policy' => $policy,
                    '_trust_record_id' => $trust['record_id'] ?? null,
                    '_trust_decision' => $trust['decision'] ?? 'allow',
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
                'sender_account_uuid' => $fromAccount->uuid,
                'recipient_account_uuid' => $toAccount->uuid,
                'sender_user_id' => $authUser->id,
                'recipient_user_id' => $requester->id,
                'amount' => $moneyRequest->amount,
                'asset_code' => $moneyRequest->asset_code,
                'status' => AuthorizedTransaction::STATUS_PENDING,
                'verification_policy' => $policy['verification_type'],
                'risk_reason' => $policy['risk_reason'],
                'idempotency_key_suffix' => $this->telemetry->maskIdempotencyKey($idempotencyKey),
                'message' => $this->telemetry->exceptionMessage($throwable),
            ]), 'error');

            throw $throwable;
        }

        $this->telemetry->logEvent('request_money_accept_initiation_succeeded', $this->telemetry->requestContext($request, [
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            'money_request_id' => $moneyRequest->id,
            'trx' => $txn->trx,
            'sender_account_uuid' => $fromAccount->uuid,
            'recipient_account_uuid' => $toAccount->uuid,
            'sender_user_id' => $authUser->id,
            'recipient_user_id' => $requester->id,
            'amount' => $moneyRequest->amount,
            'asset_code' => $moneyRequest->asset_code,
            'next_step' => $verificationType === AuthorizedTransaction::VERIFICATION_PIN ? 'pin' : 'otp',
            'status' => AuthorizedTransaction::STATUS_PENDING,
            'verification_policy' => $policy['verification_type'],
            'risk_reason' => $policy['risk_reason'],
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
