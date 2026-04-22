<?php

declare(strict_types=1);

use App\Domain\Custodian\Models\ProviderOperation;
use App\Support\Reconciliation\ReconciliationReportDataLoader;
use Tests\TestCase;

uses(TestCase::class);

it('loads full reconciliation reports for operator views while flattening summary fields', function (): void {
    $path = sys_get_temp_dir() . '/maphapay-reconciliation-' . uniqid('', true);
    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }

    $report = [
        'summary' => [
            'date'                     => '2026-04-07',
            'accounts_checked'         => 4,
            'discrepancies_found'      => 1,
            'total_discrepancy_amount' => 5000,
            'status'                   => 'completed',
            'duration_minutes'         => 3,
        ],
        'discrepancies' => [
            [
                'type'                     => 'balance_mismatch',
                'account_uuid'             => 'acc-123',
                'provider_reference'       => 'provider-456',
                'reconciliation_reference' => 'reconciliation:2026-04-07:acc-123:USD',
            ],
        ],
        'recent_provider_callbacks' => [
            ['provider_reference' => 'provider-456'],
        ],
    ];

    file_put_contents($path . '/reconciliation-2026-04-07.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $records = (new ReconciliationReportDataLoader($path))->load();

    expect($records)->toHaveCount(1);
    expect($records->first())
        ->toMatchArray([
            'date'                => '2026-04-07',
            'accounts_checked'    => 4,
            'discrepancies_found' => 1,
            'status'              => 'completed',
        ]);
    expect($records->first()['discrepancies'][0]['provider_reference'])->toBe('provider-456');
    expect($records->first()['recent_provider_callbacks'][0]['provider_reference'])->toBe('provider-456');

    unlink($path . '/reconciliation-2026-04-07.json');
    rmdir($path);
});

it('backfills canonical provider operation state into file-backed reconciliation reports', function (): void {
    ProviderOperation::query()->create([
        'provider_family'          => 'custodian',
        'provider_name'            => 'mock',
        'operation_type'           => 'transfer',
        'operation_key'            => 'custodian:mock:transfer:provider-456',
        'normalized_event_type'    => 'payment_succeeded',
        'provider_reference'       => 'provider-456',
        'internal_reference'       => 'acc-123',
        'finality_status'          => 'succeeded',
        'settlement_status'        => 'completed',
        'reconciliation_status'    => 'matched',
        'settlement_reference'     => 'SETTLEMENT_456',
        'reconciliation_reference' => 'reconciliation:2026-04-07:acc-123:USD',
        'ledger_posting_reference' => 'posting-789',
        'metadata'                 => ['source' => 'test'],
    ]);

    $path = sys_get_temp_dir() . '/maphapay-reconciliation-' . uniqid('', true);
    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }

    $report = [
        'summary' => [
            'date'                     => '2026-04-07',
            'accounts_checked'         => 4,
            'discrepancies_found'      => 1,
            'total_discrepancy_amount' => 5000,
            'status'                   => 'completed',
            'duration_minutes'         => 3,
        ],
        'discrepancies' => [
            [
                'type'                     => 'balance_mismatch',
                'account_uuid'             => 'acc-123',
                'provider_reference'       => 'provider-456',
                'reconciliation_reference' => 'reconciliation:2026-04-07:acc-123:USD',
            ],
        ],
        'recent_provider_callbacks' => [
            ['provider_reference' => 'provider-456'],
        ],
    ];

    file_put_contents($path . '/reconciliation-2026-04-07.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $records = (new ReconciliationReportDataLoader($path))->load();

    expect($records->first()['discrepancies'][0]['provider_operation'])->toMatchArray([
        'provider_reference'       => 'provider-456',
        'finality_status'          => 'succeeded',
        'settlement_status'        => 'completed',
        'reconciliation_status'    => 'matched',
        'settlement_reference'     => 'SETTLEMENT_456',
        'ledger_posting_reference' => 'posting-789',
    ]);

    expect($records->first()['recent_provider_callbacks'][0]['provider_operation'])->toMatchArray([
        'provider_reference'    => 'provider-456',
        'settlement_status'     => 'completed',
        'reconciliation_status' => 'matched',
    ]);

    unlink($path . '/reconciliation-2026-04-07.json');
    rmdir($path);
});
