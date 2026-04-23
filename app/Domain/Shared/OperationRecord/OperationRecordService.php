<?php

declare(strict_types=1);

namespace App\Domain\Shared\OperationRecord;

use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Domain\Shared\OperationRecord\Exceptions\OperationInProgressException;
use App\Domain\Shared\OperationRecord\Exceptions\OperationPayloadMismatchException;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Domain-level idempotency guard for money-moving operations.
 *
 * Designed to be called from inside an existing DB::transaction() context
 * (e.g. AuthorizedTransactionManager::finalizeAtomically). The OperationRecord
 * write participates in the outer transaction, so:
 *   - Success → OperationRecord(completed) committed together with handler writes.
 *   - Failure → outer rollback also reverts the OperationRecord, allowing a retry.
 */
class OperationRecordService
{
    public function __construct(
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
    ) {
    }

    /**
     * Execute $fn exactly once for the given (userId, type, key) triple.
     *
     * - Completed record found     → returns cached result_payload; $fn is never called.
     * - Hash mismatch              → throws OperationPayloadMismatchException (HTTP 409).
     * - Concurrent same-key insert → UniqueConstraintViolationException caught; re-reads
     *                                and returns cached result if already completed.
     * - Normal path                → creates pending record, calls $fn, marks completed.
     *
     * @param  int  $userId  users.id of the acting user.
     * @param  string  $type  Operation type (e.g. 'send_money').
     * @param  string  $key  Idempotency key from the original request.
     * @param  string  $payloadHash  SHA-256 hex of normalized request payload.
     * @param  Closure(): array<string, mixed>  $fn  Handler closure; must return array.
     * @return array<string, mixed> Result from $fn or cached result_payload.
     *
     * @throws OperationPayloadMismatchException When key is reused with a different payload.
     */
    public function guardAndRun(
        int $userId,
        string $type,
        string $key,
        string $payloadHash,
        Closure $fn,
    ): array {
        $existing = OperationRecord::query()
            ->where('user_id', $userId)
            ->where('operation_type', $type)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            if ($existing->payload_hash !== $payloadHash) {
                throw new OperationPayloadMismatchException(
                    'Idempotency key reused with a different request payload.'
                );
            }

            if ($existing->status === OperationRecord::STATUS_COMPLETED
                && $existing->result_payload !== null) {
                $this->telemetry->logOperationReplay($userId, $type, $key);

                return $existing->result_payload;
            }

            throw new OperationInProgressException();
        }

        try {
            $record = OperationRecord::create([
                'id'              => (string) Str::ulid(),
                'user_id'         => $userId,
                'operation_type'  => $type,
                'idempotency_key' => $key,
                'payload_hash'    => $payloadHash,
                'status'          => OperationRecord::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Concurrent request created the record first.
            $record = OperationRecord::query()
                ->where('user_id', $userId)
                ->where('operation_type', $type)
                ->where('idempotency_key', $key)
                ->firstOrFail();

            if ($record->payload_hash !== $payloadHash) {
                throw new OperationPayloadMismatchException(
                    'Idempotency key reused with a different request payload.'
                );
            }

            if ($record->status === OperationRecord::STATUS_COMPLETED
                && $record->result_payload !== null) {
                $this->telemetry->logOperationReplay($userId, $type, $key);

                return $record->result_payload;
            }

            throw new OperationInProgressException();
        }

        try {
            /** @var array<string, mixed> $result */
            $result = $fn();
        } catch (Throwable $e) {
            $record->update(['status' => OperationRecord::STATUS_FAILED]);
            throw $e;
        }

        $record->update([
            'status'         => OperationRecord::STATUS_COMPLETED,
            'result_payload' => $result,
        ]);

        return $result;
    }
}
