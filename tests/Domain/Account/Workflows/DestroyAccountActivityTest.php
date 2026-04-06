<?php

declare(strict_types=1);

use App\Domain\Account\Aggregates\LedgerAggregate;
use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\Workflows\DestroyAccountActivity;
use App\Domain\Account\Workflows\DestroyAccountWorkflow;
use Workflow\Models\StoredWorkflow;
use Workflow\WorkflowStub;

it('can handle destroy account activity', function () {
    $uuid = new AccountUuid((string) Illuminate\Support\Str::uuid());

    $ledgerAggregate = Mockery::mock(LedgerAggregate::class);
    $ledgerAggregate->shouldReceive('retrieve')
        ->with($uuid->getUuid())
        ->andReturnSelf();
    $ledgerAggregate->shouldReceive('deleteAccount')
        ->andReturnSelf();
    $ledgerAggregate->shouldReceive('persist')
        ->andReturnSelf();

    $workflow = WorkflowStub::make(DestroyAccountWorkflow::class);
    $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

    $activity = new DestroyAccountActivity(
        0,
        now()->toDateTimeString(),
        $storedWorkflow,
        $uuid,
        $ledgerAggregate
    );

    $activity->handle();

    expect(true)->toBeTrue(); // If no exception is thrown, test passes
});
