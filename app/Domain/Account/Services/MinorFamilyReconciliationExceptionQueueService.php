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

            $existing->forceFill([
                'status' => MinorFamilyReconciliationException::STATUS_OPEN,
                'source' => $source,
                'metadata' => $metadata,
                'occurrence_count' => $existing->occurrence_count + 1,
                'last_seen_at' => now(),
                'sla_due_at' => $existing->sla_due_at ?? $this->slaDueAt($existing->first_seen_at),
            ])->save();

            return $existing;
        });

        return $exception;
    }

    private function slaDueAt(Carbon $from): Carbon
    {
        $hours = (int) config('minor_family.reconciliation_exception.sla_review_hours', 24);

        return $from->copy()->addHours(max(1, $hours));
    }
}
