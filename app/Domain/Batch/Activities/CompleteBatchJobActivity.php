<?php

declare(strict_types=1);

namespace App\Domain\Batch\Activities;

use App\Domain\Batch\Aggregates\BatchAggregate;
use Workflow\Activity;

class CompleteBatchJobActivity extends Activity
{
    public function execute(string $batchJobUuid, array $results): void
    {
        // Complete the batch job
        BatchAggregate::retrieve($batchJobUuid)
            ->completeBatchJob()
            ->persist();
    }
}
