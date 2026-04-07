<?php

declare(strict_types=1);

namespace App\Support\Reconciliation;

final class ReconciliationReferenceBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $date, string $accountUuid, ?string $assetCode, ?string $providerReference): array
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
            'ledger_posting_reference' => null,
            'settlement_reference' => null,
        ];
    }
}
