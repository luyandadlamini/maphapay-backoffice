<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\SendMoney;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorSpendApproval;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Domain\AuthorizedTransaction\Services\MoneyMovementVerificationPolicyResolver;
use App\Domain\Mobile\Services\HighRiskActionTrustPolicy;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Domain\Payment\Services\PaymentLinkService;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Shared\OperationRecord\Exceptions\OperationPayloadMismatchException;
use App\Domain\Shared\OperationRecord\OperationRecordService;
use App\Http\Controllers\Controller;
use App\Models\MoneyRequest;
use App\Models\User;
use App\Rules\MajorUnitAmountString;
use App\Rules\ValidateMinorAccountPermission;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * POST /api/send-money/store — MaphaPay compatibility (Phase 5).
 *
 * Phase 0 contract freeze for money initiation:
 * - `amount` is a major-unit decimal string.
 * - `note` and `asset_code` are explicit request fields.
 * - callers should send an Idempotency-Key header for replay-safe initiation retries.
 * - compat success returns `status: success` with `data.next_step = otp | pin`.
 * - clients should prefer OTP/PIN and stop initiating with `verification_type = none`.
 */
class SendMoneyStoreController extends Controller
{
    private const MINOR_SPEND_APPROVAL_OPERATION = 'minor_spend_approval';

    public function __construct(
        private readonly AuthorizedTransactionManager $authorizedTransactionManager,
        private readonly MoneyMovementVerificationPolicyResolver $verificationPolicyResolver,
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
        private readonly HighRiskActionTrustPolicy $trustPolicy,
        private readonly PaymentLinkService $paymentLinkService,
        private readonly OperationRecordService $operationRecordService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_link_token' => ['sometimes', 'nullable', 'string'],
            'user'               => ['required_without:payment_link_token', 'string'],
            'amount'             => ['required_without:payment_link_token', 'string', new MajorUnitAmountString()],
            'note'               => ['sometimes', 'nullable', 'string', 'max:2000'],
            'verification_type'  => ['sometimes', 'nullable', 'string', Rule::in(['sms', 'email', 'pin', 'none'])],
            'asset_code'         => ['sometimes', 'string', 'exists:assets,code'],
            'merchant_category'  => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        /** @var User $requestUser */
        $requestUser = $request->user();
        /** @var User $authUser */
        $authUser = $requestUser->fresh() ?? $requestUser;
        $idempotencyKey = (string) $request->header('Idempotency-Key', '')
            ?: (string) $request->header('X-Idempotency-Key', '');

        $paymentLinkToken = isset($validated['payment_link_token'])
            ? trim((string) $validated['payment_link_token'])
            : '';
        $moneyRequest = null;

        if ($paymentLinkToken !== '') {
            $moneyRequest = $this->paymentLinkService->resolveValidMoneyRequest($paymentLinkToken);
            if (! $moneyRequest instanceof MoneyRequest) {
                return $this->errorResponse($request, 'Payment link token is invalid or expired.', 422, [
                    'event' => 'send_money_initiation_failed',
                ]);
            }

            if ((int) $moneyRequest->recipient_user_id !== (int) $authUser->id) {
                return $this->errorResponse($request, 'This payment link is not available for the authenticated user.', 403, [
                    'event'            => 'send_money_initiation_failed',
                    'money_request_id' => $moneyRequest->id,
                ]);
            }

            /** @var User|null $resolvedRecipient */
            $resolvedRecipient = User::query()->find($moneyRequest->requester_user_id);
            $recipient = $resolvedRecipient;
        } else {
            $recipient = $this->resolvePayeeUser((string) $validated['user']);
        }

        if (! $recipient) {
            return $this->errorResponse($request, 'Recipient not found.', 422, [
                'event'              => 'send_money_initiation_failed',
                'payment_link_token' => $paymentLinkToken !== '' ? $paymentLinkToken : null,
            ]);
        }

        if ((int) $recipient->id === (int) $authUser->id) {
            return $this->errorResponse($request, 'You cannot send money to yourself.', 422, [
                'event'             => 'send_money_initiation_failed',
                'recipient_user_id' => $recipient->id,
            ]);
        }

        $fromAccount = Account::query()
            ->where('user_uuid', $authUser->uuid)
            ->orderBy('id')
            ->first();

        $toAccount = Account::query()
            ->where('user_uuid', $recipient->uuid)
            ->orderBy('id')
            ->first();

        if (! $fromAccount || $fromAccount->frozen) {
            return $this->errorResponse($request, 'Sender wallet account not found or is frozen.', 422, [
                'event'             => 'send_money_initiation_failed',
                'recipient_user_id' => $recipient->id,
            ]);
        }

        if (! $toAccount) {
            return $this->errorResponse($request, 'Recipient wallet account not found.', 422, [
                'event'             => 'send_money_initiation_failed',
                'recipient_user_id' => $recipient->id,
            ]);
        }

        $assetCode = $moneyRequest instanceof MoneyRequest
            ? $moneyRequest->asset_code
            : ($validated['asset_code'] ?? 'SZL');
        $asset = Asset::query()->where('code', $assetCode)->first();
        if (! $asset) {
            return $this->errorResponse($request, "Unknown asset: {$assetCode}", 422, [
                'event'             => 'send_money_initiation_failed',
                'recipient_user_id' => $recipient->id,
            ]);
        }

        try {
            $requestedAmount = $moneyRequest instanceof MoneyRequest
                ? $moneyRequest->amount
                : $validated['amount'];
            $normalizedAmount = MoneyConverter::normalise($requestedAmount, $asset->precision);
            if ((float) $normalizedAmount <= 0) {
                return $this->errorResponse($request, 'Amount must be greater than zero.', 422, [
                    'event'             => 'send_money_initiation_failed',
                    'recipient_user_id' => $recipient->id,
                ]);
            }
        } catch (InvalidArgumentException) {
            return $this->errorResponse($request, 'Invalid amount.', 422, [
                'event'             => 'send_money_initiation_failed',
                'recipient_user_id' => $recipient->id,
            ]);
        }

        $trust = null;

        // ── Minor account spending enforcement ──────────────────────────────
        if ($fromAccount->type === 'minor') {
            $merchantCategory = isset($validated['merchant_category'])
                ? (string) $validated['merchant_category']
                : 'general';

            $minorPermissionRule = new ValidateMinorAccountPermission(
                $fromAccount,
                $merchantCategory,
            );

            $limitError = null;
            $minorPermissionRule->validate(
                'amount',
                $normalizedAmount,
                function (string $msg) use (&$limitError): void {
                $limitError = $msg;
                }
            );

            if ($limitError !== null) {
                return $this->errorResponse($request, $limitError, 422, [
                    'event' => 'minor_spend_limit_exceeded',
                ]);
            }

            // Approval threshold: hold high-value spends for guardian sign-off
            $permissionLevel = (int) ($fromAccount->permission_level ?? 0);
            $threshold = ValidateMinorAccountPermission::approvalThresholdFor($permissionLevel);

            if ($threshold !== null && (float) $normalizedAmount > $threshold) {
                if ($idempotencyKey === '') {
                    return $this->errorResponse(
                        $request,
                        'Idempotency-Key header is required for guardian approval requests.',
                        422,
                        ['event' => 'send_money_initiation_failed'],
                    );
                }

                $trust = $this->trustPolicy->evaluate($authUser, $request, 'send_money');

                if (($trust['decision'] ?? 'allow') === 'deny') {
                    return response()->json([
                        'success' => false,
                        'error'   => [
                            'code'            => 'TRUST_POLICY_DENY',
                            'message'         => 'Request denied by mobile trust policy.',
                            'trust_decision'  => $trust['decision'] ?? 'deny',
                            'trust_reason'    => $trust['reason'] ?? 'policy',
                            'trust_record_id' => $trust['record_id'] ?? null,
                        ],
                    ], 403);
                }

                if (in_array(($trust['decision'] ?? ''), ['step_up', 'degrade'], true)) {
                    return response()->json([
                        'success' => false,
                        'error'   => [
                            'code'            => 'TRUST_POLICY_STEP_UP',
                            'message'         => 'Additional verification is required by mobile trust policy.',
                            'trust_decision'  => $trust['decision'],
                            'trust_reason'    => $trust['reason'] ?? 'policy',
                            'trust_record_id' => $trust['record_id'] ?? null,
                        ],
                    ], 428);
                }

                // Find the primary guardian's account UUID
                $guardianAccountUuid = null;
                try {
                    $guardianMembership = AccountMembership::query()
                        ->forAccount($fromAccount->uuid)
                        ->active()
                        ->where('role', 'guardian')
                        ->first();

                    $guardianAccountUuid = $guardianMembership?->account_uuid;
                } catch (Exception) {
                    // AccountMembership table may not be accessible
                }

                $guardianAccountUuid = $guardianAccountUuid ?? (string) $fromAccount->parent_account_id;
                $approvalPayload = [
                    'minor_account_uuid'    => $fromAccount->uuid,
                    'guardian_account_uuid' => $guardianAccountUuid,
                    'from_account_uuid'     => $fromAccount->uuid,
                    'to_account_uuid'       => $toAccount->uuid,
                    'amount'                => $normalizedAmount,
                    'asset_code'            => $asset->code,
                    'note'                  => $validated['note'] ?? null,
                    'merchant_category'     => $merchantCategory,
                ];

                try {
                    $approvalResponse = DB::transaction(fn (): array => $this->operationRecordService->guardAndRun(
                            userId: (int) $authUser->getAuthIdentifier(),
                            type: self::MINOR_SPEND_APPROVAL_OPERATION,
                            key: $idempotencyKey,
                            payloadHash: $this->minorApprovalPayloadHash($approvalPayload),
                            fn: fn (): array => $this->createMinorApprovalResponse($approvalPayload),
                        ));
                } catch (OperationPayloadMismatchException) {
                    return response()->json([
                        'error'   => 'Idempotency key already used',
                        'message' => 'The provided idempotency key has already been used with different request parameters',
                    ], 409);
                } catch (RuntimeException $exception) {
                    return response()->json([
                        'error'   => 'Request in progress',
                        'message' => $exception->getMessage(),
                    ], 409);
                }

                return response()->json($approvalResponse, 202);
            }
        }
        // ────────────────────────────────────────────────────────────────────

        $policy = $this->verificationPolicyResolver->resolveSendMoneyPolicy(
            user: $authUser,
            amount: $normalizedAmount,
            asset: $asset,
            clientHint: isset($validated['verification_type']) ? (string) $validated['verification_type'] : null,
            context: [
                'sender_account_uuid'    => $fromAccount->uuid,
                'recipient_account_uuid' => $toAccount->uuid,
                'recipient_user_id'      => $recipient->id,
            ],
        );
        $verificationType = $policy['verification_type'];

        $payload = [
            'from_account_uuid' => $fromAccount->uuid,
            'to_account_uuid'   => $toAccount->uuid,
            'amount'            => $normalizedAmount,
            'asset_code'        => $asset->code,
            'note'              => $moneyRequest instanceof MoneyRequest
                ? ($moneyRequest->note ?? '')
                : ($validated['note'] ?? ''),
            '_verification_policy' => $policy,
        ];
        if ($moneyRequest instanceof MoneyRequest) {
            $payload['money_request_id'] = (string) $moneyRequest->id;
            $payload['payment_link_token'] = $paymentLinkToken;
        }

        $replayedTxn = $this->findExistingInitiationReplay(
            userId: (int) $authUser->getAuthIdentifier(),
            idempotencyKey: $idempotencyKey,
        );
        if ($replayedTxn !== null) {
            if (! $this->sendMoneyReplayMatches($replayedTxn, $payload)) {
                return response()->json([
                    'error'   => 'Idempotency key already used',
                    'message' => 'The provided idempotency key has already been used with different request parameters',
                ], 409);
            }

            $this->telemetry->logIdempotencyReplay($request, $idempotencyKey, [
                'status_code' => 200,
                'source'      => 'authorized_transaction',
            ]);

            return response()->json($this->sendMoneyReplayPayload($replayedTxn, $validated));
        }

        $trust ??= $this->trustPolicy->evaluate($authUser, $request, 'send_money');

        if (($trust['decision'] ?? 'allow') === 'deny') {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'            => 'TRUST_POLICY_DENY',
                    'message'         => 'Request denied by mobile trust policy.',
                    'trust_decision'  => $trust['decision'] ?? 'deny',
                    'trust_reason'    => $trust['reason'] ?? 'policy',
                    'trust_record_id' => $trust['record_id'] ?? null,
                ],
            ], 403);
        }

        if (in_array(($trust['decision'] ?? ''), ['step_up', 'degrade'], true)) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'            => 'TRUST_POLICY_STEP_UP',
                    'message'         => 'Additional verification is required by mobile trust policy.',
                    'trust_decision'  => $trust['decision'],
                    'trust_reason'    => $trust['reason'] ?? 'policy',
                    'trust_record_id' => $trust['record_id'] ?? null,
                ],
            ], 428);
        }

        $payload['_trust_record_id'] = $trust['record_id'] ?? null;
        $payload['_trust_decision'] = $trust['decision'] ?? 'allow';

        $this->telemetry->logEvent('send_money_initiation_started', $this->telemetry->requestContext($request, [
            'remark'                 => AuthorizedTransaction::REMARK_SEND_MONEY,
            'sender_account_uuid'    => $fromAccount->uuid,
            'recipient_account_uuid' => $toAccount->uuid,
            'sender_user_id'         => $authUser->id,
            'recipient_user_id'      => $recipient->id,
            'amount'                 => $normalizedAmount,
            'asset_code'             => $asset->code,
            'status'                 => AuthorizedTransaction::STATUS_PENDING,
            'verification_policy'    => $policy['verification_type'],
            'risk_reason'            => $policy['risk_reason'],
            'idempotency_key_suffix' => $this->telemetry->maskIdempotencyKey($idempotencyKey),
        ]));

        try {
            $txn = $this->authorizedTransactionManager->initiate(
                (int) $authUser->getAuthIdentifier(),
                AuthorizedTransaction::REMARK_SEND_MONEY,
                $payload,
                $verificationType,
                $idempotencyKey,
            );
        } catch (Throwable $throwable) {
            $this->telemetry->logEvent('send_money_initiation_failed', $this->telemetry->requestContext($request, [
                'remark'                 => AuthorizedTransaction::REMARK_SEND_MONEY,
                'recipient_user_id'      => $recipient->id,
                'sender_account_uuid'    => $fromAccount->uuid,
                'recipient_account_uuid' => $toAccount->uuid,
                'amount'                 => $normalizedAmount,
                'asset_code'             => $asset->code,
                'verification_policy'    => $policy['verification_type'],
                'risk_reason'            => $policy['risk_reason'],
                'idempotency_key_suffix' => $this->telemetry->maskIdempotencyKey($idempotencyKey),
                'message'                => $this->telemetry->exceptionMessage($throwable),
            ]), 'error');

            throw $throwable;
        }

        if ($verificationType === AuthorizedTransaction::VERIFICATION_NONE) {
            try {
                $result = $this->authorizedTransactionManager->finalize($txn);
            } catch (RuntimeException $throwable) {
                $this->telemetry->logEvent('send_money_initiation_failed', $this->telemetry->requestContext($request, [
                    'remark'                 => AuthorizedTransaction::REMARK_SEND_MONEY,
                    'trx'                    => $txn->trx,
                    'sender_account_uuid'    => $fromAccount->uuid,
                    'recipient_account_uuid' => $toAccount->uuid,
                    'sender_user_id'         => $authUser->id,
                    'recipient_user_id'      => $recipient->id,
                    'amount'                 => $normalizedAmount,
                    'asset_code'             => $asset->code,
                    'verification_policy'    => $policy['verification_type'],
                    'risk_reason'            => $policy['risk_reason'],
                    'message'                => $throwable->getMessage(),
                ]), 'warning');

                return $this->errorResponse($request, $throwable->getMessage(), 422, [
                    'recipient_user_id'      => $recipient->id,
                    'sender_account_uuid'    => $fromAccount->uuid,
                    'recipient_account_uuid' => $toAccount->uuid,
                    'sender_user_id'         => $authUser->id,
                    'amount'                 => $normalizedAmount,
                    'asset_code'             => $asset->code,
                    'verification_policy'    => $policy['verification_type'],
                    'risk_reason'            => $policy['risk_reason'],
                ]);
            }

            $this->telemetry->logEvent('send_money_initiation_succeeded', $this->telemetry->requestContext($request, [
                'remark'                 => AuthorizedTransaction::REMARK_SEND_MONEY,
                'trx'                    => $txn->trx,
                'reference'              => $result['reference'] ?? null,
                'sender_account_uuid'    => $fromAccount->uuid,
                'recipient_account_uuid' => $toAccount->uuid,
                'sender_user_id'         => $authUser->id,
                'next_step'              => 'none',
                'verification_policy'    => $policy['verification_type'],
                'risk_reason'            => $policy['risk_reason'],
                'status'                 => AuthorizedTransaction::STATUS_COMPLETED,
                'recipient_user_id'      => $recipient->id,
            ]));

            // ── Saving milestone check (minor accounts only) ─────────────────────────────
            if ($fromAccount->type === 'minor') {
                $totalSaved = (string) \Illuminate\Support\Facades\DB::table('transaction_projections')
                    ->where('account_uuid', $fromAccount->uuid)
                    ->where('type', 'deposit')
                    ->sum('amount');

                try {
                    app(\App\Domain\Account\Services\MinorPointsService::class)
                        ->checkAndAwardSavingMilestones($fromAccount, $totalSaved);
                } catch (Throwable) {
                    // Milestone check is non-critical; never block the transfer response.
                }
            }
            // ─────────────────────────────────────────────────────────────────────────────

            return response()->json([
                'status' => 'success',
                'remark' => 'send_money',
                'data'   => array_merge(['next_step' => 'none'], $result),
            ]);
        }

        $codeSentMessage = null;
        if ($verificationType === AuthorizedTransaction::VERIFICATION_OTP) {
            $this->authorizedTransactionManager->dispatchOtp($txn);
            $channel = ($validated['verification_type'] ?? 'sms') === 'email' ? 'email' : 'phone';
            $codeSentMessage = $channel === 'email'
                ? 'A verification code has been sent to your email.'
                : 'A verification code has been sent to your phone.';
        }

        $this->telemetry->logEvent('send_money_initiation_succeeded', $this->telemetry->requestContext($request, [
            'remark'                 => AuthorizedTransaction::REMARK_SEND_MONEY,
            'trx'                    => $txn->trx,
            'sender_account_uuid'    => $fromAccount->uuid,
            'recipient_account_uuid' => $toAccount->uuid,
            'sender_user_id'         => $authUser->id,
            'next_step'              => $verificationType === AuthorizedTransaction::VERIFICATION_PIN ? 'pin' : 'otp',
            'verification_policy'    => $policy['verification_type'],
            'risk_reason'            => $policy['risk_reason'],
            'status'                 => AuthorizedTransaction::STATUS_PENDING,
            'recipient_user_id'      => $recipient->id,
        ]));

        return response()->json([
            'status' => 'success',
            'remark' => 'send_money',
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
            'remark'  => 'send_money',
            'message' => [$message],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function errorResponse(Request $request, string $message, int $status, array $context = []): JsonResponse
    {
        $event = $context['event'] ?? 'send_money_initiation_failed';
        unset($context['event']);

        $this->telemetry->logEvent($event, $this->telemetry->requestContext($request, array_merge($context, [
            'remark'      => AuthorizedTransaction::REMARK_SEND_MONEY,
            'message'     => $message,
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
            ->where('remark', AuthorizedTransaction::REMARK_SEND_MONEY)
            // @phpstan-ignore argument.type
            ->where('payload->_idempotency_key', $idempotencyKey)
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendMoneyReplayMatches(AuthorizedTransaction $txn, array $payload): bool
    {
        $existingPayload = is_array($txn->payload) ? $txn->payload : [];

        return ($existingPayload['from_account_uuid'] ?? null) === $payload['from_account_uuid']
            && ($existingPayload['to_account_uuid'] ?? null) === $payload['to_account_uuid']
            && ($existingPayload['amount'] ?? null) === $payload['amount']
            && ($existingPayload['asset_code'] ?? null) === $payload['asset_code']
            && (($existingPayload['note'] ?? '') === ($payload['note'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function sendMoneyReplayPayload(AuthorizedTransaction $txn, array $validated): array
    {
        if ($txn->isCompleted() && is_array($txn->result)) {
            return [
                'status' => 'success',
                'remark' => 'send_money',
                'data'   => array_merge([
                    'next_step' => 'none',
                ], $txn->result),
            ];
        }

        $nextStep = match ($txn->verification_type) {
            AuthorizedTransaction::VERIFICATION_PIN  => 'pin',
            AuthorizedTransaction::VERIFICATION_NONE => 'none',
            default                                  => 'otp',
        };
        $codeSentMessage = null;
        if ($txn->verification_type === AuthorizedTransaction::VERIFICATION_OTP) {
            $channel = ($validated['verification_type'] ?? 'sms') === 'email' ? 'email' : 'phone';
            $codeSentMessage = $channel === 'email'
                ? 'A verification code has been sent to your email.'
                : 'A verification code has been sent to your phone.';
        }

        return [
            'status' => 'success',
            'remark' => 'send_money',
            'data'   => [
                'next_step'         => $nextStep,
                'trx'               => $txn->trx,
                'code_sent_message' => $codeSentMessage,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $approvalPayload
     * @return array<string, mixed>
     */
    private function createMinorApprovalResponse(array $approvalPayload): array
    {
        $approval = MinorSpendApproval::create([
            'minor_account_uuid'    => $approvalPayload['minor_account_uuid'],
            'guardian_account_uuid' => $approvalPayload['guardian_account_uuid'],
            'from_account_uuid'     => $approvalPayload['from_account_uuid'],
            'to_account_uuid'       => $approvalPayload['to_account_uuid'],
            'amount'                => $approvalPayload['amount'],
            'asset_code'            => $approvalPayload['asset_code'],
            'note'                  => $approvalPayload['note'],
            'merchant_category'     => $approvalPayload['merchant_category'],
            'status'                => 'pending',
            'expires_at'            => now()->addHours(24),
        ]);

        return [
            'success' => true,
            'message' => 'This transaction requires guardian approval.',
            'data'    => [
                'approval_id' => $approval->id,
                'status'      => 'pending_guardian_approval',
                'expires_at'  => $approval->expires_at->toISOString(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $approvalPayload
     */
    private function minorApprovalPayloadHash(array $approvalPayload): string
    {
        return hash('sha256', (string) json_encode($approvalPayload));
    }
}
