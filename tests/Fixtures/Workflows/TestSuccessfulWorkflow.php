<?php

declare(strict_types=1);

namespace Tests\Fixtures\Workflows;

use Generator;
use Workflow\Workflow;

/**
 * Test fixture: workflow that completes immediately with a deterministic output.
 * Used by SyncTransferAwaiterTest to assert the `completed` path.
 */
class TestSuccessfulWorkflow extends Workflow
{
    public function execute(string $input = 'ok'): Generator
    {
        yield from [];

        return $input . '-ok';
    }
}
