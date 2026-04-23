<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\MinorFamilyReconciliationException;
use Illuminate\Console\Command;

/**
 * Marks open minor-family reconciliation exceptions whose SLA window has passed,
 * so operators and dashboards can filter on sla_escalated_at without mutating financial state.
 */
class FlagMinorFamilyReconciliationExceptionSlaBreaches extends Command
{
    protected $signature = 'minor-family:reconciliation-exceptions-flag-sla-breaches';

    protected $description = 'Set sla_escalated_at on open minor-family reconciliation exceptions past SLA due time';

    public function handle(): int
    {
        $now = now();

        $count = MinorFamilyReconciliationException::query()
            ->where('status', MinorFamilyReconciliationException::STATUS_OPEN)
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', $now)
            ->whereNull('sla_escalated_at')
            ->update(['sla_escalated_at' => $now]);

        $this->info(sprintf('Flagged %d reconciliation exception(s) past SLA.', $count));

        return self::SUCCESS;
    }
}
