<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\MinorAccountLifecycleException;
use Illuminate\Console\Command;

class FlagMinorAccountLifecycleExceptionSlaBreaches extends Command
{
    protected $signature = 'minor-accounts:lifecycle-exceptions-flag-sla-breaches';

    protected $description = 'Set sla_escalated_at on open lifecycle exceptions past SLA due time';

    public function handle(): int
    {
        $now = now();

        $count = MinorAccountLifecycleException::query()
            ->where('status', MinorAccountLifecycleException::STATUS_OPEN)
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', $now)
            ->whereNull('sla_escalated_at')
            ->update(['sla_escalated_at' => $now]);

        $this->info(sprintf('Flagged %d lifecycle exception(s) past SLA.', $count));

        return self::SUCCESS;
    }
}
