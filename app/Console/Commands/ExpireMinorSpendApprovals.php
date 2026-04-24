<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\MinorSpendApproval;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExpireMinorSpendApprovals extends Command
{
    protected $signature = 'minor-accounts:expire-approvals';

    protected $description = 'Cancel pending minor spend approvals past their 24-hour expiry';

    public function handle(): int
    {
        try {
            $count = 0;

            MinorSpendApproval::query()
                ->where('status', 'pending')
                ->where('expires_at', '<', now())
                ->orderBy('id')
                ->chunkById(100, function (Collection $chunk) use (&$count): void {
                    foreach ($chunk as $approval) {
                        DB::transaction(function () use ($approval, &$count): void {
                            // Re-fetch with lock inside the transaction
                            /** @var MinorSpendApproval|null $locked */
                            $locked = MinorSpendApproval::query()
                                ->where('id', $approval->id)
                                ->where('status', 'pending')
                                ->where('expires_at', '<', now())
                                ->lockForUpdate()
                                ->first();

                            if ($locked === null) {
                                // A concurrent request already changed the status — skip
                                return;
                            }

                            $locked->forceFill([
                                'status'     => 'cancelled',
                                'decided_at' => now(),
                            ])->save();

                            ++$count;
                        });
                    }
                });

            $this->info("Expired {$count} pending minor spend approval(s).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Failed to expire minor spend approvals: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
