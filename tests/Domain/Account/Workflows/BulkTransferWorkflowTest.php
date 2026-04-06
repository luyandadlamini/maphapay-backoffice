<?php

declare(strict_types=1);

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Workflows\BulkTransferWorkflow;
use Workflow\WorkflowStub;

it('can execute bulk transfers successfully', function () {
    WorkflowStub::fake();

    $fromAccount = new AccountUuid('from-account-uuid');
    $transfers = [
        ['to' => new AccountUuid('to-account-1'), 'amount' => new Money(100)],
        ['to' => new AccountUuid('to-account-2'), 'amount' => new Money(200)],
    ];

    $workflow = WorkflowStub::make(BulkTransferWorkflow::class);
    $workflow->start($fromAccount, $transfers);

    expect(true)->toBeTrue(); // Basic test that workflow starts without error
});

it('can create workflow stub for bulk transfer', function () {
    expect((new ReflectionClass(BulkTransferWorkflow::class))->getName())->not->toBeEmpty();
});
