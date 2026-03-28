<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Models\ScheduledSend;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteScheduledSends extends Command
{
    protected $signature = 'scheduled-sends:execute';

    protected $description = 'Execute scheduled sends that are due (pending and scheduled_for <= now)';

    public function __construct(
        private readonly AuthorizedTransactionManager $authorizedTransactionManager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ids = ScheduledSend::query()
            ->where('status', ScheduledSend::STATUS_PENDING)
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->pluck('id');

        foreach ($ids as $scheduledSendId) {
            DB::transaction(function () use ($scheduledSendId): void {
                /** @var ScheduledSend|null $send */
                $send = ScheduledSend::query()
                    ->whereKey($scheduledSendId)
                    ->lockForUpdate()
                    ->first();

                if ($send === null || $send->status !== ScheduledSend::STATUS_PENDING) {
                    return;
                }

                if ($send->scheduled_for === null || $send->scheduled_for->isFuture()) {
                    return;
                }

                $authorizedTransaction = $this->resolveAuthorizedTransaction($send);

                if ($authorizedTransaction === null) {
                    Log::error('ExecuteScheduledSends: authorized transaction not found', [
                        'scheduled_send_id' => $send->id,
                        'trx'               => $send->trx,
                    ]);
                    $send->update(['status' => ScheduledSend::STATUS_FAILED]);

                    return;
                }

                try {
                    $this->authorizedTransactionManager->finalize($authorizedTransaction);
                    $send->update(['status' => ScheduledSend::STATUS_EXECUTED]);
                } catch (Throwable $e) {
                    Log::error('ExecuteScheduledSends: finalize failed', [
                        'scheduled_send_id' => $send->id,
                        'trx'               => $send->trx,
                        'message'           => $e->getMessage(),
                        'exception'         => $e::class,
                    ]);
                    $send->update(['status' => ScheduledSend::STATUS_FAILED]);
                }
            });
        }

        return Command::SUCCESS;
    }

    /**
     * Prefer authorized_transaction_id when the column exists; otherwise match by trx (current schema).
     */
    private function resolveAuthorizedTransaction(ScheduledSend $send): ?AuthorizedTransaction
    {
        $authorizedTransactionId = $send->getAttribute('authorized_transaction_id');

        if (is_string($authorizedTransactionId) && $authorizedTransactionId !== '') {
            $byId = AuthorizedTransaction::query()->find($authorizedTransactionId);

            if ($byId !== null) {
                return $byId;
            }
        }

        if ($send->trx === null || $send->trx === '') {
            return null;
        }

        return AuthorizedTransaction::query()
            ->where('trx', $send->trx)
            ->first();
    }
}
