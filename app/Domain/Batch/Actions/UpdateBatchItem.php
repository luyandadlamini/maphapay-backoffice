<?php

declare(strict_types=1);

namespace App\Domain\Batch\Actions;

use App\Domain\Batch\Events\BatchItemProcessed;
use App\Domain\Batch\Models\BatchJob;
use App\Domain\Batch\Models\BatchJobItem;

class UpdateBatchItem
{
    public function __invoke(BatchItemProcessed $event): void
    {
        /** @var BatchJob|null $batchJob */
        $batchJob = BatchJob::where('uuid', $event->aggregateRootUuid())->first();

        if (! $batchJob) {
            return;
        }

        // Update the item
        BatchJobItem::where('batch_job_uuid', $batchJob->uuid)
            ->where('sequence', $event->itemIndex + 1)
            ->update(
                [
                    'status'        => $event->status,
                    'result'        => $event->result,
                    'error_message' => $event->errorMessage,
                    'processed_at'  => now(),
                ]
            );

        // Update batch job counters
        $batchJob->increment('processed_items');

        if ($event->status === 'failed') {
            $batchJob->increment('failed_items');
        }
    }
}
