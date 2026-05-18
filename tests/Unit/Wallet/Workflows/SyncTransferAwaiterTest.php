<?php

declare(strict_types=1);

namespace Tests\Unit\Wallet\Workflows;

use App\Domain\Wallet\Workflows\SyncTransferAwaiter;
use App\Domain\Wallet\Workflows\TransferAwaitOutcome;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Workflows\TestHangingWorkflow;
use Tests\Fixtures\Workflows\TestSuccessfulWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowFailedStatus;
use Workflow\States\WorkflowPendingStatus;
use Workflow\WorkflowStub;

/**
 * SyncTransferAwaiter polls a laravel-workflow stub for terminal state with a
 * bounded wait. These tests cover the three terminal outcomes:
 *   - completed: workflow finishes during the wait window (here, immediately
 *     because the queue driver is sync in tests).
 *   - failed:    workflow throws and finishes in FAILED state.
 *   - pending:   workflow does not finish before the deadline; the awaiter
 *     returns the workflowId so the caller can surface HTTP 202 + status URL.
 */
class SyncTransferAwaiterTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function it_returns_completed_outcome_when_workflow_finishes_in_window(): void
    {
        $stub = WorkflowStub::make(TestSuccessfulWorkflow::class);
        $stub->start('hello');

        $outcome = (new SyncTransferAwaiter())->awaitOrAccept($stub, waitSeconds: 5);

        $this->assertTrue($outcome->isCompleted(), 'Expected completed outcome');
        $this->assertSame('hello-ok', $outcome->result);
        $this->assertSame((string) $stub->id(), $outcome->workflowId);
    }

    #[Test]
    public function it_returns_failed_outcome_when_workflow_is_in_failed_state(): void
    {
        // Construct the stub then manually transition the underlying stored
        // workflow into the FAILED state with a small exception row, so the
        // test does not depend on laravel-workflow's run-loop closure
        // serialization (which can exceed the workflow_exceptions.exception
        // TEXT column for trace-heavy throws — out of scope here).
        $stub = WorkflowStub::make(TestHangingWorkflow::class);
        $stored = StoredWorkflow::findOrFail($stub->id());

        DB::table('workflow_exceptions')->insert([
            'stored_workflow_id' => $stored->id,
            'class'              => TestHangingWorkflow::class,
            'exception'          => serialize(['message' => 'Boom — workflow failed']),
            'created_at'         => now(),
        ]);
        // State machine: created → pending → failed (direct created→failed not allowed).
        $stored->status->transitionTo(WorkflowPendingStatus::class);
        $stored->status->transitionTo(WorkflowFailedStatus::class);

        // Pre-condition: stub now reports failed (after fresh()).
        $stub->fresh();
        $this->assertTrue($stub->failed(), 'Pre-condition: stub should report failed');

        $outcome = (new SyncTransferAwaiter())->awaitOrAccept($stub, waitSeconds: 0);

        $this->assertTrue($outcome->isFailed(), 'Expected failed outcome (state=' . $outcome->state . ')');
        $this->assertNotNull($outcome->failureMessage);
        $this->assertNotSame('', $outcome->failureMessage);
        $this->assertSame((string) $stub->id(), $outcome->workflowId);
    }

    #[Test]
    public function it_returns_pending_outcome_when_workflow_does_not_finish_in_window(): void
    {
        // TestHangingWorkflow is created (status=created) but never started — fresh()
        // will see no terminal status. With waitSeconds=0 the awaiter exits on the
        // first non-terminal poll without sleeping.
        $stub = WorkflowStub::make(TestHangingWorkflow::class);

        $outcome = (new SyncTransferAwaiter())->awaitOrAccept($stub, waitSeconds: 0);

        $this->assertTrue($outcome->isPending(), 'Expected pending outcome (state=' . $outcome->state . ')');
        $this->assertNull($outcome->result);
        $this->assertNull($outcome->failureMessage);
        $this->assertSame((string) $stub->id(), $outcome->workflowId);
    }

    #[Test]
    public function transfer_await_outcome_factories_produce_correct_state(): void
    {
        $completed = TransferAwaitOutcome::completed('wf-1', ['x' => 1]);
        $this->assertTrue($completed->isCompleted());
        $this->assertSame(['x' => 1], $completed->result);

        $pending = TransferAwaitOutcome::pending('wf-2');
        $this->assertTrue($pending->isPending());

        $failed = TransferAwaitOutcome::failed('wf-3', 'boom');
        $this->assertTrue($failed->isFailed());
        $this->assertSame('boom', $failed->failureMessage);
    }
}
