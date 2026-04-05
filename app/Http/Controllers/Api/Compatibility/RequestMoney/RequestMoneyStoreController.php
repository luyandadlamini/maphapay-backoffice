<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\RequestMoney;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Domain\AuthorizedTransaction\Services\MoneyMovementVerificationPolicyResolver;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Domain\Payment\Services\PaymentLinkService;
use App\Domain\Shared\Money\MoneyConverter;
use App\Http\Controllers\Controller;
use App\Models\MoneyRequest;
use App\Models\User;
use App\Rules\MajorUnitAmountString;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * POST /api/request-money/store — MaphaPay compatibility (Phase 5).
 *
 * Persists a money request immediately. No wallet movement.
 *
 * Phase 0 contract freeze for money initiation:
 * - `amount` is a major-unit decimal string.
 * - `note` and `asset_code` are explicit request fields.
 * - callers should send an Idempotency-Key header for replay-safe initiation retries.
 * - compat success returns `status: success` with `data.next_step = none`.
 * - request creation does not accept a verification override.
 */
class RequestMoneyStoreController extends Controller
{
    public function __construct(
        private readonly AuthorizedTransactionManager $authorizedTransactionManager,
        private readonly MoneyMovementVerificationPolicyResolver $verificationPolicyResolver,
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
        private readonly PaymentLinkService $paymentLinkService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user' => ['required', 'string'],
            'amount' => ['required', 'string', new MajorUnitAmountString],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'verification_type' => ['prohibited'],
            'asset_code' => ['sometimes', 'string', 'exists:assets,code'],
            'chat_friend_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        /** @var User $authUser */
        $authUser = $request->user();
        $idempotencyKey = (string) $request->header('Idempotency-Key', '')
            ?: (string) $request->header('X-Idempotency-Key', '');

        $recipient = $this->resolvePayeeUser((string) $validated['user']);
        if (! $recipient) {
            return $this->errorResponse($request, 'Recipient not found.', 422);
        }

        if ((int) $recipient->id === (int) $authUser->id) {
            return $this->errorResponse($request, 'You cannot request money from yourself.', 422, [
                'recipient_user_id' => $recipient->id,
            ]);
        }

        $chatFriendId = isset($validated['chat_friend_id']) ? (int) $validated['chat_friend_id'] : null;
        if ($chatFriendId !== null && $chatFriendId !== (int) $recipient->id) {
            return $this->errorResponse($request, 'Chat context does not match the selected recipient.', 422, [
                'recipient_user_id' => $recipient->id,
                'chat_friend_id' => $chatFriendId,
            ]);
        }

        $assetCode = $validated['asset_code'] ?? 'SZL';
        $asset = Asset::query()->where('code', $assetCode)->first();
        if (! $asset) {
            return $this->errorResponse($request, "Unknown asset: {$assetCode}", 422, [
                'recipient_user_id' => $recipient->id,
            ]);
        }

        try {
            $normalizedAmount = MoneyConverter::normalise($validated['amount'], $asset->precision);
            if ((float) $normalizedAmount <= 0) {
                return $this->errorResponse($request, 'Amount must be greater than zero.', 422, [
                    'recipient_user_id' => $recipient->id,
                ]);
            }
        } catch (InvalidArgumentException) {
            return $this->errorResponse($request, 'Invalid amount.', 422, [
                'recipient_user_id' => $recipient->id,
            ]);
        }

        $policy = $this->verificationPolicyResolver->resolveRequestMoneyCreatePolicy(
            user: $authUser,
            amount: $normalizedAmount,
            asset: $asset,
            clientHint: null,
            context: [
                'recipient_user_id' => $recipient->id,
            ],
        );
        $verificationType = AuthorizedTransaction::VERIFICATION_NONE;

        $replayedTxn = $this->findExistingInitiationReplay(
            userId: (int) $authUser->getAuthIdentifier(),
            idempotencyKey: $idempotencyKey,
        );
        if ($replayedTxn !== null) {
            if (! $this->requestMoneyReplayMatches($replayedTxn, $recipient, $normalizedAmount, $asset->code, $validated['note'] ?? null)) {
                return response()->json([
                    'error' => 'Idempotency key already used',
                    'message' => 'The provided idempotency key has already been used with different request parameters',
                ], 409);
            }

            $this->telemetry->logIdempotencyReplay($request, $idempotencyKey, [
                'status_code' => 200,
                'source' => 'authorized_transaction',
            ]);

            return response()->json($this->requestMoneyReplayPayload($replayedTxn));
        }

        $this->telemetry->logEvent('request_money_initiation_started', $this->telemetry->requestContext($request, [
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'sender_user_id' => $authUser->id,
            'recipient_user_id' => $recipient->id,
            'amount' => $normalizedAmount,
            'asset_code' => $asset->code,
            'status' => AuthorizedTransaction::STATUS_PENDING,
            'verification_policy' => $policy['verification_type'],
            'risk_reason' => $policy['risk_reason'],
            'idempotency_key_suffix' => $this->telemetry->maskIdempotencyKey($idempotencyKey),
        ]));

        try {
            [$txn, $result] = DB::transaction(function () use (
                $authUser,
                $recipient,
                $normalizedAmount,
                $asset,
                $validated,
                $chatFriendId,
                $policy,
                $verificationType,
                $idempotencyKey,
            ): array {
                $moneyRequest = MoneyRequest::query()->create([
                    'id' => (string) Str::uuid(),
                    'requester_user_id' => (int) $authUser->getAuthIdentifier(),
                    'recipient_user_id' => (int) $recipient->getAuthIdentifier(),
                    'amount' => $normalizedAmount,
                    'asset_code' => $asset->code,
                    'note' => $validated['note'] ?? null,
                    'status' => MoneyRequest::STATUS_PENDING,
                ]);

                // Generate payment token for the money request
                $moneyRequest = $this->paymentLinkService->assignPaymentToken($moneyRequest);

                $payload = [
                    'money_request_id' => $moneyRequest->id,
                    'recipient_user_id' => (int) $recipient->getAuthIdentifier(),
                    'amount' => $normalizedAmount,
                    'asset_code' => $asset->code,
                    'note' => $validated['note'] ?? null,
                    '_verification_policy' => $policy,
                    'chat_friend_id' => $chatFriendId,
                ];

                $txn = $this->authorizedTransactionManager->initiate(
                    (int) $authUser->getAuthIdentifier(),
                    AuthorizedTransaction::REMARK_REQUEST_MONEY,
                    $payload,
                    $verificationType,
                    $idempotencyKey,
                );

                $moneyRequest->update(['trx' => $txn->trx]);

                $result = $this->authorizedTransactionManager->finalize($txn);

                return [$txn->fresh(), $result];
            });
        } catch (Throwable $throwable) {
            $this->telemetry->logEvent('request_money_initiation_failed', $this->telemetry->requestContext($request, [
                'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY,
                'sender_user_id' => $authUser->id,
                'recipient_user_id' => $recipient->id,
                'amount' => $normalizedAmount,
                'asset_code' => $asset->code,
                'status' => AuthorizedTransaction::STATUS_PENDING,
                'verification_policy' => $policy['verification_type'],
                'risk_reason' => $policy['risk_reason'],
                'idempotency_key_suffix' => $this->telemetry->maskIdempotencyKey($idempotencyKey),
                'message' => $this->telemetry->exceptionMessage($throwable),
            ]), 'error');

            throw $throwable;
        }

        $this->telemetry->logEvent('request_money_initiation_succeeded', $this->telemetry->requestContext($request, [
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'money_request_id' => $txn->payload['money_request_id'] ?? null,
            'sender_user_id' => $authUser->id,
            'recipient_user_id' => $recipient->id,
            'trx' => $txn->trx,
            'next_step' => 'none',
            'status' => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_policy' => $policy['verification_type'],
            'risk_reason' => $policy['risk_reason'],
        ]));

        $moneyRequestId = $result['money_request_id'] ?? $txn->payload['money_request_id'] ?? null;
        $moneyRequest = $moneyRequestId ? MoneyRequest::query()->find($moneyRequestId) : null;

        return response()->json([
            'status' => 'success',
            'remark' => 'request_money',
            'data' => [
                'next_step' => 'none',
                'trx' => $txn->trx,
                'code_sent_message' => null,
                'money_request_id' => $moneyRequestId,
                'chat_message_id' => $result['chat_message_id'] ?? null,
                'chat_linked' => $result['chat_linked'] ?? null,
                'payment_token' => $moneyRequest?->payment_token,
                'payment_link' => $moneyRequest?->payment_token
                    ? $this->paymentLinkService->buildDynamicLink($moneyRequest->payment_token)
                    : null,
                'expires_at' => $moneyRequest?->expires_at?->toIso8601String(),
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
            'remark' => 'request_money',
            'message' => [$message],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function errorResponse(Request $request, string $message, int $status, array $context = []): JsonResponse
    {
        $this->telemetry->logEvent('request_money_initiation_failed', $this->telemetry->requestContext($request, array_merge($context, [
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'message' => $message,
            'status_code' => $status,
        ])), 'warning');

        return response()->json($this->errorPayload($message), $status);
    }

    private function resolvePayeeUser(string $user): ?User
    {
        if (Str::isUuid($user)) {
            return User::query()->where('uuid', $user)->first();
        }

        if (filter_var($user, FILTER_VALIDATE_EMAIL)) {
            return User::query()->where('email', $user)->first();
        }

        if (ctype_digit($user)) {
            $userById = User::query()->find((int) $user);
            if ($userById) {
                return $userById;
            }
        }

        $username = str_starts_with($user, '@') ? substr($user, 1) : $user;

        return User::query()
            ->where('email', $user)
            ->orWhere('mobile', $user)
            ->orWhere('username', $username)
            ->first();
    }

    private function findExistingInitiationReplay(int $userId, string $idempotencyKey): ?AuthorizedTransaction
    {
        if ($idempotencyKey === '') {
            return null;
        }

        return AuthorizedTransaction::query()
            ->where('user_id', $userId)
            ->where('remark', AuthorizedTransaction::REMARK_REQUEST_MONEY)
            ->where('payload->_idempotency_key', $idempotencyKey)
            ->latest('created_at')
            ->first();
    }

    private function requestMoneyReplayMatches(
        AuthorizedTransaction $txn,
        User $recipient,
        string $amount,
        string $assetCode,
        ?string $note,
    ): bool {
        $payload = is_array($txn->payload) ? $txn->payload : [];
        $moneyRequestId = $payload['money_request_id'] ?? null;
        if (! is_string($moneyRequestId) || $moneyRequestId === '') {
            return false;
        }

        $moneyRequest = MoneyRequest::query()->find($moneyRequestId);
        if ($moneyRequest === null) {
            return false;
        }

        return (int) $moneyRequest->recipient_user_id === (int) $recipient->id
            && (string) $moneyRequest->amount === $amount
            && (string) $moneyRequest->asset_code === $assetCode
            && (($moneyRequest->note ?? null) === $note);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestMoneyReplayPayload(AuthorizedTransaction $txn): array
    {
        $result = is_array($txn->result) ? $txn->result : [];
        $moneyRequestId = $result['money_request_id'] ?? $txn->payload['money_request_id'] ?? null;

        // Hydrate the MoneyRequest so the replay response includes the payment link —
        // the RN app needs payment_token to navigate to the QR screen on replay.
        $moneyRequest = $moneyRequestId ? MoneyRequest::query()->find($moneyRequestId) : null;

        return [
            'status' => 'success',
            'remark' => 'request_money',
            'data' => [
                'next_step' => 'none',
                'trx' => $txn->trx,
                'code_sent_message' => null,
                'money_request_id' => $moneyRequestId,
                'chat_message_id' => $result['chat_message_id'] ?? null,
                'chat_linked' => $result['chat_linked'] ?? null,
                'payment_token' => $moneyRequest?->payment_token,
                'payment_link' => $moneyRequest?->payment_token
                    ? $this->paymentLinkService->buildDynamicLink($moneyRequest->payment_token)
                    : null,
                'expires_at' => $moneyRequest?->expires_at?->toIso8601String(),
            ],
        ];
    }
}
