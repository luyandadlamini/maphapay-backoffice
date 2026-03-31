<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Mtn;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\MtnMomo\Services\MtnMomoClient;
use App\Domain\MtnMomo\Services\MtnMomoCollectionSettler;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Http\Controllers\Controller;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * GET /api/mtn/transaction/{referenceId}/status — poll MTN + refresh row (Phase 15).
 */
class TransactionStatusController extends Controller
{
    public function __construct(
        private readonly MtnMomoClient $mtnMomoClient,
        private readonly MtnMomoCollectionSettler $collectionSettler,
        private readonly WalletOperationsService $walletOps,
    ) {
    }

    public function __invoke(Request $request, string $referenceId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $txn = MtnMomoTransaction::query()
            ->where('user_id', $authUser->id)
            ->where('mtn_reference_id', $referenceId)
            ->first();

        if ($txn === null) {
            return $this->errorResponse('Transaction not found.', 404);
        }

        try {
            $this->mtnMomoClient->assertConfigured();

            /** @var array<string, mixed> $payload */
            $payload = $txn->type === MtnMomoTransaction::TYPE_REQUEST_TO_PAY
                ? $this->mtnMomoClient->getRequestToPayStatus($referenceId)
                : $this->mtnMomoClient->getTransferStatus($referenceId);
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 503);
        } catch (Throwable) {
            return $this->errorResponse('Could not load MTN transaction status.', 503);
        }

        $remoteStatus = $this->mtnStatusFrom($payload);
        $normalized = MtnMomoTransaction::normaliseRemoteStatus($remoteStatus);
        $financialId = $this->mtnFinancialIdFrom($payload);

        $txn->update([
            'last_mtn_status'              => $remoteStatus,
            'status'                       => $normalized,
            'mtn_financial_transaction_id' => $financialId ?? $txn->mtn_financial_transaction_id,
        ]);

        $fresh = $txn->fresh();

        if (
            $fresh !== null
            && $fresh->type === MtnMomoTransaction::TYPE_REQUEST_TO_PAY
            && $normalized === MtnMomoTransaction::STATUS_SUCCESSFUL
        ) {
            $this->collectionSettler->creditIfNeeded($fresh, $authUser);
            $fresh = $fresh->fresh();
        }

        if (
            $fresh !== null
            && $fresh->type === MtnMomoTransaction::TYPE_DISBURSEMENT
            && $normalized === MtnMomoTransaction::STATUS_FAILED
            && $fresh->wallet_debited_at !== null
            && $fresh->wallet_refunded_at === null
        ) {
            $this->refundDisbursementIfNeeded($fresh);
            $fresh = $fresh->fresh();
        }

        return response()->json([
            'status' => 'success',
            'remark' => 'mtn_transaction_status',
            'data'   => [
                'transaction' => $this->transactionData($fresh ?? $txn),
            ],
        ]);
    }

    private function refundDisbursementIfNeeded(MtnMomoTransaction $txn): void
    {
        DB::transaction(function () use ($txn): void {
            /** @var MtnMomoTransaction|null $locked */
            $locked = MtnMomoTransaction::query()
                ->whereKey($txn->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->wallet_debited_at === null || $locked->wallet_refunded_at !== null) {
                return;
            }

            $user = $locked->user;

            if ($user === null) {
                Log::error('MTN disbursement status refund: user not found', [
                    'mtn_momo_transaction_id' => $locked->id,
                    'user_id'                 => $locked->user_id,
                ]);

                return;
            }

            $account = Account::query()
                ->where('user_uuid', $user->uuid)
                ->orderBy('id')
                ->first();

            if ($account === null) {
                Log::critical('MTN disbursement status refund: account not found', [
                    'mtn_momo_transaction_id' => $locked->id,
                    'user_id'                 => $user->id,
                ]);

                return;
            }

            $asset = Asset::query()->where('code', $locked->currency)->first();

            if ($asset === null) {
                Log::critical('MTN disbursement status refund: asset not found', [
                    'mtn_momo_transaction_id' => $locked->id,
                    'currency'                => $locked->currency,
                ]);

                return;
            }

            $amountMinor = MoneyConverter::forAsset($locked->amount, $asset);

            try {
                $this->walletOps->deposit(
                    $account->uuid,
                    $locked->currency,
                    (string) $amountMinor,
                    'mtn-disburse-refund:' . $locked->mtn_reference_id,
                    ['mtn_momo_transaction_id' => $locked->id],
                );

                $locked->update(['wallet_refunded_at' => now()]);
            } catch (Throwable $e) {
                Log::critical('MTN disbursement status refund failed — funds may be lost', [
                    'mtn_reference_id'        => $locked->mtn_reference_id,
                    'mtn_momo_transaction_id' => $locked->id,
                    'user_id'                 => $user->id,
                    'amount_minor'            => $amountMinor,
                    'currency'                => $locked->currency,
                    'error'                   => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mtnStatusFrom(array $payload): ?string
    {
        $s = $payload['status'] ?? null;

        return is_string($s) ? $s : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mtnFinancialIdFrom(array $payload): ?string
    {
        foreach (['financialTransactionId', 'financial_transaction_id'] as $key) {
            $v = $payload[$key] ?? null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * @return array{status: string, remark: string, message: array<int, string>}
     */
    private function errorPayload(string $message): array
    {
        return [
            'status'  => 'error',
            'remark'  => 'mtn_transaction_status',
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
            'last_mtn_status'              => $txn->last_mtn_status,
        ];
    }
}
