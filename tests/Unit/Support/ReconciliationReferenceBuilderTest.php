<?php

declare(strict_types=1);

use App\Support\Reconciliation\ReconciliationReferenceBuilder;

it('builds incremental reconciliation references with nullable ledger linkage', function (): void {
    $references = (new ReconciliationReferenceBuilder())->build(
        '2026-04-07',
        'acc-123',
        'USD',
        'provider-456',
    );

    expect($references)->toMatchArray([
        'provider_family' => 'custodian',
        'provider_reference' => 'provider-456',
        'internal_reference' => 'acc-123',
        'reconciliation_reference' => 'reconciliation:2026-04-07:acc-123:USD',
        'ledger_posting_reference' => null,
        'settlement_reference' => null,
    ]);
});
