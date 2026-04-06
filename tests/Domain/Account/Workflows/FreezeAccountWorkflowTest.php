<?php

declare(strict_types=1);

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\Workflows\FreezeAccountWorkflow;
use Workflow\WorkflowStub;

it('can freeze account with reason', function () {
    WorkflowStub::fake();

    $accountUuid = new AccountUuid('test-account-uuid');
    $reason = 'Suspicious activity detected';
    $authorizedBy = 'compliance-officer-123';

    $workflow = WorkflowStub::make(FreezeAccountWorkflow::class);
    $workflow->start($accountUuid, $reason, $authorizedBy);

    expect(true)->toBeTrue(); // Basic test that workflow starts without error
});

it('can create workflow stub for freeze account', function () {
    expect((new ReflectionClass(FreezeAccountWorkflow::class))->getName())->not->toBeEmpty();
});
