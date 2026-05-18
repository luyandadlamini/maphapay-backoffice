<?php

declare(strict_types=1);

namespace App\Domain\SocialMoney\Observers;

use App\Domain\SocialMoney\Services\SyncTransactionToChatService;
use App\Models\MoneyRequest;
use Illuminate\Support\Facades\DB;
use Throwable;

class MoneyRequestChatSyncObserver
{
    public function __construct(
        private readonly SyncTransactionToChatService $sync,
    ) {
    }

    public function created(MoneyRequest $request): void
    {
        DB::afterCommit(function () use ($request): void {
            try {
                $this->sync->postRequestMessage($request);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    public function updated(MoneyRequest $request): void
    {
        if (! $request->wasChanged('status')) {
            return;
        }

        $newStatus = $request->status;

        DB::afterCommit(function () use ($request, $newStatus): void {
            try {
                if ($newStatus === MoneyRequest::STATUS_FULFILLED) {
                    $this->sync->markRequestPaid($request);
                } elseif ($newStatus === MoneyRequest::STATUS_REJECTED) {
                    $this->sync->postRequestDeclined($request);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
