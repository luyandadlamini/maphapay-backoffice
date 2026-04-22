<?php

declare(strict_types=1);

namespace App\Support\Reconciliation;

use App\Domain\Custodian\Models\ProviderOperation;
use Illuminate\Support\Collection;

final class ReconciliationReportDataLoader
{
    public function __construct(
        private readonly ?string $reportDirectory = null,
    ) {
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function load(): Collection
    {
        $directory = $this->reportDirectory ?? storage_path('app/reconciliation');
        $files = glob($directory . '/reconciliation-*.json') ?: [];

        return collect($files)
            ->map(function (string $file): ?array {
                $content = json_decode((string) file_get_contents($file), true);

                if (! is_array($content)) {
                    return null;
                }

                $summary = is_array($content['summary'] ?? null) ? $content['summary'] : [];
                $report = array_merge($content, $summary);

                return $this->attachProviderOperations($report);
            })
            ->filter(fn (?array $report): bool => $report !== null)
            ->sortByDesc('date')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function attachProviderOperations(array $report): array
    {
        $report['discrepancies'] = collect($report['discrepancies'] ?? [])
            ->map(fn (mixed $discrepancy): mixed => is_array($discrepancy)
                ? $this->attachProviderOperationSnapshot($discrepancy)
                : $discrepancy)
            ->all();

        $report['recent_provider_callbacks'] = collect($report['recent_provider_callbacks'] ?? [])
            ->map(fn (mixed $callback): mixed => is_array($callback)
                ? $this->attachProviderOperationSnapshot($callback)
                : $callback)
            ->all();

        return $report;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attachProviderOperationSnapshot(array $payload): array
    {
        $providerOperation = $this->resolveProviderOperationSnapshot($payload);

        if ($providerOperation === null) {
            return $payload;
        }

        return array_merge($payload, [
            'provider_operation'       => $providerOperation,
            'provider_reference'       => $payload['provider_reference'] ?? $providerOperation['provider_reference'] ?? null,
            'settlement_reference'     => $payload['settlement_reference'] ?? $providerOperation['settlement_reference'] ?? null,
            'reconciliation_reference' => $payload['reconciliation_reference'] ?? $providerOperation['reconciliation_reference'] ?? null,
            'ledger_posting_reference' => $payload['ledger_posting_reference'] ?? $providerOperation['ledger_posting_reference'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function resolveProviderOperationSnapshot(array $payload): ?array
    {
        /** @var ProviderOperation|null $providerOperation */
        $providerOperation = ProviderOperation::query()
            ->where('provider_family', 'custodian')
            ->where(function ($query) use ($payload): void {
                $providerReference = $payload['provider_reference'] ?? null;
                $settlementReference = $payload['settlement_reference'] ?? null;
                $reconciliationReference = $payload['reconciliation_reference'] ?? null;
                $ledgerPostingReference = $payload['ledger_posting_reference'] ?? null;
                $internalReference = $payload['internal_reference'] ?? $payload['account_uuid'] ?? null;

                if (is_string($providerReference) && $providerReference !== '') {
                    $query->orWhere('provider_reference', $providerReference);
                }

                if (is_string($settlementReference) && $settlementReference !== '') {
                    $query->orWhere('settlement_reference', $settlementReference);
                }

                if (is_string($reconciliationReference) && $reconciliationReference !== '') {
                    $query->orWhere('reconciliation_reference', $reconciliationReference);
                }

                if (is_string($ledgerPostingReference) && $ledgerPostingReference !== '') {
                    $query->orWhere('ledger_posting_reference', $ledgerPostingReference);
                }

                if (is_string($internalReference) && $internalReference !== '') {
                    $query->orWhere('internal_reference', $internalReference);
                }
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();

        if ($providerOperation === null) {
            return null;
        }

        return array_filter([
            'id'                       => $providerOperation->id,
            'provider_family'          => $providerOperation->provider_family,
            'provider_name'            => $providerOperation->provider_name,
            'operation_type'           => $providerOperation->operation_type->value,
            'normalized_event_type'    => $providerOperation->normalized_event_type,
            'provider_reference'       => $providerOperation->provider_reference,
            'internal_reference'       => $providerOperation->internal_reference,
            'finality_status'          => $providerOperation->finality_status->value,
            'settlement_status'        => $providerOperation->settlement_status->value,
            'reconciliation_status'    => $providerOperation->reconciliation_status->value,
            'settlement_reference'     => $providerOperation->settlement_reference,
            'reconciliation_reference' => $providerOperation->reconciliation_reference,
            'ledger_posting_reference' => $providerOperation->ledger_posting_reference,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
