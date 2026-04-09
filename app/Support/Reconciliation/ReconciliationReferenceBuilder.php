<?php

declare(strict_types=1);

namespace App\Support\Reconciliation;

final class ReconciliationReferenceBuilder
{
    /**
     * @param  array<string, mixed>|null  $ledgerPosting
     * @return array<string, mixed>
     */
    public function build(
        string $date,
        string $accountUuid,
        ?string $assetCode,
        ?string $providerReference,
        ?array $ledgerPosting = null,
    ): array
    {
        return [
            'provider_family' => 'custodian',
            'provider_reference' => $providerReference,
            'internal_reference' => $accountUuid,
            'reconciliation_reference' => sprintf(
                'reconciliation:%s:%s:%s',
                $date,
                $accountUuid,
                $assetCode ?? 'n-a'
            ),
            'ledger_posting_reference' => $ledgerPosting['id'] ?? null,
            'ledger_posting' => $ledgerPosting,
            'settlement_reference' => null,
        ];
    }
}
