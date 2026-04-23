<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\MinorFamilyReconciliationException;
use App\Models\MtnMomoTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MinorFamilyReconciliationExceptionQueueService
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function recordOpenException(
        MtnMomoTransaction $transaction,
        string $reasonCode,
        string $source,
        array $metadata = [],
    ): MinorFamilyReconciliationException {
        /** @var MinorFamilyReconciliationException $exception */
        $exception = DB::transaction(function () use ($transaction, $reasonCode, $source, $metadata): MinorFamilyReconciliationException {
            /** @var MinorFamilyReconciliationException|null $existing */
            $existing = MinorFamilyReconciliationException::query()
                ->where('mtn_momo_transaction_id', $transaction->id)
                ->where('reason_code', $reasonCode)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                $now = now();

                return MinorFamilyReconciliationException::query()->create([
                    'mtn_momo_transaction_id' => $transaction->id,
                    'reason_code' => $reasonCode,
                    'status' => MinorFamilyReconciliationException::STATUS_OPEN,
                    'source' => $source,
                    'occurrence_count' => 1,
                    'metadata' => $metadata,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'sla_due_at' => $this->slaDueAt($now),
                ]);
            }

            /** @var array<string, mixed> $existingMetadata */
            $existingMetadata = is_array($existing->metadata) ? $existing->metadata : [];

            $existing->forceFill([
                'status' => MinorFamilyReconciliationException::STATUS_OPEN,
                'source' => $source,
                'metadata' => array_merge($existingMetadata, $metadata),
                'occurrence_count' => $existing->occurrence_count + 1,
                'last_seen_at' => now(),
                'sla_due_at' => $existing->sla_due_at ?? $this->slaDueAt($existing->first_seen_at),
                'resolved_at' => null,
            ])->save();

            return $existing;
        });

        return $exception;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function resolveOpenExceptionsForTransaction(
        MtnMomoTransaction $transaction,
        string $source,
        array $metadata = [],
    ): int {
        return DB::transaction(function () use ($transaction, $source, $metadata): int {
            /** @var \Illuminate\Database\Eloquent\Collection<int, MinorFamilyReconciliationException> $openExceptions */
            $openExceptions = MinorFamilyReconciliationException::query()
                ->where('mtn_momo_transaction_id', $transaction->id)
                ->where('status', MinorFamilyReconciliationException::STATUS_OPEN)
                ->lockForUpdate()
                ->get();

            if ($openExceptions->isEmpty()) {
                return 0;
            }

            $now = now();

            foreach ($openExceptions as $exception) {
                /** @var array<string, mixed> $existingMetadata */
                $existingMetadata = is_array($exception->metadata) ? $exception->metadata : [];
                $existingMetadata['resolution'] = array_merge([
                    'source' => $source,
                    'resolved_at' => $now->toIso8601String(),
                ], $metadata);

                $exception->forceFill([
                    'status' => MinorFamilyReconciliationException::STATUS_RESOLVED,
                    'source' => $source,
                    'metadata' => $existingMetadata,
                    'resolved_at' => $now,
                ])->save();
            }

            return $openExceptions->count();
        });
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function resolveException(
        MinorFamilyReconciliationException $exception,
        string $source,
        array $metadata = [],
    ): MinorFamilyReconciliationException {
        /** @var MinorFamilyReconciliationException $fresh */
        $fresh = DB::transaction(function () use ($exception, $source, $metadata): MinorFamilyReconciliationException {
            /** @var MinorFamilyReconciliationException $locked */
            $locked = MinorFamilyReconciliationException::query()
                ->whereKey($exception->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var array<string, mixed> $existingMetadata */
            $existingMetadata = is_array($locked->metadata) ? $locked->metadata : [];
            $existingMetadata['resolution'] = array_merge([
                'source' => $source,
                'resolved_at' => now()->toIso8601String(),
            ], $metadata);

            $locked->forceFill([
                'status' => MinorFamilyReconciliationException::STATUS_RESOLVED,
                'source' => $source,
                'metadata' => $existingMetadata,
                'resolved_at' => now(),
            ])->save();

            return $locked;
        });

        return $fresh;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function reopenException(
        MinorFamilyReconciliationException $exception,
        string $source,
        array $metadata = [],
    ): MinorFamilyReconciliationException {
        /** @var MinorFamilyReconciliationException $fresh */
        $fresh = DB::transaction(function () use ($exception, $source, $metadata): MinorFamilyReconciliationException {
            /** @var MinorFamilyReconciliationException $locked */
            $locked = MinorFamilyReconciliationException::query()
                ->whereKey($exception->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var array<string, mixed> $existingMetadata */
            $existingMetadata = is_array($locked->metadata) ? $locked->metadata : [];
            $existingMetadata['reopened'] = array_merge([
                'source' => $source,
                'reopened_at' => now()->toIso8601String(),
            ], $metadata);

            $locked->forceFill([
                'status' => MinorFamilyReconciliationException::STATUS_OPEN,
                'source' => $source,
                'metadata' => $existingMetadata,
                'resolved_at' => null,
                'last_seen_at' => now(),
            ])->save();

            return $locked;
        });

        return $fresh;
    }

    private function slaDueAt(Carbon $from): Carbon
    {
        $hours = (int) config('minor_family.reconciliation_exception.sla_review_hours', 24);

        return $from->copy()->addHours(max(1, $hours));
    }
}
