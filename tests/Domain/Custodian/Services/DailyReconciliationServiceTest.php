<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Custodian\Events\ReconciliationCompleted;
use App\Domain\Custodian\Events\ReconciliationDiscrepancyFound;
use App\Domain\Custodian\Models\CustodianAccount;
use App\Domain\Custodian\Services\BalanceSynchronizationService;
use App\Domain\Custodian\Services\CustodianRegistry;
use App\Domain\Custodian\Services\DailyReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->syncService = Mockery::mock(BalanceSynchronizationService::class);
    $this->custodianRegistry = Mockery::mock(CustodianRegistry::class);
    $this->reconciliationService = new DailyReconciliationService(
        $this->syncService,
        $this->custodianRegistry,
        app(\App\Support\Reconciliation\ReconciliationReferenceBuilder::class),
    );
});

it('performs daily reconciliation successfully', function () {
    Event::fake();

    // Mock sync service
    $this->syncService->shouldReceive('synchronizeAllBalances')
        ->once()
        ->andReturn([
            'synchronized' => 10,
            'failed'       => 0,
            'skipped'      => 2,
        ]);

    // Create test accounts with custodian accounts so no orphaned balances
    $account = Account::factory()->create();
    $account->balances()->create([
        'asset_code' => 'USD',
        'balance'    => 100000, // $1000
    ]);

    // Create custodian account for this account
    CustodianAccount::factory()->create([
        'account_uuid'         => $account->uuid,
        'custodian_name'       => 'test_bank',
        'custodian_account_id' => '123',
        'status'               => 'active',
    ]);

    // Mock custodian connector with proper interface
    $mockConnector = Mockery::mock(App\Domain\Custodian\Contracts\ICustodianConnector::class);
    $mockConnector->shouldReceive('isAvailable')->andReturn(true);
    $mockConnector->shouldReceive('getAccountInfo')->andReturn(
        new App\Domain\Custodian\ValueObjects\AccountInfo(
            accountId: '123',
            name: 'Test Account',
            status: 'active',
            balances: ['USD' => 100000], // Same balance - no discrepancy
            currency: 'USD',
            type: 'checking',
            createdAt: now()
        )
    );

    $this->custodianRegistry->shouldReceive('getConnector')
        ->andReturn($mockConnector);

    $result = $this->reconciliationService->performDailyReconciliation();

    expect($result)->toBeArray();
    expect($result['summary']['status'])->toBe('completed');

    // We can't control other tests creating accounts with orphaned balances
    // So just check that our specific account has no discrepancies
    $ourAccountDiscrepancies = collect($result['discrepancies'])
        ->where('account_uuid', $account->uuid)
        ->count();
    expect($ourAccountDiscrepancies)->toBe(0);

    Event::assertDispatched(ReconciliationCompleted::class);
});

it('detects balance discrepancies', function () {
    Event::fake();

    $this->syncService->shouldReceive('synchronizeAllBalances')
        ->once()
        ->andReturn(['synchronized' => 10, 'failed' => 0, 'skipped' => 0]);

    // Create account with balance
    $account = Account::factory()->create();
    $account->balances()->create([
        'asset_code' => 'USD',
        'balance'    => 100000, // $1000 internal
    ]);

    // Create custodian account
    $custodianAccount = CustodianAccount::factory()->create([
        'account_uuid'         => $account->uuid,
        'custodian_name'       => 'test_bank',
        'custodian_account_id' => '123',
        'status'               => 'active',
    ]);

    // Mock custodian with different balance
    $mockConnector = Mockery::mock(App\Domain\Custodian\Contracts\ICustodianConnector::class);
    $mockConnector->shouldReceive('isAvailable')->andReturn(true);
    $mockConnector->shouldReceive('getAccountInfo')->andReturn(
        new App\Domain\Custodian\ValueObjects\AccountInfo(
            accountId: '123',
            name: 'Test Account',
            status: 'active',
            balances: ['USD' => 95000], // $950 external - $50 discrepancy
            currency: 'USD',
            type: 'checking',
            createdAt: now()
        )
    );

    $this->custodianRegistry->shouldReceive('getConnector')
        ->with('test_bank')
        ->andReturn($mockConnector);

    $result = $this->reconciliationService->performDailyReconciliation();

    expect($result['summary']['discrepancies_found'])->toBe(1);
    expect($result['summary']['total_discrepancy_amount'])->toBe(5000); // $50
    expect($result['discrepancies'])->toHaveCount(1);
    expect($result['discrepancies'][0]['type'])->toBe('balance_mismatch');
    expect($result['discrepancies'][0]['difference'])->toBe(5000);

    Event::assertDispatched(ReconciliationDiscrepancyFound::class);
    Event::assertDispatched(ReconciliationCompleted::class);
});

it('attaches latest ledger posting context to balance discrepancies when posted movements exist', function () {
    Event::fake();

    $this->syncService->shouldReceive('synchronizeAllBalances')
        ->once()
        ->andReturn(['synchronized' => 10, 'failed' => 0, 'skipped' => 0]);

    $account = Account::factory()->create();
    $account->balances()->create([
        'asset_code' => 'USD',
        'balance' => 100000,
    ]);

    CustodianAccount::factory()->create([
        'account_uuid' => $account->uuid,
        'custodian_name' => 'test_bank',
        'custodian_account_id' => '123',
        'status' => 'active',
    ]);

    $mockConnector = Mockery::mock(App\Domain\Custodian\Contracts\ICustodianConnector::class);
    $mockConnector->shouldReceive('isAvailable')->andReturn(true);
    $mockConnector->shouldReceive('getAccountInfo')->andReturn(
        new App\Domain\Custodian\ValueObjects\AccountInfo(
            accountId: '123',
            name: 'Test Account',
            status: 'active',
            balances: ['USD' => 95000],
            currency: 'USD',
            type: 'checking',
            createdAt: now()
        )
    );

    $this->custodianRegistry->shouldReceive('getConnector')
        ->with('test_bank')
        ->andReturn($mockConnector);

    DB::table('ledger_postings')->insert([
        [
            'id' => '11111111-1111-1111-1111-111111111111',
            'authorized_transaction_id' => null,
            'authorized_transaction_trx' => 'TRXORIGINAL000000000000000000001',
            'posting_type' => 'send_money',
            'status' => 'adjusted',
            'asset_code' => 'USD',
            'transfer_reference' => 'transfer-original',
            'money_request_id' => null,
            'rule_version' => 1,
            'entries_hash' => str_repeat('a', 64),
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'posted_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ],
        [
            'id' => '22222222-2222-2222-2222-222222222222',
            'authorized_transaction_id' => null,
            'authorized_transaction_trx' => 'TRXADJUST0000000000000000000002',
            'posting_type' => 'reconciliation_adjustment',
            'status' => 'posted',
            'asset_code' => 'USD',
            'transfer_reference' => 'transfer-original',
            'money_request_id' => null,
            'rule_version' => 1,
            'entries_hash' => str_repeat('b', 64),
            'metadata' => json_encode([
                'related_posting_id' => '11111111-1111-1111-1111-111111111111',
                'adjustment_reason' => 'daily_reconciliation_delta',
            ], JSON_THROW_ON_ERROR),
            'posted_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ],
    ]);

    DB::table('ledger_entries')->insert([
        [
            'id' => '33333333-3333-3333-3333-333333333333',
            'ledger_posting_id' => '11111111-1111-1111-1111-111111111111',
            'account_uuid' => $account->uuid,
            'asset_code' => 'USD',
            'signed_amount' => -100000,
            'entry_type' => 'debit',
            'metadata' => json_encode(['role' => 'sender'], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ],
        [
            'id' => '44444444-4444-4444-4444-444444444444',
            'ledger_posting_id' => '22222222-2222-2222-2222-222222222222',
            'account_uuid' => $account->uuid,
            'asset_code' => 'USD',
            'signed_amount' => 5000,
            'entry_type' => 'credit',
            'metadata' => json_encode(['role' => 'adjusted_account'], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ],
    ]);

    $result = $this->reconciliationService->performDailyReconciliation();

    $discrepancy = null;

    foreach ($result['discrepancies'] as $candidate) {
        if (($candidate['account_uuid'] ?? null) !== $account->uuid) {
            continue;
        }

        if (($candidate['type'] ?? null) !== 'balance_mismatch') {
            continue;
        }

        $discrepancy = $candidate;
        break;
    }

    expect($discrepancy)->not->toBeNull()
        ->and($discrepancy['ledger_posting_reference'])->toBe('22222222-2222-2222-2222-222222222222')
        ->and($discrepancy['ledger_posting'])->toMatchArray([
            'id' => '22222222-2222-2222-2222-222222222222',
            'posting_type' => 'reconciliation_adjustment',
            'status' => 'posted',
            'transfer_reference' => 'transfer-original',
            'related_posting_id' => '11111111-1111-1111-1111-111111111111',
        ]);
});

it('detects orphaned balances', function () {
    Event::fake();

    $this->syncService->shouldReceive('synchronizeAllBalances')
        ->once()
        ->andReturn(['synchronized' => 0, 'failed' => 0, 'skipped' => 0]);

    // Create account with balance but no custodian accounts
    $account = Account::factory()->create();
    $account->balances()->create([
        'asset_code' => 'USD',
        'balance'    => 50000,
    ]);

    $result = $this->reconciliationService->performDailyReconciliation();

    expect($result['summary']['discrepancies_found'])->toBeGreaterThanOrEqual(1);

    // Find the orphaned balance discrepancy for our account
    $orphanedDiscrepancy = collect($result['discrepancies'])
        ->where('account_uuid', $account->uuid)
        ->where('type', 'orphaned_balance')
        ->first();

    expect($orphanedDiscrepancy)->not->toBeNull();
    expect($orphanedDiscrepancy['type'])->toBe('orphaned_balance');
});

// TODO: Re-enable this test when last_synced_at column is added
// it('detects stale data', function () {
//     Event::fake();
//
//     $this->syncService->shouldReceive('synchronizeAllBalances')
//         ->once()
//         ->andReturn(['synchronized' => 0, 'failed' => 0, 'skipped' => 0]);
//
//     // Create account with old custodian sync
//     $account = Account::factory()->zeroBalance()->create();
//     $custodianAccount = CustodianAccount::factory()->create([
//         'account_uuid' => $account->uuid,
//         'custodian_name' => 'test_bank',
//         'last_synced_at' => now()->subHours(25), // Over 24 hours old
//         'status' => 'active',
//     ]);
//
//     $result = $this->reconciliationService->performDailyReconciliation();
//
//     expect($result['summary']['discrepancies_found'])->toBe(1);
//     expect($result['discrepancies'][0]['type'])->toBe('stale_data');
// });

it('generates recommendations based on findings', function () {
    $this->syncService->shouldReceive('synchronizeAllBalances')
        ->once()
        ->andReturn(['synchronized' => 0, 'failed' => 0, 'skipped' => 0]);

    // Create account with orphaned balance
    $account1 = Account::factory()->create();
    $account1->balances()->create(['asset_code' => 'USD', 'balance' => 100000]);

    $result = $this->reconciliationService->performDailyReconciliation();

    expect($result['recommendations'])->toBeArray();
    expect($result['recommendations'])->toContain('Investigate and resolve balance discrepancies immediately');
});
