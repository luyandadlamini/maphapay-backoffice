<?php

declare(strict_types=1);

namespace App\Domain\Governance\Activities;

use Illuminate\Support\Facades\Log;
use Workflow\Activity;

class RecordGovernanceEventActivity extends Activity
{
    /**
     * Execute record governance event activity.
     */
    public function execute(array $eventData): void
    {
        // Log the governance event
        Log::info('Governance event recorded', $eventData);
    }
}
