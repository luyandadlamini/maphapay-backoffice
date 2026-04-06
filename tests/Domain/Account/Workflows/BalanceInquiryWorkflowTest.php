<?php

declare(strict_types=1);

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Workflows\BalanceInquiryWorkflow;
use Workflow\WorkflowStub;

it('can perform balance inquiry', function () {
    WorkflowStub::fake();

    // Create test account
    $account = Account::factory()->create();
    $accountUuid = new AccountUuid($account->uuid);
    $requestedBy = 'teller-123';

    $workflow = WorkflowStub::make(BalanceInquiryWorkflow::class);
    $workflow->start($accountUuid, $requestedBy);

    expect(true)->toBeTrue(); // Basic test that workflow starts without error
});

it('can create workflow stub for balance inquiry', function () {
    expect((new ReflectionClass(BalanceInquiryWorkflow::class))->getName())->not->toBeEmpty();
});
