<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\SendMoney;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Domain\Shared\Money\MoneyConverter;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\MajorUnitAmountString;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * POST /api/send-money/store — MaphaPay compatibility (Phase 5).
 */
class SendMoneyStoreController extends Controller
{
    public function __construct(
        private readonly AuthorizedTransactionManager $authorizedTransactionManager,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user'              => ['required', 'string'],
            'amount'            => ['required', 'string', new MajorUnitAmountString()],
            'note'              => ['sometimes', 'nullable', 'string', 'max:2000'],
            'verification_type' => ['sometimes', 'nullable', 'string', Rule::in(['sms', 'email', 'pin'])],
            'asset_code'        => ['sometimes', 'string', 'exists:assets,code'],
        ]);

        /** @var User $authUser */
        $authUser = $request->user();

        $recipient = $this->resolvePayeeUser((string) $validated['user']);
        if (! $recipient) {
            return $this->errorResponse('Recipient not found.', 422);
        }

        if ((int) $recipient->id === (int) $authUser->id) {
            return $this->errorResponse('You cannot send money to yourself.', 422);
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
            return $this->errorResponse('Sender wallet account not found or is frozen.', 422);
        }

        if (! $toAccount) {
            return $this->errorResponse('Recipient wallet account not found.', 422);
        }

        $assetCode = $validated['asset_code'] ?? 'SZL';
        $asset = Asset::query()->where('code', $assetCode)->first();
        if (! $asset) {
            return $this->errorResponse("Unknown asset: {$assetCode}", 422);
        }

        try {
            $normalizedAmount = MoneyConverter::normalise($validated['amount'], $asset->precision);
        } catch (InvalidArgumentException) {
            return $this->errorResponse('Invalid amount.', 422);
        }

        $verificationType = match ($validated['verification_type'] ?? null) {
            'pin'   => AuthorizedTransaction::VERIFICATION_PIN,
            default => AuthorizedTransaction::VERIFICATION_OTP,
        };

        $payload = [
            'from_account_uuid' => $fromAccount->uuid,
            'to_account_uuid'   => $toAccount->uuid,
            'amount'            => $normalizedAmount,
            'asset_code'        => $asset->code,
            'note'              => $validated['note'] ?? '',
        ];

        $idempotencyKey = (string) $request->header('Idempotency-Key', '')
            ?: (string) $request->header('X-Idempotency-Key', '');

        $txn = $this->authorizedTransactionManager->initiate(
            (int) $authUser->getAuthIdentifier(),
            AuthorizedTransaction::REMARK_SEND_MONEY,
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

    private function errorResponse(string $message, int $status): JsonResponse
    {
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
            return User::query()->find((int) $user);
        }

        return User::query()->where('email', $user)->first();
    }
}
