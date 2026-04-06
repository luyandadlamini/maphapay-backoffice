<?php

declare(strict_types=1);

namespace App\Domain\Batch\Actions;

use App\Domain\Batch\Events\BatchJobCancelled;
use App\Domain\Batch\Models\BatchItem;
use App\Domain\Batch\Models\BatchJob;

class CancelBatchJob
{
    public function __invoke(BatchJobCancelled $event): void
    {
        /** @var BatchJob|null $batchJob */
        $batchJob = BatchJob::where('uuid', $event->aggregateRootUuid())->first();

        if (! $batchJob) {
            return;
        }

        // Update batch job status
        $batchJob->update(
            [
                'status'       => 'cancelled',
                'completed_at' => $event->cancelledAt,
                'metadata'     => array_merge(
                    $batchJob->metadata ?? [],
                    [
                        'cancellation_reason' => $event->reason,
                    ]
                ),
            ]
        );

        // Cancel all pending items
        BatchItem::where('batch_job_id', $batchJob->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);
    }
}
