<?php

declare(strict_types=1);

it('renders canonical provider operation state in reconciliation report details', function (): void {
    $report = [
        'date' => '2026-04-07',
        'accounts_checked' => 1,
        'discrepancies_found' => 1,
        'total_discrepancy_amount' => 5000,
        'start_time' => '2026-04-07 10:00:00',
        'duration_minutes' => 2,
        'discrepancies' => [
            [
                'type' => 'balance_mismatch',
                'account_uuid' => 'acc-123',
                'asset_code' => 'USD',
                'internal_balance' => 100000,
                'external_balance' => 95000,
                'difference' => 5000,
                'provider_reference' => 'provider-456',
                'internal_reference' => 'acc-123',
                'reconciliation_reference' => 'reconciliation:2026-04-07:acc-123:USD',
                'ledger_posting_reference' => 'posting-789',
                'settlement_reference' => 'SETTLEMENT_456',
                'detected_at' => '2026-04-07 10:02:00',
                'provider_operation' => [
                    'provider_name' => 'mock',
                    'provider_reference' => 'provider-456',
                    'finality_status' => 'succeeded',
                    'settlement_status' => 'completed',
                    'reconciliation_status' => 'matched',
                    'settlement_reference' => 'SETTLEMENT_456',
                    'ledger_posting_reference' => 'posting-789',
                ],
            ],
        ],
    ];

    $this->view('filament.admin.resources.reconciliation-report-details', [
        'report' => $report,
    ])
        ->assertSee('Canonical Provider Operation')
        ->assertSee('succeeded / completed / matched')
        ->assertSee('provider-456');
});
