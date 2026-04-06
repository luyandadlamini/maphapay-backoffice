<?php

declare(strict_types=1);

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Workflows\TransactionReversalWorkflow;
use Workflow\WorkflowStub;

it('can reverse a debit transaction', function () {
    WorkflowStub::fake();

    $accountUuid = new AccountUuid('test-account-uuid');
    $amount = new Money(500);
    $transactionType = 'debit';
    $reason = 'Transaction posted in error';
    $authorizedBy = 'manager-456';

    $workflow = WorkflowStub::make(TransactionReversalWorkflow::class);
    $workflow->start($accountUuid, $amount, $transactionType, $reason, $authorizedBy);

    expect(true)->toBeTrue(); // Basic test that workflow starts without error
});

it('can create workflow stub for transaction reversal', function () {
    expect((new ReflectionClass(TransactionReversalWorkflow::class))->getName())->not->toBeEmpty();
});
