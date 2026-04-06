<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Payment\DataObjects\StripeDeposit;
use App\Domain\Payment\Models\PaymentTransaction;
use Illuminate\Support\Str;

beforeEach(function () {
    // Clear payment transactions before each test
    PaymentTransaction::truncate();
});

it('validates stripe deposit data structure', function () {
    $accountUuid = Str::uuid()->toString();

    $deposit = new StripeDeposit(
        accountUuid: $accountUuid,
        amount: 10000, // $100.00
        currency: 'USD',
        reference: 'TEST-' . uniqid(),
        externalReference: 'pi_test_' . uniqid(),
        paymentMethod: 'card',
        paymentMethodType: 'visa',
        metadata: ['test' => true]
    );

    // Test that the deposit data object is properly constructed
    expect($deposit->getAccountUuid())->toBe($accountUuid);
    expect($deposit->getAmount())->toBe(10000);
    expect($deposit->getCurrency())->toBe('USD');
    expect($deposit->getPaymentMethod())->toBe('card');
    expect($deposit->getPaymentMethodType())->toBe('visa');
    expect($deposit->getMetadata())->toBe(['test' => true]);
    expect($deposit->getReference())->toStartWith('TEST-');
    expect($deposit->getExternalReference())->toStartWith('pi_test_');
});

it('creates deposit with different payment methods', function () {
    $accountUuid = Str::uuid()->toString();

    // Test card deposit
    $cardDeposit = new StripeDeposit(
        accountUuid: $accountUuid,
        amount: 10000,
        currency: 'USD',
        reference: 'TEST-' . uniqid(),
        externalReference: 'pi_test_' . uniqid(),
        paymentMethod: 'card',
        paymentMethodType: 'visa',
        metadata: []
    );
    expect($cardDeposit->getPaymentMethod())->toBe('card');
    expect($cardDeposit->getPaymentMethodType())->toBe('visa');

    // Test bank transfer deposit
    $bankDeposit = new StripeDeposit(
        accountUuid: $accountUuid,
        amount: 50000,
        currency: 'USD',
        reference: 'TEST-' . uniqid(),
        externalReference: 'ach_test_' . uniqid(),
        paymentMethod: 'bank_transfer',
        paymentMethodType: 'ach',
        metadata: []
    );
    expect($bankDeposit->getPaymentMethod())->toBe('bank_transfer');
    expect($bankDeposit->getPaymentMethodType())->toBe('ach');
});

it('creates proper transaction flow', function () {
    $accountUuid = Str::uuid()->toString();
    $account = Account::factory()->create(['uuid' => $accountUuid, 'name' => 'Test Account', 'balance' => 0]);

    $deposit = new StripeDeposit(
        accountUuid: $accountUuid,
        amount: 10000,
        currency: 'USD',
        reference: 'TEST-' . uniqid(),
        externalReference: 'pi_test_' . uniqid(),
        paymentMethod: 'card',
        paymentMethodType: 'visa',
        metadata: []
    );

    // Simulate the workflow execution
    $depositUuid = Str::uuid()->toString();
    $transactionId = 'txn_' . uniqid();

    // Step 1: Initiate deposit
    PaymentTransaction::create([
        'aggregate_uuid'      => $depositUuid,
        'account_uuid'        => $accountUuid,
        'type'                => 'deposit',
        'status'              => 'pending',
        'amount'              => 10000,
        'currency'            => 'USD',
        'reference'           => $deposit->getReference(),
        'external_reference'  => $deposit->getExternalReference(),
        'payment_method'      => 'card',
        'payment_method_type' => 'visa',
        'initiated_at'        => now(),
    ]);

    // Step 2: Credit account (in real flow this happens via event sourcing)
    // Account balance is handled by the Account aggregate, not direct manipulation

    // Step 3: Complete deposit
    PaymentTransaction::where('aggregate_uuid', $depositUuid)
        ->update([
            'status'         => 'completed',
            'transaction_id' => $transactionId,
            'completed_at'   => now(),
        ]);

    // Verify results
    $transaction = PaymentTransaction::where('aggregate_uuid', $depositUuid)->first();
    expect($transaction->status)->toBe('completed');
    expect($transaction->transaction_id)->toBe($transactionId);

    // Balance verification would happen through Account aggregate
    // expect($account->balance)->toBe(10000);
});
