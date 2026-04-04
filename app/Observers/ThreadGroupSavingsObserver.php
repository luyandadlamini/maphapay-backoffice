<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\GroupSavings\Services\GroupPocketTransferService;
use App\Models\GroupPocket;
use App\Models\Thread;

class ThreadGroupSavingsObserver
{
    public function __construct(
        private readonly GroupPocketTransferService $transferService,
    ) {
    }

    public function deleting(Thread $thread): void
    {
        foreach ($thread->groupPockets()->where('status', '!=', GroupPocket::STATUS_CLOSED)->get() as $pocket) {
            $this->transferService->refundAllContributions($pocket);
        }
    }
}
