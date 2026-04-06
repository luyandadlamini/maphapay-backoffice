<?php

declare(strict_types=1);

use App\Domain\Account\Aggregates\LedgerAggregate;
use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\Workflows\UnfreezeAccountActivity;
use App\Domain\Account\Workflows\UnfreezeAccountWorkflow;
use Workflow\Models\StoredWorkflow;
use Workflow\WorkflowStub;

it('can handle unfreeze account activity', function () {
    $uuid = new AccountUuid((string) Illuminate\Support\Str::uuid());
    $reason = 'Investigation completed';
    $authorizedBy = 'admin@example.com';

    $ledgerAggregate = Mockery::mock(LedgerAggregate::class);
    $ledgerAggregate->shouldReceive('retrieve')
        ->with($uuid->getUuid())
        ->andReturnSelf();
    $ledgerAggregate->shouldReceive('unfreezeAccount')
        ->with($reason, $authorizedBy)
        ->andReturnSelf();
    $ledgerAggregate->shouldReceive('persist')
        ->andReturnSelf();

    $workflow = WorkflowStub::make(UnfreezeAccountWorkflow::class);
    $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

    $activity = new UnfreezeAccountActivity(
        0,
        now()->toDateTimeString(),
        $storedWorkflow,
        $uuid,
        $reason,
        $authorizedBy,
        $ledgerAggregate
    );

    $activity->handle();

    expect(true)->toBeTrue(); // If no exception is thrown, test passes
});

it('can handle unfreeze account activity without authorized by', function () {
    $uuid = new AccountUuid((string) Illuminate\Support\Str::uuid());
    $reason = 'Account unfreeze requested';

    $ledgerAggregate = Mockery::mock(LedgerAggregate::class);
    $ledgerAggregate->shouldReceive('retrieve')
        ->with($uuid->getUuid())
        ->andReturnSelf();
    $ledgerAggregate->shouldReceive('unfreezeAccount')
        ->with($reason, null)
        ->andReturnSelf();
    $ledgerAggregate->shouldReceive('persist')
        ->andReturnSelf();

    $workflow = WorkflowStub::make(UnfreezeAccountWorkflow::class);
    $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

    $activity = new UnfreezeAccountActivity(
        0,
        now()->toDateTimeString(),
        $storedWorkflow,
        $uuid,
        $reason,
        null,
        $ledgerAggregate
    );

    $activity->handle();

    expect(true)->toBeTrue(); // If no exception is thrown, test passes
});
