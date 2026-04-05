<?php

use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\Account;
use App\Domain\Custodian\Connectors\MockBankConnector;
use App\Domain\Custodian\Models\CustodianAccount;
use App\Domain\Custodian\Services\CustodianAccountService;
use App\Domain\Custodian\Services\CustodianRegistry;


beforeEach(function () {
    // Set up custodian registry with mock connector
    $this->registry = new CustodianRegistry();
    $this->mockConnector = new MockBankConnector([
        'name'    => 'Mock Bank',
        'enabled' => true,
    ]);
    $this->registry->register('mock', $this->mockConnector);

    $this->service = new CustodianAccountService($this->registry);

    // Create a test account
    $this->account = Account::factory()->create();
});

it('can link an internal account to a custodian account', function () {
    $custodianAccount = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-1',
        ['additional' => 'metadata'],
        true
    );

    expect($custodianAccount)->toBeInstanceOf(CustodianAccount::class);
    expect($custodianAccount->account_uuid)->toBe($this->account->uuid);
    expect($custodianAccount->custodian_name)->toBe('mock');
    expect($custodianAccount->custodian_account_id)->toBe('mock-account-1');
    expect($custodianAccount->custodian_account_name)->toBe('Mock Business Account');
    expect($custodianAccount->status)->toBe('active');
    expect($custodianAccount->is_primary)->toBeTrue();
    expect($custodianAccount->metadata['additional'])->toBe('metadata');
    expect($custodianAccount->metadata['mock'])->toBeTrue();
});

it('throws exception when linking to invalid custodian account', function () {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid custodian account: invalid-account');

    $this->service->linkAccount(
        $this->account,
        'mock',
        'invalid-account'
    );
});

it('can unlink a custodian account', function () {
    // First link an account
    $custodianAccount = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-1',
        [],
        true
    );

    // Then unlink it
    $this->service->unlinkAccount($custodianAccount);

    expect(CustodianAccount::find($custodianAccount->id))->toBeNull();
});

it('transfers primary status when unlinking primary account', function () {
    // Link two accounts
    $primary = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-1',
        [],
        true
    );

    $secondary = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-2'
    );

    expect($primary->is_primary)->toBeTrue();
    expect($secondary->fresh()->is_primary)->toBeFalse();

    // Unlink primary
    $this->service->unlinkAccount($primary);

    // Secondary should now be primary
    expect($secondary->fresh()->is_primary)->toBeTrue();
});

it('can get balance from custodian', function () {
    $custodianAccount = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-1'
    );

    $balance = $this->service->getBalance($custodianAccount, 'USD');

    expect($balance)->toBeInstanceOf(Money::class);
    expect($balance->getAmount())->toBe(1000000); // $10,000.00 from mock data
});

it('can get all balances from custodian', function () {
    $custodianAccount = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-1'
    );

    $balances = $this->service->getAllBalances($custodianAccount);

    expect($balances)->toBe([
        'USD' => 1000000,
        'EUR' => 500000,
        'BTC' => 10000000,
    ]);
});

it('can initiate transfer between custodian accounts', function () {
    $fromAccount = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-1'
    );

    $toAccount = Account::factory()->create();
    $toCustodianAccount = $this->service->linkAccount(
        $toAccount,
        'mock',
        'mock-account-2'
    );

    $transactionId = $this->service->initiateTransfer(
        $fromAccount,
        $toCustodianAccount,
        new Money(50000), // $500.00
        'USD',
        'TEST-REF-123',
        'Test transfer'
    );

    expect($transactionId)->toMatch('/^mock-tx-/');
});

it('throws exception for cross-custodian transfers', function () {
    $fromAccount = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-1'
    );

    // Create a fake account with different custodian
    $toAccount = CustodianAccount::factory()->make([
        'custodian_name'       => 'different-custodian',
        'custodian_account_id' => 'account-123',
    ]);
    $toAccount->id = 999; // Fake ID for testing

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Cross-custodian transfers are not supported yet');

    $this->service->initiateTransfer(
        $fromAccount,
        $toAccount,
        new Money(50000),
        'USD',
        'TEST-REF'
    );
});

it('can get transaction status', function () {
    // First initiate a transfer to create a transaction
    $fromAccount = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-1'
    );

    $toAccount = Account::factory()->create();
    $toCustodianAccount = $this->service->linkAccount(
        $toAccount,
        'mock',
        'mock-account-2'
    );

    $transactionId = $this->service->initiateTransfer(
        $fromAccount,
        $toCustodianAccount,
        new Money(50000),
        'USD',
        'TEST-REF-123'
    );

    // Get status
    $status = $this->service->getTransactionStatus('mock', $transactionId);

    expect($status['id'])->toBe($transactionId);
    expect($status['status'])->toBe('completed');
    expect($status['amount'])->toBe(50000);
});

it('can sync account status with custodian', function () {
    $custodianAccount = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-1'
    );

    // Sync status
    $this->service->syncAccountStatus($custodianAccount);

    // Should still be active (from mock data)
    expect($custodianAccount->fresh()->status)->toBe('active');
});

it('can get transaction history', function () {
    $custodianAccount = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-1'
    );

    // Create some transactions
    $toAccount = Account::factory()->create();
    $toCustodianAccount = $this->service->linkAccount(
        $toAccount,
        'mock',
        'mock-account-2'
    );

    $this->service->initiateTransfer(
        $custodianAccount,
        $toCustodianAccount,
        new Money(10000),
        'USD',
        'TEST-1'
    );

    $this->service->initiateTransfer(
        $custodianAccount,
        $toCustodianAccount,
        new Money(20000),
        'USD',
        'TEST-2'
    );

    // Get history
    $history = $this->service->getTransactionHistory($custodianAccount, 10, 0);

    expect($history)->toHaveCount(2);
    expect($history[0]['from_account'])->toBe('mock-account-1');
    expect($history[0]['to_account'])->toBe('mock-account-2');
});

it('ensures only one primary custodian account per internal account', function () {
    $first = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-1',
        [],
        true
    );

    $second = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-2',
        [],
        true
    );

    // First should no longer be primary
    expect($first->fresh()->is_primary)->toBeFalse();
    expect($second->fresh()->is_primary)->toBeTrue();
});

it('can find custodian accounts through account relationship', function () {
    $custodianAccount = $this->service->linkAccount(
        $this->account,
        'mock',
        'mock-account-1',
        [],
        true
    );

    // Refresh to load relationships
    $this->account->refresh();

    expect($this->account->custodianAccounts)->toHaveCount(1);
    expect($this->account->custodianAccounts->first()->id)->toBe($custodianAccount->id);
    expect($this->account->primaryCustodianAccount()->id)->toBe($custodianAccount->id);
});
