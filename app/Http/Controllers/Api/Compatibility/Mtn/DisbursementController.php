<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Mtn;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\MtnMomo\Services\MtnMomoClient;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Http\Controllers\Controller;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use App\Rules\MajorUnitAmountString;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * POST /api/mtn/disbursement — MaphaPay MTN MoMo disbursement (Phase 15).
 */
class DisbursementController extends Controller
{
    public function __construct(
        private readonly MtnMomoClient $mtnMomoClient,
        private readonly WalletOperationsService $walletOps,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:191'],
            'amount'          => ['required', 'string', new MajorUnitAmountString()],
            'payee_msisdn'    => ['required', 'string', 'regex:/^\+?[0-9]{8,15}$/'],
            'note'            => ['sometimes', 'nullable', 'string', 'max:2000'],
            'currency'        => ['sometimes', 'string', 'max:8'],
        ]);

        /** @var User $authUser */
        $authUser = $request->user();

        $existing = MtnMomoTransaction::query()
            ->where('user_id', $authUser->id)
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($existing !== null) {
            return $this->successResponse($existing);
        }

        $currency = strtoupper((string) ($validated['currency'] ?? config('mtn_momo.currency', 'SZL')));
        $asset = Asset::query()->where('code', $currency)->first();

        if (! $asset) {
            return $this->errorResponse("Unknown asset/currency: {$currency}", 422);
        }

        try {
            $normalizedAmount = MoneyConverter::normalise($validated['amount'], $asset->precision);
        } catch (InvalidArgumentException) {
            return $this->errorResponse('Invalid amount.', 422);
        }

        $fromAccount = Account::query()
            ->where('user_uuid', $authUser->uuid)
            ->orderBy('id')
            ->first();

        if (! $fromAccount || $fromAccount->frozen) {
            return $this->errorResponse('Wallet account not found or is frozen.', 422);
        }

        $amountMinor = MoneyConverter::forAsset($normalizedAmount, $asset);

        if (! $fromAccount->hasSufficientBalance($currency, $amountMinor)) {
            return $this->errorResponse('Insufficient balance.', 422);
        }

        $referenceId = (string) Str::uuid();
        $recordId = (string) Str::uuid();

        try {
            DB::transaction(function () use (
                $authUser,
                $validated,
                $normalizedAmount,
                $currency,
                $fromAccount,
                $amountMinor,
                $referenceId,
                $recordId,
            ): void {
                $this->walletOps->withdraw(
                    $fromAccount->uuid,
                    $currency,
                    (string) $amountMinor,
                    'mtn-disburse:' . $referenceId,
                    [
                        'mtn_momo_transaction_id' => $recordId,
                    ],
                );

                MtnMomoTransaction::query()->create([
                    'id'                => $recordId,
                    'user_id'           => $authUser->id,
                    'idempotency_key'   => $validated['idempotency_key'],
                    'type'              => MtnMomoTransaction::TYPE_DISBURSEMENT,
                    'amount'            => $normalizedAmount,
                    'currency'          => $currency,
                    'status'            => MtnMomoTransaction::STATUS_PENDING,
                    'party_msisdn'      => (string) $validated['payee_msisdn'],
                    'mtn_reference_id'  => $referenceId,
                    'note'              => $validated['note'] ?? null,
                    'wallet_debited_at' => now(),
                ]);
            });
        } catch (InsufficientBalanceException) {
            return $this->errorResponse('Insufficient balance.', 422);
        } catch (Throwable $e) {
            // A unique-constraint violation means a concurrent request with the same
            // idempotency key won the race — return the existing record idempotently.
            $raceExisting = MtnMomoTransaction::query()
                ->where('user_id', $authUser->id)
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();

            if ($raceExisting !== null) {
                return $this->successResponse($raceExisting);
            }

            Log::error('MTN disbursement fund reservation failed', [
                'user_id'         => $authUser->id,
                'idempotency_key' => $validated['idempotency_key'],
                'error'           => $e->getMessage(),
            ]);

            return $this->errorResponse('Could not reserve funds for disbursement.', 503);
        }

        /** @var MtnMomoTransaction $txn */
        $txn = MtnMomoTransaction::query()->findOrFail($recordId);

        try {
            $this->mtnMomoClient->assertConfigured();
            $this->mtnMomoClient->disburse(
                $referenceId,
                $normalizedAmount,
                $currency,
                (string) $validated['payee_msisdn'],
                $validated['idempotency_key'],
                (string) ($validated['note'] ?? ''),
                'MaphaPay disbursement',
            );
        } catch (RuntimeException $e) {
            $this->refundAndFail($txn, $fromAccount, $currency, $amountMinor, $referenceId);
            Log::error('MTN disbursement API call failed', [
                'mtn_reference_id' => $referenceId,
                'user_id'          => $authUser->id,
                'error'            => $e->getMessage(),
            ]);

            return $this->errorResponse('MTN disbursement could not be completed.', 503);
        } catch (Throwable $e) {
            $this->refundAndFail($txn, $fromAccount, $currency, $amountMinor, $referenceId);
            Log::error('MTN disbursement unexpected error', [
                'mtn_reference_id' => $referenceId,
                'user_id'          => $authUser->id,
                'error'            => $e->getMessage(),
            ]);

            return $this->errorResponse('MTN disbursement could not be completed.', 503);
        }

        return $this->successResponse($txn->fresh() ?? $txn);
    }

    private function refundAndFail(
        MtnMomoTransaction $txn,
        Account $fromAccount,
        string $currency,
        int $amountMinor,
        string $referenceId,
    ): void {
        try {
            $this->walletOps->deposit(
                $fromAccount->uuid,
                $currency,
                (string) $amountMinor,
                'mtn-disburse-refund:' . $referenceId,
                ['mtn_momo_transaction_id' => $txn->id],
            );

            $txn->update([
                'status'             => MtnMomoTransaction::STATUS_FAILED,
                'wallet_refunded_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::critical('MTN disbursement refund failed — funds may be lost', [
                'mtn_reference_id'        => $referenceId,
                'mtn_momo_transaction_id' => $txn->id,
                'user_id'                 => $fromAccount->user_uuid ?? null,
                'amount_minor'            => $amountMinor,
                'currency'                => $currency,
                'error'                   => $e->getMessage(),
            ]);

            $txn->update(['status' => MtnMomoTransaction::STATUS_FAILED]);
        }
    }

    private function successResponse(MtnMomoTransaction $txn): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'remark' => 'mtn_disbursement',
            'data'   => [
                'transaction' => $this->transactionData($txn),
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
            'remark'  => 'mtn_disbursement',
            'message' => [$message],
        ];
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json($this->errorPayload($message), $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionData(MtnMomoTransaction $txn): array
    {
        return [
            'id'                           => $txn->id,
            'idempotency_key'              => $txn->idempotency_key,
            'type'                         => $txn->type,
            'amount'                       => $txn->amount,
            'currency'                     => $txn->currency,
            'status'                       => $txn->status,
            'party_msisdn'                 => $txn->party_msisdn,
            'mtn_reference_id'             => $txn->mtn_reference_id,
            'mtn_financial_transaction_id' => $txn->mtn_financial_transaction_id,
            'note'                         => $txn->note,
        ];
    }
}
