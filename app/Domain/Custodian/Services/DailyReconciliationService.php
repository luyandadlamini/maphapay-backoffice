<?php

declare(strict_types=1);

namespace App\Domain\Custodian\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Custodian\Enums\ProviderReconciliationStatus;
use App\Domain\Custodian\Models\CustodianWebhook;
use App\Domain\Custodian\Models\ProviderOperation;
use App\Domain\Custodian\Events\ReconciliationCompleted;
use App\Domain\Custodian\Events\ReconciliationDiscrepancyFound;
use App\Domain\Custodian\Mail\ReconciliationReport;
use App\Domain\Ledger\Models\LedgerPosting;
use App\Support\Reconciliation\ReconciliationReferenceBuilder;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DailyReconciliationService
{
    private array $reconciliationResults = [];

    private array $discrepancies = [];

    public function __construct(
        private readonly BalanceSynchronizationService $syncService,
        private readonly CustodianRegistry $custodianRegistry,
        private readonly ReconciliationReferenceBuilder $referenceBuilder,
    ) {
    }

    /**
     * Perform daily reconciliation for all accounts.
     */
    public function performDailyReconciliation(): array
    {
        Log::info('Starting daily reconciliation process');

        $this->reconciliationResults = [
            'date'                     => now()->toDateString(),
            'start_time'               => now(),
            'accounts_checked'         => 0,
            'discrepancies_found'      => 0,
            'total_discrepancy_amount' => 0,
            'status'                   => 'in_progress',
        ];

        try {
            // Step 1: Synchronize all balances
            $syncResults = $this->syncService->synchronizeAllBalances();

            // Step 2: Perform reconciliation checks
            $this->performReconciliationChecks();

            // Step 3: Send notifications if discrepancies found
            if (! empty($this->discrepancies)) {
                $this->handleDiscrepancies();
            }

            $this->reconciliationResults['end_time'] = now();
            $this->reconciliationResults['duration_minutes'] =
                $this->reconciliationResults['end_time']->diffInMinutes($this->reconciliationResults['start_time']);
            $this->reconciliationResults['status'] = 'completed';

            // Step 4: Generate reconciliation report
            $report = $this->generateReconciliationReport();

            // Fire reconciliation completed event
            event(
                new ReconciliationCompleted(
                    date: $this->reconciliationResults['date'],
                    results: $this->reconciliationResults,
                    discrepancies: $this->discrepancies
                )
            );

            return $report;
        } catch (Exception $e) {
            Log::error(
                'Daily reconciliation failed',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            $this->reconciliationResults['status'] = 'failed';
            $this->reconciliationResults['error'] = $e->getMessage();

            throw $e;
        }
    }

    /**
     * Perform reconciliation checks.
     */
    private function performReconciliationChecks(): void
    {
        $accounts = Account::with(['balances', 'custodianAccounts'])->get();

        foreach ($accounts as $account) {
            $this->reconciliationResults['accounts_checked']++;

            // Check internal vs external balances
            $this->checkAccountBalances($account);

            // Check for orphaned balances
            $this->checkOrphanedBalances($account);

            // Check for stale data
            $this->checkStaleData($account);
        }
    }

    /**
     * Check internal vs external balances for an account.
     */
    private function checkAccountBalances(Account $account): void
    {
        $internalBalances = $this->getInternalBalances($account);
        $externalBalances = $this->getExternalBalances($account);

        foreach ($internalBalances as $assetCode => $internalAmount) {
            $externalAmount = $externalBalances[$assetCode] ?? 0;

            if ($internalAmount !== $externalAmount) {
                $discrepancy = [
                    'account_uuid'     => $account->uuid,
                    'asset_code'       => $assetCode,
                    'internal_balance' => $internalAmount,
                    'external_balance' => $externalAmount,
                    'difference'       => abs($internalAmount - $externalAmount),
                    'type'             => 'balance_mismatch',
                    'detected_at'      => now(),
                ];

                $discrepancy = array_merge($discrepancy, $this->buildOrchestrationReferences($account, $assetCode));
                $discrepancy = $this->markProviderOperationAsException($discrepancy);

                $this->discrepancies[] = $discrepancy;
                $this->reconciliationResults['discrepancies_found']++;
                $this->reconciliationResults['total_discrepancy_amount'] += $discrepancy['difference'];

                // Fire discrepancy event
                event(new ReconciliationDiscrepancyFound($discrepancy));

                Log::warning('Reconciliation discrepancy found', $discrepancy);
            } else {
                $this->markProviderOperationAsMatched($account, $assetCode);
            }
        }
    }

    /**
     * Get internal balances for an account.
     */
    private function getInternalBalances(Account $account): array
    {
        $balances = [];

        foreach ($account->balances as $balance) {
            $balances[$balance->asset_code] = $balance->balance;
        }

        return $balances;
    }

    /**
     * Get external balances from all custodians.
     */
    private function getExternalBalances(Account $account): array
    {
        $aggregatedBalances = [];

        foreach ($account->custodianAccounts as $custodianAccount) {
            if ($custodianAccount->status !== 'active') {
                continue;
            }

            try {
                $connector = $this->custodianRegistry->getConnector($custodianAccount->custodian_name);

                if (! $connector->isAvailable()) {
                    Log::warning(
                        'Custodian not available for reconciliation',
                        [
                            'custodian' => $custodianAccount->custodian_name,
                            'account'   => $account->uuid,
                        ]
                    );

                    continue;
                }

                $accountInfo = $connector->getAccountInfo($custodianAccount->custodian_account_id);

                foreach ($accountInfo->balances as $assetCode => $amount) {
                    $aggregatedBalances[$assetCode] = ($aggregatedBalances[$assetCode] ?? 0) + $amount;
                }
            } catch (Exception $e) {
                Log::error(
                    'Failed to get external balance',
                    [
                        'custodian' => $custodianAccount->custodian_name,
                        'account'   => $account->uuid,
                        'error'     => $e->getMessage(),
                    ]
                );
            }
        }

        return $aggregatedBalances;
    }

    /**
     * Check for orphaned balances.
     */
    private function checkOrphanedBalances(Account $account): void
    {
        // Check for balances without corresponding custodian accounts
        if ($account->balances->isNotEmpty() && $account->custodianAccounts->isEmpty()) {
            $discrepancy = [
                'account_uuid' => $account->uuid,
                'type'         => 'orphaned_balance',
                'message'      => 'Account has balances but no custodian accounts',
                'detected_at'  => now(),
            ] + $this->buildOrchestrationReferences($account, null);

            $this->discrepancies[] = $this->markProviderOperationAsException($discrepancy);

            $this->reconciliationResults['discrepancies_found']++;
        }
    }

    /**
     * Check for stale data.
     *
     * Identifies custodian accounts that haven't been synced in over 24 hours,
     * which may indicate connectivity issues or data staleness.
     */
    private function checkStaleData(Account $account): void
    {
        $staleCutoff = now()->subHours(24);

        foreach ($account->custodianAccounts as $custodianAccount) {
            // Only check accounts that have been synced at least once
            if (
                $custodianAccount->last_synced_at &&
                $custodianAccount->last_synced_at->isBefore($staleCutoff)
            ) {
                $discrepancy = [
                    'account_uuid'   => $account->uuid,
                    'custodian_id'   => $custodianAccount->custodian_name,
                    'type'           => 'stale_data',
                    'message'        => 'Custodian account not synced in 24 hours',
                    'last_synced_at' => $custodianAccount->last_synced_at,
                    'detected_at'    => now(),
                ] + $this->buildOrchestrationReferences($account, null, $custodianAccount->custodian_account_id);

                $this->discrepancies[] = $this->markProviderOperationAsException($discrepancy);

                $this->reconciliationResults['discrepancies_found']++;
            }
        }
    }

    /**
     * Generate reconciliation report.
     */
    private function generateReconciliationReport(): array
    {
        $report = [
            'summary'         => $this->reconciliationResults,
            'discrepancies'   => $this->discrepancies,
            'settlement_summary' => $this->buildSettlementSummary(),
            'recent_provider_callbacks' => $this->getRecentProviderCallbacks(),
            'recommendations' => $this->generateRecommendations(),
            'generated_at'    => now(),
        ];

        // Store report in database or file system
        $this->storeReport($report);

        return $report;
    }

    /**
     * Generate recommendations based on findings.
     */
    private function generateRecommendations(): array
    {
        $recommendations = [];

        if ($this->reconciliationResults['discrepancies_found'] > 0) {
            $recommendations[] = 'Investigate and resolve balance discrepancies immediately';
        }

        $staleCounts = collect($this->discrepancies)
            ->where('type', 'stale_data')
            ->count();

        if ($staleCounts > 0) {
            $recommendations[] = "Force synchronization for {$staleCounts} accounts with stale data";
        }

        $orphanedCounts = collect($this->discrepancies)
            ->where('type', 'orphaned_balance')
            ->count();

        if ($orphanedCounts > 0) {
            $recommendations[] = "Review {$orphanedCounts} accounts with orphaned balances";
        }

        return $recommendations;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrchestrationReferences(Account $account, ?string $assetCode, ?string $providerReference = null): array
    {
        $custodianAccounts = $account->custodianAccounts
            ->sortBy('id')
            ->values();

        $primaryCustodianAccount = $custodianAccounts->first();
        $resolvedProviderReference = $providerReference
            ?? $primaryCustodianAccount?->custodian_account_id;
        $ledgerPostingContext = $assetCode !== null
            ? $this->resolveLatestLedgerPostingContext($account, $assetCode)
            : null;
        $references = $this->referenceBuilder->build(
            $this->reconciliationResults['date'],
            $account->uuid,
            $assetCode,
            $resolvedProviderReference,
            $ledgerPostingContext,
        );
        $providerOperation = $this->resolveProviderOperationContext(
            providerReference: $resolvedProviderReference,
            internalReference: $account->uuid,
            ledgerPostingContext: $ledgerPostingContext,
            reconciliationReference: is_string($references['reconciliation_reference'] ?? null)
                ? $references['reconciliation_reference']
                : null,
            settlementReference: is_string($references['settlement_reference'] ?? null)
                ? $references['settlement_reference']
                : null,
        );

        if ($providerOperation === null) {
            return $references;
        }

        return [
            ...$references,
            'provider_operation' => $providerOperation,
            'provider_reference' => $references['provider_reference'] ?? $providerOperation['provider_reference'] ?? null,
            'settlement_reference' => $references['settlement_reference'] ?? $providerOperation['settlement_reference'] ?? null,
            'ledger_posting_reference' => $references['ledger_posting_reference'] ?? $providerOperation['ledger_posting_reference'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveLatestLedgerPostingContext(Account $account, string $assetCode): ?array
    {
        /** @var LedgerPosting|null $posting */
        $posting = LedgerPosting::query()
            ->select('ledger_postings.*')
            ->join('ledger_entries', 'ledger_entries.ledger_posting_id', '=', 'ledger_postings.id')
            ->where('ledger_entries.account_uuid', $account->uuid)
            ->where('ledger_entries.asset_code', $assetCode)
            ->orderByDesc('ledger_postings.posted_at')
            ->orderByDesc('ledger_postings.created_at')
            ->first();

        if ($posting === null) {
            return null;
        }

        $metadata = is_array($posting->metadata) ? $posting->metadata : [];

        return array_filter([
            'id' => $posting->id,
            'posting_type' => $posting->posting_type,
            'status' => $posting->status,
            'transfer_reference' => $posting->transfer_reference,
            'related_posting_id' => $metadata['related_posting_id'] ?? null,
            'money_request_id' => $posting->money_request_id,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, int>
     */
    private function buildSettlementSummary(): array
    {
        $rows = DB::table('settlements')
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->get();

        $summary = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $row) {
            $summary[$row->status] = (int) $row->aggregate;
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRecentProviderCallbacks(): array
    {
        return CustodianWebhook::query()
            ->latest('created_at')
            ->limit(5)
            ->get([
                'custodian_name',
                'event_type',
                'normalized_event_type',
                'provider_reference',
                'finality_status',
                'settlement_status',
                'reconciliation_status',
                'status',
                'processed_at',
                'provider_operation_id',
            ])
            ->map(function (CustodianWebhook $webhook): array {
                $providerOperation = $this->resolveProviderOperationContext(
                    providerReference: $webhook->provider_reference,
                    internalReference: null,
                    ledgerPostingContext: null,
                    reconciliationReference: null,
                    settlementReference: $webhook->settlement_reference,
                    providerOperationId: $webhook->provider_operation_id,
                );

                return array_filter([
                    'custodian_name' => $webhook->custodian_name,
                    'event_type' => $webhook->event_type,
                    'normalized_event_type' => $webhook->normalized_event_type,
                    'provider_reference' => $webhook->provider_reference,
                    'finality_status' => $webhook->finality_status,
                    'settlement_status' => $webhook->settlement_status,
                    'reconciliation_status' => $webhook->reconciliation_status,
                    'status' => $webhook->status,
                    'processed_at' => $webhook->processed_at?->toDateTimeString(),
                    'provider_operation' => $providerOperation,
                ], static fn (mixed $value): bool => $value !== null);
            })
            ->all();
    }

    private function markProviderOperationAsMatched(Account $account, string $assetCode): void
    {
        $references = $this->buildOrchestrationReferences($account, $assetCode);
        $providerOperation = $references['provider_operation'] ?? null;

        if (! is_array($providerOperation) || ! is_string($providerOperation['id'] ?? null)) {
            return;
        }

        $this->updateProviderOperationReconciliationState(
            $providerOperation['id'],
            ProviderReconciliationStatus::MATCHED,
            is_string($references['reconciliation_reference'] ?? null) ? $references['reconciliation_reference'] : null,
            is_string($references['ledger_posting_reference'] ?? null) ? $references['ledger_posting_reference'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $discrepancy
     * @return array<string, mixed>
     */
    private function markProviderOperationAsException(array $discrepancy): array
    {
        $providerOperation = $discrepancy['provider_operation'] ?? null;

        if (! is_array($providerOperation) || ! is_string($providerOperation['id'] ?? null)) {
            return $discrepancy;
        }

        $updatedProviderOperation = $this->updateProviderOperationReconciliationState(
            $providerOperation['id'],
            ProviderReconciliationStatus::EXCEPTION,
            is_string($discrepancy['reconciliation_reference'] ?? null) ? $discrepancy['reconciliation_reference'] : null,
            is_string($discrepancy['ledger_posting_reference'] ?? null) ? $discrepancy['ledger_posting_reference'] : null,
        );

        if ($updatedProviderOperation !== null) {
            $discrepancy['provider_operation'] = $updatedProviderOperation;
            $discrepancy['settlement_reference'] = $discrepancy['settlement_reference'] ?? $updatedProviderOperation['settlement_reference'] ?? null;
            $discrepancy['ledger_posting_reference'] = $discrepancy['ledger_posting_reference'] ?? $updatedProviderOperation['ledger_posting_reference'] ?? null;
        }

        return $discrepancy;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function updateProviderOperationReconciliationState(
        string $providerOperationId,
        ProviderReconciliationStatus $status,
        ?string $reconciliationReference,
        ?string $ledgerPostingReference,
    ): ?array {
        /** @var ProviderOperation|null $providerOperation */
        $providerOperation = ProviderOperation::query()->find($providerOperationId);

        if ($providerOperation === null) {
            return null;
        }

        $providerOperation->reconciliation_status = $status;
        $providerOperation->reconciliation_reference = $reconciliationReference ?? $providerOperation->reconciliation_reference;
        $providerOperation->ledger_posting_reference = $ledgerPostingReference ?? $providerOperation->ledger_posting_reference;
        $providerOperation->save();

        return $this->formatProviderOperationSnapshot($providerOperation->fresh());
    }

    /**
     * @param  array<string, mixed>|null  $ledgerPostingContext
     * @return array<string, mixed>|null
     */
    private function resolveProviderOperationContext(
        ?string $providerReference,
        ?string $internalReference,
        ?array $ledgerPostingContext,
        ?string $reconciliationReference,
        ?string $settlementReference,
        ?string $providerOperationId = null,
    ): ?array {
        $query = ProviderOperation::query()
            ->where('provider_family', 'custodian');

        if ($providerOperationId !== null && $providerOperationId !== '') {
            /** @var ProviderOperation|null $providerOperation */
            $providerOperation = $query->whereKey($providerOperationId)->first();

            return $this->formatProviderOperationSnapshot($providerOperation);
        }

        $candidateProviderReferences = array_values(array_unique(array_filter([
            $providerReference,
            is_string($ledgerPostingContext['transfer_reference'] ?? null) ? $ledgerPostingContext['transfer_reference'] : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '')));

        $ledgerPostingReference = is_string($ledgerPostingContext['id'] ?? null)
            ? $ledgerPostingContext['id']
            : null;

        if (
            $candidateProviderReferences === []
            && $internalReference === null
            && $reconciliationReference === null
            && $settlementReference === null
            && $ledgerPostingReference === null
        ) {
            return null;
        }

        /** @var ProviderOperation|null $providerOperation */
        $providerOperation = $query
            ->where(function ($builder) use (
                $candidateProviderReferences,
                $internalReference,
                $reconciliationReference,
                $settlementReference,
                $ledgerPostingReference,
            ): void {
                if ($candidateProviderReferences !== []) {
                    $builder->orWhereIn('provider_reference', $candidateProviderReferences);
                }

                if ($internalReference !== null && $internalReference !== '') {
                    $builder->orWhere('internal_reference', $internalReference);
                }

                if ($reconciliationReference !== null && $reconciliationReference !== '') {
                    $builder->orWhere('reconciliation_reference', $reconciliationReference);
                }

                if ($settlementReference !== null && $settlementReference !== '') {
                    $builder->orWhere('settlement_reference', $settlementReference);
                }

                if ($ledgerPostingReference !== null && $ledgerPostingReference !== '') {
                    $builder->orWhere('ledger_posting_reference', $ledgerPostingReference);
                }
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();

        return $this->formatProviderOperationSnapshot($providerOperation);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatProviderOperationSnapshot(?ProviderOperation $providerOperation): ?array
    {
        if ($providerOperation === null) {
            return null;
        }

        return array_filter([
            'id' => $providerOperation->id,
            'provider_family' => $providerOperation->provider_family,
            'provider_name' => $providerOperation->provider_name,
            'operation_type' => $providerOperation->operation_type->value,
            'normalized_event_type' => $providerOperation->normalized_event_type,
            'provider_reference' => $providerOperation->provider_reference,
            'internal_reference' => $providerOperation->internal_reference,
            'finality_status' => $providerOperation->finality_status->value,
            'settlement_status' => $providerOperation->settlement_status->value,
            'reconciliation_status' => $providerOperation->reconciliation_status->value,
            'settlement_reference' => $providerOperation->settlement_reference,
            'reconciliation_reference' => $providerOperation->reconciliation_reference,
            'ledger_posting_reference' => $providerOperation->ledger_posting_reference,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Handle discrepancies.
     */
    private function handleDiscrepancies(): void
    {
        // Group discrepancies by severity
        $criticalDiscrepancies = collect($this->discrepancies)
            ->filter(
                function ($d) {
                    return isset($d['difference']) && $d['difference'] > 100000; // Over $1000
                }
            );

        if ($criticalDiscrepancies->isNotEmpty()) {
            // Send immediate alert for critical discrepancies
            $this->sendCriticalAlert($criticalDiscrepancies);
        }

        // Send reconciliation report email
        $this->sendReconciliationReport();
    }

    /**
     * Send critical alert.
     */
    private function sendCriticalAlert(Collection $criticalDiscrepancies): void
    {
        Log::critical(
            'Critical reconciliation discrepancies found',
            [
                'count'        => $criticalDiscrepancies->count(),
                'total_amount' => $criticalDiscrepancies->sum('difference'),
            ]
        );

        // In production, send alerts to operations team
    }

    /**
     * Send reconciliation report.
     */
    private function sendReconciliationReport(): void
    {
        $recipients = config('reconciliation.report_recipients', []);

        if (! empty($recipients)) {
            Mail::to($recipients)->send(
                new ReconciliationReport(
                    $this->reconciliationResults,
                    $this->discrepancies
                )
            );
        }
    }

    /**
     * Store reconciliation report.
     */
    private function storeReport(array $report): void
    {
        $filename = sprintf(
            'reconciliation-%s.json',
            $this->reconciliationResults['date']
        );

        $path = storage_path("app/reconciliation/{$filename}");

        // Ensure directory exists
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT));

        Log::info('Reconciliation report stored', ['path' => $path]);
    }

    /**
     * Get latest reconciliation report.
     */
    public function getLatestReport(): ?array
    {
        $files = glob(storage_path('app/reconciliation/reconciliation-*.json'));

        if (empty($files)) {
            return null;
        }

        // Sort by filename (date) descending
        rsort($files);

        $latestFile = $files[0];
        $content = file_get_contents($latestFile);

        return json_decode($content, true);
    }
}
