<?php

declare(strict_types=1);

use App\Domain\Account\Aggregates\LedgerAggregate;
use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\Workflows\FreezeAccountActivity;
use App\Domain\Account\Workflows\FreezeAccountWorkflow;
use Workflow\Models\StoredWorkflow;
use Workflow\WorkflowStub;

it('can handle freeze account activity', function () {
    $uuid = new AccountUuid((string) Illuminate\Support\Str::uuid());
    $reason = 'Suspicious activity detected';
    $authorizedBy = 'admin@example.com';

    $ledgerAggregate = Mockery::mock(LedgerAggregate::class);
    $ledgerAggregate->shouldReceive('retrieve')
        ->with($uuid->getUuid())
        ->andReturnSelf();
    $ledgerAggregate->shouldReceive('freezeAccount')
        ->with($reason, $authorizedBy)
        ->andReturnSelf();
    $ledgerAggregate->shouldReceive('persist')
        ->andReturnSelf();

    $workflow = WorkflowStub::make(FreezeAccountWorkflow::class);
    $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

    $activity = new FreezeAccountActivity(
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

it('can handle freeze account activity without authorized by', function () {
    $uuid = new AccountUuid((string) Illuminate\Support\Str::uuid());
    $reason = 'Account freeze requested';

    $ledgerAggregate = Mockery::mock(LedgerAggregate::class);
    $ledgerAggregate->shouldReceive('retrieve')
        ->with($uuid->getUuid())
        ->andReturnSelf();
    $ledgerAggregate->shouldReceive('freezeAccount')
        ->with($reason, null)
        ->andReturnSelf();
    $ledgerAggregate->shouldReceive('persist')
        ->andReturnSelf();

    $workflow = WorkflowStub::make(FreezeAccountWorkflow::class);
    $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

    $activity = new FreezeAccountActivity(
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
