<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Domain\Account\Services\MinorFamilyReconciliationService;
use App\Domain\Asset\Models\Asset;
use App\Domain\MtnMomo\Services\MtnMomoClient;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\MtnMomoTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconcile pending MTN MoMo disbursements against the MTN status API.
 *
 * Problem: DisbursementController debits the wallet before calling MTN. If MTN
 * returns 202 (async accepted) but later marks the transfer FAILED — and the
 * callback is never delivered — the wallet is permanently short without the user
 * receiving funds. This command polls MTN for each pending-but-debited
 * disbursement and issues a refund when MTN confirms failure.
 *
 * Safety guards:
 * - min-age window avoids racing with legitimate in-flight callbacks.
 * - DB::transaction + lockForUpdate prevents double-refund under concurrent runs.
 * - Log::critical on refund failure (funds-loss path) mirrors DisbursementController.
 * - --dry-run performs no writes; safe to run manually for inspection.
 */
class ReconcileMtnMomoTransactions extends Command
{
    protected $signature = 'mtn:reconcile-disbursements
                            {--dry-run : Log planned work only; no wallet writes or status updates}
                            {--min-age=15 : Minimum age in minutes before reconciling a pending row (default 15)}
                            {--chunk=100 : Number of records to process per batch}';

    protected $description = 'Reconcile pending MTN MoMo disbursements: poll MTN status and refund wallet on FAILED';

    public function __construct(
        private readonly MtnMomoClient $mtnClient,
        private readonly WalletOperationsService $walletOps,
        private readonly MinorFamilyReconciliationService $minorFamilyReconciliation,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $minAgeMinutes = max(1, (int) $this->option('min-age'));
        $chunkSize = max(1, (int) $this->option('chunk'));
        $cutoff = now()->subMinutes($minAgeMinutes);

        if ($dryRun) {
            $this->info('[dry-run] No writes will be performed.');
        }

        $ids = MtnMomoTransaction::query()
            ->where('status', MtnMomoTransaction::STATUS_PENDING)
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($disbursementQuery) use ($cutoff): void {
                    $disbursementQuery->where('type', MtnMomoTransaction::TYPE_DISBURSEMENT)
                        ->whereNotNull('wallet_debited_at')
                        ->whereNull('wallet_refunded_at')
                        ->where('wallet_debited_at', '<=', $cutoff);
                })->orWhere(function ($phase9Query) use ($cutoff): void {
                    $phase9Query->whereIn('context_type', [
                        MinorFamilyFundingAttempt::class,
                        MinorFamilySupportTransfer::class,
                    ])->where('created_at', '<=', $cutoff);
                });
            })
            ->orderByRaw('COALESCE(wallet_debited_at, created_at) asc')
            ->limit($chunkSize)
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('No pending disbursements to reconcile.');

            return Command::SUCCESS;
        }

        $this->info(sprintf('Reconciling %d pending disbursement(s)…', $ids->count()));

        $reconciled = 0;
        $refunded = 0;
        $unreconciled = 0;
        $errors = 0;

        foreach ($ids as $txnId) {
            $outcome = $this->reconcileOne((string) $txnId, $dryRun);

            match ($outcome) {
                'refunded' => $refunded++,
                'settled'  => $reconciled++,
                'pending'  => null,
                'unreconciled' => $unreconciled++,
                default    => $errors++,
            };
        }

        $this->info(sprintf(
            'Done. settled=%d refunded=%d unreconciled=%d still_pending=%d errors=%d',
            $reconciled,
            $refunded,
            $unreconciled,
            $ids->count() - $reconciled - $refunded - $unreconciled - $errors,
            $errors,
        ));

        return ($errors > 0 || $unreconciled > 0) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Reconcile a single disbursement row.
     *
     * @return 'refunded'|'settled'|'pending'|'unreconciled'|'error'
     */
    private function reconcileOne(string $txnId, bool $dryRun): string
    {
        try {
            return DB::transaction(function () use ($txnId, $dryRun): string {
                /** @var MtnMomoTransaction|null $txn */
                $txn = MtnMomoTransaction::query()
                    ->whereKey($txnId)
                    ->lockForUpdate()
                    ->first();

                // Guard: may have been settled by a callback while we were iterating.
                if ($txn === null || $txn->status !== MtnMomoTransaction::STATUS_PENDING) {
                    return 'settled';
                }

                $referenceId = $txn->mtn_reference_id;

                if (! is_string($referenceId) || $referenceId === '') {
                    Log::warning('ReconcileMtnMomoTransactions: missing mtn_reference_id, skipping', [
                        'mtn_momo_transaction_id' => $txnId,
                    ]);

                    return 'error';
                }

                $remoteStatus = $this->fetchRemoteStatus($referenceId, $txnId);

                if ($remoteStatus === null) {
                    return 'error';
                }

                $normalisedStatus = MtnMomoTransaction::normaliseRemoteStatus($remoteStatus);

                $this->info(sprintf(
                    '  txn=%s ref=%s remote=%s → %s%s',
                    $txnId,
                    $referenceId,
                    $remoteStatus,
                    $normalisedStatus,
                    $dryRun ? ' [dry-run]' : '',
                ));

                if ($dryRun) {
                    return match ($normalisedStatus) {
                        MtnMomoTransaction::STATUS_SUCCESSFUL => 'settled',
                        MtnMomoTransaction::STATUS_FAILED     => 'refunded',
                        default                               => 'pending',
                    };
                }

                if ($this->isMinorFamilyContext($txn)) {
                    return $this->reconcileMinorFamilyTransaction($txn, $normalisedStatus, $remoteStatus);
                }

                return match ($normalisedStatus) {
                    MtnMomoTransaction::STATUS_SUCCESSFUL => $this->markSuccessful($txn, $remoteStatus),
                    MtnMomoTransaction::STATUS_FAILED => $this->refundAndFail($txn, $remoteStatus),
                    default => $this->updateLastStatus($txn, $remoteStatus),
                };
            });
        } catch (Throwable $e) {
            Log::error('ReconcileMtnMomoTransactions: unexpected error', [
                'mtn_momo_transaction_id' => $txnId,
                'error'                   => $e->getMessage(),
                'exception'               => $e::class,
            ]);

            return 'error';
        }
    }

    /**
     * @return 'settled'
     */
    private function markSuccessful(MtnMomoTransaction $txn, string $remoteStatus): string
    {
        $txn->update([
            'status'          => MtnMomoTransaction::STATUS_SUCCESSFUL,
            'last_mtn_status' => $remoteStatus,
        ]);

        Log::info('ReconcileMtnMomoTransactions: disbursement confirmed successful', [
            'mtn_momo_transaction_id' => $txn->id,
            'mtn_reference_id'        => $txn->mtn_reference_id,
        ]);

        return 'settled';
    }

    /**
     * @return 'refunded'|'error'
     */
    private function refundAndFail(MtnMomoTransaction $txn, string $remoteStatus): string
    {
        $user = $txn->user;

        if (! $user) {
            Log::critical('ReconcileMtnMomoTransactions: user not found — cannot refund', [
                'mtn_momo_transaction_id' => $txn->id,
                'user_id'                 => $txn->user_id,
            ]);
            $txn->update([
                'status'          => MtnMomoTransaction::STATUS_FAILED,
                'last_mtn_status' => $remoteStatus,
            ]);

            return 'error';
        }

        $account = Account::query()
            ->where('user_uuid', $user->uuid)
            ->orderBy('id')
            ->first();

        if (! $account) {
            Log::critical('ReconcileMtnMomoTransactions: account not found — cannot refund', [
                'mtn_momo_transaction_id' => $txn->id,
                'user_id'                 => $txn->user_id,
            ]);
            $txn->update([
                'status'          => MtnMomoTransaction::STATUS_FAILED,
                'last_mtn_status' => $remoteStatus,
            ]);

            return 'error';
        }

        $asset = Asset::query()->where('code', $txn->currency)->first();

        if (! $asset) {
            Log::critical('ReconcileMtnMomoTransactions: unknown currency — cannot refund', [
                'mtn_momo_transaction_id' => $txn->id,
                'currency'                => $txn->currency,
            ]);
            $txn->update([
                'status'          => MtnMomoTransaction::STATUS_FAILED,
                'last_mtn_status' => $remoteStatus,
            ]);

            return 'error';
        }

        $amountMinor = MoneyConverter::forAsset($txn->amount, $asset);
        $referenceId = (string) ($txn->mtn_reference_id ?? $txn->id);

        try {
            $this->walletOps->deposit(
                $account->uuid,
                $txn->currency,
                (string) $amountMinor,
                'mtn-disburse-refund:' . $referenceId,
                ['mtn_momo_transaction_id' => $txn->id],
            );

            $txn->update([
                'status'             => MtnMomoTransaction::STATUS_FAILED,
                'last_mtn_status'    => $remoteStatus,
                'wallet_refunded_at' => now(),
            ]);

            Log::info('ReconcileMtnMomoTransactions: refunded failed disbursement', [
                'mtn_momo_transaction_id' => $txn->id,
                'mtn_reference_id'        => $referenceId,
                'amount'                  => $txn->amount,
                'currency'                => $txn->currency,
            ]);

            return 'refunded';
        } catch (Throwable $e) {
            Log::critical('ReconcileMtnMomoTransactions: refund failed — funds may be lost', [
                'mtn_momo_transaction_id' => $txn->id,
                'mtn_reference_id'        => $referenceId,
                'user_id'                 => $txn->user_id,
                'amount'                  => $txn->amount,
                'currency'                => $txn->currency,
                'error'                   => $e->getMessage(),
            ]);

            $txn->update([
                'status'          => MtnMomoTransaction::STATUS_FAILED,
                'last_mtn_status' => $remoteStatus,
            ]);

            return 'error';
        }
    }

    /**
     * MTN still processing — update last_mtn_status for observability, leave pending.
     *
     * @return 'pending'
     */
    private function updateLastStatus(MtnMomoTransaction $txn, string $remoteStatus): string
    {
        $txn->update(['last_mtn_status' => $remoteStatus]);

        return 'pending';
    }

    /**
     * Fetch remote transfer status from MTN. Returns null on API error (logged internally).
     */
    private function fetchRemoteStatus(string $referenceId, string $txnId): ?string
    {
        try {
            /** @var MtnMomoTransaction|null $txn */
            $txn = MtnMomoTransaction::query()->find($txnId);
            if ($txn === null) {
                return null;
            }

            $statusData = $txn->type === MtnMomoTransaction::TYPE_REQUEST_TO_PAY
                ? $this->mtnClient->getRequestToPayStatus($referenceId)
                : $this->mtnClient->getTransferStatus($referenceId);
            $raw = $statusData['status'] ?? null;

            return is_string($raw) ? $raw : null;
        } catch (Throwable $e) {
            Log::error('ReconcileMtnMomoTransactions: MTN status API error', [
                'mtn_reference_id'        => $referenceId,
                'mtn_momo_transaction_id' => $txnId,
                'error'                   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return 'settled'|'refunded'|'pending'|'unreconciled'
     */
    private function reconcileMinorFamilyTransaction(
        MtnMomoTransaction $txn,
        string $normalisedStatus,
        string $remoteStatus,
    ): string {
        if ($normalisedStatus === MtnMomoTransaction::STATUS_PENDING) {
            return $this->updateLastStatus($txn, $remoteStatus);
        }

        $txn->update([
            'status' => $normalisedStatus,
            'last_mtn_status' => $remoteStatus,
        ]);

        $reconciliationOutcome = $this->minorFamilyReconciliation->reconcile($txn->fresh() ?? $txn, 'reconcile_command');

        if (! $reconciliationOutcome->isReconciled()) {
            return 'unreconciled';
        }

        if ($normalisedStatus !== MtnMomoTransaction::STATUS_FAILED) {
            return 'settled';
        }

        if ((string) ($txn->getAttribute('context_type') ?? '') !== MinorFamilySupportTransfer::class) {
            return 'settled';
        }

        /** @var MinorFamilySupportTransfer|null $transfer */
        $transfer = MinorFamilySupportTransfer::query()
            ->where('mtn_momo_transaction_id', $txn->id)
            ->first();

        if ($transfer?->status === MinorFamilySupportTransfer::STATUS_FAILED_REFUNDED) {
            return 'refunded';
        }

        return 'settled';
    }

    private function isMinorFamilyContext(MtnMomoTransaction $txn): bool
    {
        $contextType = (string) ($txn->getAttribute('context_type') ?? '');

        return in_array($contextType, [
            MinorFamilyFundingAttempt::class,
            MinorFamilySupportTransfer::class,
        ], true);
    }
}
