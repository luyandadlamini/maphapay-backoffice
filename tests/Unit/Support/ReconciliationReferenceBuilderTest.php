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
        'provider_family'          => 'custodian',
        'provider_reference'       => 'provider-456',
        'internal_reference'       => 'acc-123',
        'reconciliation_reference' => 'reconciliation:2026-04-07:acc-123:USD',
        'ledger_posting_reference' => null,
        'settlement_reference'     => null,
    ]);
});

it('builds ledger-posting-aware reconciliation references when posting context is available', function (): void {
    $references = (new ReconciliationReferenceBuilder())->build(
        '2026-04-07',
        'acc-123',
        'USD',
        'provider-456',
        [
            'id'                 => 'posting-789',
            'posting_type'       => 'reconciliation_adjustment',
            'status'             => 'posted',
            'transfer_reference' => 'transfer-001',
            'related_posting_id' => 'posting-456',
        ],
    );

    expect($references)->toMatchArray([
        'provider_family'          => 'custodian',
        'provider_reference'       => 'provider-456',
        'internal_reference'       => 'acc-123',
        'reconciliation_reference' => 'reconciliation:2026-04-07:acc-123:USD',
        'ledger_posting_reference' => 'posting-789',
        'ledger_posting'           => [
            'id'                 => 'posting-789',
            'posting_type'       => 'reconciliation_adjustment',
            'status'             => 'posted',
            'transfer_reference' => 'transfer-001',
            'related_posting_id' => 'posting-456',
        ],
        'settlement_reference' => null,
    ]);
});
