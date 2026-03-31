<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Mtn;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\MtnMomo\Services\MtnMomoCollectionSettler;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Http\Controllers\Controller;
use App\Models\MtnMomoTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/mtn/callback — MTN MoMo IPN (no Sanctum; verify X-Callback-Token).
 */
class CallbackController extends Controller
{
    public function __construct(
        private readonly MtnMomoCollectionSettler $collectionSettler,
        private readonly WalletOperationsService $walletOps,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (config('mtn_momo.verify_callback_token', true)) {
            $expected = (string) config('mtn_momo.callback_token', '');

            if ($expected === '') {
                Log::warning('MTN callback token verification is enabled but MTNMOMO_CALLBACK_TOKEN is not set.');

                return response('', 401);
            }

            $incoming = (string) $request->header('X-Callback-Token', '');

            if ($incoming === '' || ! hash_equals($expected, $incoming)) {
                return response('', 401);
            }
        }

        $referenceId = (string) $request->header('X-Reference-Id', '');
        if ($referenceId === '') {
            return response('', 400);
        }

        /** @var array<string, mixed> $body */
        $body = $request->all();

        $remoteStatus = $this->mtnStatusFrom($body);
        $normalized = MtnMomoTransaction::normaliseRemoteStatus($remoteStatus);
        $financialId = $this->mtnFinancialIdFrom($body);

        // Replay protection: terminal-state callbacks (SUCCESSFUL / FAILED) are processed
        // exactly once per reference ID. A duplicate unique key means the work is already done.
        $terminalStatuses = [MtnMomoTransaction::STATUS_SUCCESSFUL, MtnMomoTransaction::STATUS_FAILED];
        if (in_array($normalized, $terminalStatuses, true)) {
            try {
                DB::table('mtn_callback_log')->insert([
                    'mtn_reference_id' => $referenceId,
                    'terminal_status'  => $normalized,
                    'body_sha256'      => hash('sha256', $request->getContent()),
                    'received_at'      => now(),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            } catch (QueryException) {
                // Duplicate: already processed this terminal state for this reference.
                return response('', 200);
            }
        }

        $txn = MtnMomoTransaction::query()
            ->where('mtn_reference_id', $referenceId)
            ->first();

        if ($txn === null) {
            Log::warning('MTN callback received for unknown reference ID', [
                'mtn_reference_id' => $referenceId,
            ]);

            // Return 200 to prevent MTN from retrying; do not leak whether the ID exists.
            return response('', 200);
        }

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
            $user = $fresh->user;
            if ($user !== null) {
                $this->collectionSettler->creditIfNeeded($fresh, $user);
            }
        }

        // Auto-refund if MTN later marks an accepted disbursement as FAILED.
        if (
            $fresh !== null
            && $fresh->type === MtnMomoTransaction::TYPE_DISBURSEMENT
            && $normalized === MtnMomoTransaction::STATUS_FAILED
            && $fresh->wallet_debited_at !== null
            && $fresh->wallet_refunded_at === null
        ) {
            $this->refundDisbursementIfNeeded($fresh);
        }

        return response('', 200);
    }

    /**
     * Refund the user's wallet when a previously debited disbursement is marked FAILED by MTN.
     *
     * Uses lockForUpdate inside a transaction to guard against concurrent refund attempts
     * (e.g. this callback handler racing with a future reconciliation cron).
     */
    private function refundDisbursementIfNeeded(MtnMomoTransaction $txn): void
    {
        DB::transaction(function () use ($txn): void {
            /** @var MtnMomoTransaction|null $locked */
            $locked = MtnMomoTransaction::query()
                ->where('id', $txn->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            // Double-check under lock: another process may have refunded between our
            // pre-flight check and this lock acquisition.
            if ($locked->wallet_debited_at === null || $locked->wallet_refunded_at !== null) {
                return;
            }

            $user = $locked->user;

            if ($user === null) {
                Log::error('MTN disbursement callback refund: user not found', [
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
                Log::critical('MTN disbursement callback refund: account not found', [
                    'mtn_momo_transaction_id' => $locked->id,
                    'user_id'                 => $user->id,
                ]);

                return;
            }

            $asset = Asset::query()->where('code', $locked->currency)->first();

            if ($asset === null) {
                Log::critical('MTN disbursement callback refund: asset not found', [
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
                Log::critical('MTN disbursement callback refund failed — funds may be lost', [
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
}
