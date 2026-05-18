<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Workflows;

use Throwable;
use Workflow\WorkflowStub;

/**
 * Bounded-wait wrapper for laravel-workflow money-movement workflows.
 *
 * Money-movement HTTP handlers can elect to wait for the workflow to commit
 * inside the same request, returning HTTP 200 with the terminal state for
 * the >99% of intra-tenant transfers that complete in well under a second.
 * If the workflow does not complete within {@see DEFAULT_WAIT_SECONDS}, the
 * handler returns HTTP 202 with the workflowId so the client can poll
 * GET /api/v2/transfers/{id}/status.
 *
 * Polling is done in-process with exponential backoff capped at 500 ms so the
 * common case (workflow completes in <100 ms) sees the result on the first or
 * second poll, while the slow case still bounds DB pressure.
 *
 * NOTE: laravel-workflow is poll-based, not Temporal's signal-on-completion.
 * If the application has no queue worker running, `WorkflowStub::start()` runs
 * the workflow inline (sync driver) — the first poll completes immediately.
 */
final class SyncTransferAwaiter
{
    public const DEFAULT_WAIT_SECONDS = 5;

    private const POLL_INTERVAL_MS_INITIAL = 50;
    private const POLL_INTERVAL_MS_MAX = 500;

    public function awaitOrAccept(WorkflowStub $stub, int $waitSeconds = self::DEFAULT_WAIT_SECONDS): TransferAwaitOutcome
    {
        $workflowId = (string) $stub->id();
        $deadline = microtime(true) + max(0, $waitSeconds);
        $intervalMs = self::POLL_INTERVAL_MS_INITIAL;

        while (true) {
            $stub->fresh();

            if ($stub->completed()) {
                return TransferAwaitOutcome::completed($workflowId, $stub->output());
            }

            if ($stub->failed()) {
                return TransferAwaitOutcome::failed($workflowId, $this->extractFailureMessage($stub));
            }

            if (microtime(true) >= $deadline) {
                return TransferAwaitOutcome::pending($workflowId);
            }

            usleep($intervalMs * 1000);
            $intervalMs = min((int) ($intervalMs * 2), self::POLL_INTERVAL_MS_MAX);
        }
    }

    /**
     * Build a safe, client-displayable failure message from the workflow's
     * exception trail. Returns a generic fallback if the trail is unreadable.
     */
    private function extractFailureMessage(WorkflowStub $stub): string
    {
        try {
            $first = $stub->exceptions()->first();
            $message = is_object($first) ? ($first->message ?? null) : null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        } catch (Throwable) {
            // fall through to generic message
        }

        return 'Transfer failed';
    }
}
