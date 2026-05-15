<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Wallet\Contracts\WalletMovementStatus;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Providers\WalletProviderRegistry;
use App\Domain\Wallet\Services\MoneySettlerService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconcile stuck PENDING wallet_provider_transactions across all providers.
 *
 * Problem: when a real provider's webhook is delayed or never delivered,
 * rows can remain PENDING indefinitely while the user's wallet stays in
 * an inconsistent state (especially for disbursements where the wallet
 * was already debited). This command polls each provider's status API
 * via the registered adapter and replays the result through the
 * MoneySettlerService dispatcher — same code path as a real webhook.
 *
 * Safety guards:
 * - --min-age window avoids racing with legitimate in-flight callbacks.
 * - --dry-run logs intent without writes.
 * - Exceptions per row are isolated; the loop continues.
 */
class ReconcileWalletProviderTransactions extends Command
{
    protected $signature = 'wallet:reconcile
                            {--provider= : Limit reconciliation to one provider_id}
                            {--min-age=15 : Minimum age in minutes before reconciling a pending row}
                            {--chunk=100 : Max rows to process this run}
                            {--dry-run : Log planned actions only; no settler writes}';

    protected $description = 'Reconcile stuck PENDING wallet_provider_transactions by polling provider status';

    public function __construct(
        private readonly WalletProviderRegistry $registry,
        private readonly MoneySettlerService $settler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $minAgeMinutes = max(0, (int) $this->option('min-age'));
        $chunk = max(1, (int) $this->option('chunk'));
        $providerFilter = (string) ($this->option('provider') ?? '');
        $dryRun = (bool) $this->option('dry-run');

        $query = WalletProviderTransaction::query()
            ->where('status', WalletProviderTransaction::STATUS_PENDING)
            ->where('created_at', '<=', Carbon::now()->subMinutes($minAgeMinutes))
            ->orderBy('created_at')
            ->limit($chunk);

        if ($providerFilter !== '') {
            $query->where('provider_id', $providerFilter);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('No pending wallet provider transactions to reconcile.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Reconciling %d pending row(s)…', $rows->count()));

        $reconciledTerminal = 0;
        $stillPending = 0;
        $errors = 0;

        foreach ($rows as $row) {
            try {
                $adapter = $this->registry->for($row->provider_id);
                $status = $adapter->status($row->provider_request_id);

                $remote = match ($status->status) {
                    WalletMovementStatus::STATUS_SUCCESSFUL => 'SUCCESSFUL',
                    WalletMovementStatus::STATUS_FAILED     => 'FAILED',
                    default                                 => 'PENDING',
                };

                if ($remote === 'PENDING') {
                    $stillPending++;
                    $this->line(sprintf(
                        '  %s/%s: still pending — skipping',
                        $row->provider_id,
                        $row->provider_request_id,
                    ));

                    continue;
                }

                if ($dryRun) {
                    $reconciledTerminal++;
                    $this->line(sprintf(
                        '  [dry-run] would settle %s/%s as %s',
                        $row->provider_id,
                        $row->provider_request_id,
                        $remote,
                    ));

                    continue;
                }

                $this->settler->settle(
                    $row->provider_id,
                    $row->provider_request_id,
                    $remote,
                    [
                        'reconciled_via'    => 'wallet:reconcile',
                        'reconciled_at'     => now()->toIso8601String(),
                        'failure_reason'    => $status->failureReason,
                        'remote_settled_at' => $status->settledAt,
                    ],
                );

                $reconciledTerminal++;
                $this->line(sprintf(
                    '  %s/%s: settled as %s',
                    $row->provider_id,
                    $row->provider_request_id,
                    $remote,
                ));
            } catch (Throwable $e) {
                $errors++;
                Log::warning('Wallet reconcile failed for row', [
                    'provider_id'         => $row->provider_id,
                    'provider_request_id' => $row->provider_request_id,
                    'error'               => $e->getMessage(),
                ]);
                $this->error(sprintf(
                    '  %s/%s: error — %s',
                    $row->provider_id,
                    $row->provider_request_id,
                    $e->getMessage(),
                ));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. settled=%d still_pending=%d errors=%d',
            $reconciledTerminal,
            $stillPending,
            $errors,
        ));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
