<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\ScheduledSend;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Domain\Shared\Money\MoneyConverter;
use App\Http\Controllers\Controller;
use App\Models\ScheduledSend;
use App\Models\User;
use App\Rules\MajorUnitAmountString;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * POST /api/scheduled-send/store — MaphaPay compatibility (Phase 5).
 */
class ScheduledSendStoreController extends Controller
{
    public function __construct(
        private readonly AuthorizedTransactionManager $authorizedTransactionManager,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
            'amount'            => ['required', 'string', new MajorUnitAmountString()],
            'asset_code'        => ['sometimes', 'string', 'exists:assets,code'],
            'scheduled_for'     => ['required', 'date', 'after:now', 'before:+1 year'],
            'note'              => ['sometimes', 'nullable', 'string', 'max:2000'],
            'verification_type' => ['sometimes', 'nullable', 'string', Rule::in(['sms', 'email', 'pin'])],
        ]);

        /** @var User $authUser */
        $authUser = $request->user();

        $recipientId = (int) $validated['recipient_user_id'];
        if ($recipientId === (int) $authUser->getAuthIdentifier()) {
            return $this->errorResponse('You cannot schedule a send to yourself.', 422);
        }

        /** @var User $recipient */
        $recipient = User::query()->findOrFail($recipientId);

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

        $scheduledFor = Carbon::parse((string) $validated['scheduled_for']);

        [$txn, $codeSentMessage] = DB::transaction(function () use (
            $authUser,
            $recipient,
            $fromAccount,
            $toAccount,
            $normalizedAmount,
            $asset,
            $validated,
            $verificationType,
            $scheduledFor,
        ): array {
            $scheduledSend = ScheduledSend::query()->create([
                'id'                => (string) Str::uuid(),
                'sender_user_id'    => (int) $authUser->getAuthIdentifier(),
                'recipient_user_id' => (int) $recipient->getAuthIdentifier(),
                'amount'            => $normalizedAmount,
                'asset_code'        => $asset->code,
                'note'              => $validated['note'] ?? null,
                'scheduled_for'     => $scheduledFor,
                'status'            => ScheduledSend::STATUS_PENDING,
            ]);

            $payload = [
                'scheduled_send_id' => $scheduledSend->id,
                'from_account_uuid' => $fromAccount->uuid,
                'to_account_uuid'   => $toAccount->uuid,
                'amount'            => $normalizedAmount,
                'asset_code'        => $asset->code,
                'note'              => $validated['note'] ?? '',
                'scheduled_at'      => $scheduledFor->toIso8601String(),
            ];

            $txn = $this->authorizedTransactionManager->initiate(
                (int) $authUser->getAuthIdentifier(),
                AuthorizedTransaction::REMARK_SCHEDULED_SEND,
                $payload,
                $verificationType,
            );

            $scheduledSend->update(['trx' => $txn->trx]);

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

        return response()->json([
            'status' => 'success',
            'remark' => 'scheduled_send',
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
            'remark'  => 'scheduled_send',
            'message' => [$message],
        ];
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json($this->errorPayload($message), $status);
    }
}
