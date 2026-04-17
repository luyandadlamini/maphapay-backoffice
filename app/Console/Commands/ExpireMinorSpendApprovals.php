<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\MinorSpendApproval;
use Illuminate\Console\Command;

class ExpireMinorSpendApprovals extends Command
{
    protected $signature = 'minor-accounts:expire-approvals';

    protected $description = 'Cancel pending minor spend approvals past their 24-hour expiry';

    public function handle(): int
    {
        try {
            $count = MinorSpendApproval::where('status', 'pending')
                ->where('expires_at', '<', now())
                ->update([
                    'status'     => 'cancelled',
                    'decided_at' => now(),
                ]);

            $this->info("Expired {$count} pending minor spend approval(s).");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to expire minor spend approvals: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
