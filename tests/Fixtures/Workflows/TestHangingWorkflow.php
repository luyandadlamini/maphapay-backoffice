<?php

declare(strict_types=1);

namespace Tests\Fixtures\Workflows;

use Generator;
use Workflow\Workflow;

/**
 * Test fixture: workflow that is intentionally never started — used by
 * SyncTransferAwaiterTest::it_returns_pending_outcome to drive the deadline
 * branch. WorkflowStub::make() creates the stub in `created` status, which
 * is non-terminal, so the awaiter's first poll falls through to the
 * deadline check and returns a pending outcome.
 */
class TestHangingWorkflow extends Workflow
{
    public function execute(): Generator
    {
        yield;
    }
}
