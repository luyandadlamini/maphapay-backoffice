<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\RequestMoney;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Domain\Shared\Money\MoneyConverter;
use App\Http\Controllers\Controller;
use App\Models\MoneyRequest;
use App\Models\User;
use App\Rules\MajorUnitAmountString;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

/**
 * POST /api/request-money/store — MaphaPay compatibility (Phase 5).
 *
 * Persists a money request and starts OTP/PIN verification. No wallet movement.
 *
 * Phase 0 contract freeze for money initiation:
 * - `amount` is a major-unit decimal string.
 * - `note` and `asset_code` are explicit request fields.
 * - callers should send an Idempotency-Key header for replay-safe initiation retries.
 * - compat success returns `status: success` with `data.next_step = otp | pin`.
 * - clients should prefer OTP/PIN and stop initiating with `verification_type = none`.
 */
class RequestMoneyStoreController extends Controller
{
    public function __construct(
        private readonly AuthorizedTransactionManager $authorizedTransactionManager,
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user' => ['required', 'string'],
            'amount' => ['required', 'string', new MajorUnitAmountString],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'verification_type' => ['sometimes', 'nullable', 'string', Rule::in(['sms', 'email', 'pin'])],
            'asset_code' => ['sometimes', 'string', 'exists:assets,code'],
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

        $verificationType = match ($validated['verification_type'] ?? null) {
            'pin' => AuthorizedTransaction::VERIFICATION_PIN,
            default => AuthorizedTransaction::VERIFICATION_OTP,
        };

        $this->telemetry->logEvent('request_money_initiation_started', $this->telemetry->requestContext($request, [
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'recipient_user_id' => $recipient->id,
            'amount' => $normalizedAmount,
            'asset_code' => $asset->code,
            'verification_type' => $verificationType,
            'idempotency_key_suffix' => $this->telemetry->maskIdempotencyKey($idempotencyKey),
        ]));

        try {
            [$txn, $codeSentMessage] = DB::transaction(function () use (
                $authUser,
                $recipient,
                $normalizedAmount,
                $asset,
                $validated,
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
                    'status' => MoneyRequest::STATUS_AWAITING_OTP,
                ]);

                $payload = [
                    'money_request_id' => $moneyRequest->id,
                    'recipient_user_id' => (int) $recipient->getAuthIdentifier(),
                    'amount' => $normalizedAmount,
                    'asset_code' => $asset->code,
                ];

                $txn = $this->authorizedTransactionManager->initiate(
                    (int) $authUser->getAuthIdentifier(),
                    AuthorizedTransaction::REMARK_REQUEST_MONEY,
                    $payload,
                    $verificationType,
                    $idempotencyKey,
                );

                $moneyRequest->update(['trx' => $txn->trx]);

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
        } catch (Throwable $throwable) {
            $this->telemetry->logEvent('request_money_initiation_failed', $this->telemetry->requestContext($request, [
                'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY,
                'recipient_user_id' => $recipient->id,
                'amount' => $normalizedAmount,
                'asset_code' => $asset->code,
                'verification_type' => $verificationType,
                'idempotency_key_suffix' => $this->telemetry->maskIdempotencyKey($idempotencyKey),
                'message' => $this->telemetry->exceptionMessage($throwable),
            ]), 'error');

            throw $throwable;
        }

        $this->telemetry->logEvent('request_money_initiation_succeeded', $this->telemetry->requestContext($request, [
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'money_request_id' => $txn->payload['money_request_id'] ?? null,
            'recipient_user_id' => $recipient->id,
            'trx' => $txn->trx,
            'next_step' => $verificationType === AuthorizedTransaction::VERIFICATION_PIN ? 'pin' : 'otp',
        ]));

        return response()->json([
            'status' => 'success',
            'remark' => 'request_money',
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
}
