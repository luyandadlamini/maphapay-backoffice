<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Account\Models\MinorCardRequest;
use Illuminate\Console\Command;
use Throwable;

class ExpireMinorCardRequests extends Command
{
    protected $signature = 'minor-accounts:expire-card-requests';

    protected $description = 'Expire pending minor card requests that have passed their 72-hour expiry window';

    public function handle(): int
    {
        try {
            $count = MinorCardRequest::where('status', MinorCardConstants::STATUS_PENDING_APPROVAL)
                ->where('expires_at', '<', now())
                ->update([
                    'status' => MinorCardConstants::STATUS_EXPIRED,
                ]);

            $this->info("Expired {$count} pending minor card request(s).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Failed to expire minor card requests: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
