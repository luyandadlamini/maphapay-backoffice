<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Workflows;

/**
 * Result of {@see SyncTransferAwaiter::awaitOrAccept()}.
 *
 * Three terminal states:
 *   - `completed`: workflow finished within the wait window; `$result` is the
 *     workflow's returned payload (from WalletTransferWorkflow::execute()).
 *   - `pending`:   workflow is still running at the deadline; client should
 *     poll GET /api/v2/transfers/{workflowId}/status.
 *   - `failed`:    workflow reported a terminal failure; `$failureMessage`
 *     carries a redacted/safe message suitable for client display.
 */
final class TransferAwaitOutcome
{
    public const STATE_COMPLETED = 'completed';
    public const STATE_PENDING = 'pending';
    public const STATE_FAILED = 'failed';

    /**
     * @param  mixed  $result
     */
    private function __construct(
        public readonly string $workflowId,
        public readonly string $state,
        public readonly mixed $result = null,
        public readonly ?string $failureMessage = null,
    ) {
    }

    public static function completed(string $workflowId, mixed $result): self
    {
        return new self($workflowId, self::STATE_COMPLETED, $result);
    }

    public static function pending(string $workflowId): self
    {
        return new self($workflowId, self::STATE_PENDING);
    }

    public static function failed(string $workflowId, string $message): self
    {
        return new self($workflowId, self::STATE_FAILED, null, $message);
    }

    public function isCompleted(): bool
    {
        return $this->state === self::STATE_COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isFailed(): bool
    {
        return $this->state === self::STATE_FAILED;
    }
}
