<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Jobs;

use App\Domain\CardSubscriptions\Models\CardTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Settles a previously authorised card transaction.
 *
 * Matches by processor_transaction_id; updates status to settled; applies
 * any billing-amount adjustment against the wallet hold.
 *
 * Per 08-processor-gateway.md §9.
 */
class HandleClearingWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $processor,
        public readonly array $payload
    ) {
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $transactionId = $this->payload['transaction_id'] ?? null;

            if (! $transactionId) {
                Log::warning('Clearing webhook missing transaction_id.', ['processor' => $this->processor]);

                return;
            }

            $tx = CardTransaction::where('processor_transaction_id', $transactionId)
                ->lockForUpdate()
                ->first();

            if (! $tx) {
                // Orphaned settlement — persist alert, return 200 (do NOT ask processor to retry)
                Log::warning("Clearing webhook for unknown transaction: {$transactionId}", [
                    'processor' => $this->processor,
                ]);

                return;
            }

            /** @var string|null $settledAmount */
            $settledAmount = $this->payload['settled_amount'] ?? $this->payload['amount'] ?? null;

            $tx->status = 'settled';
            $tx->settled_at = now();

            if ($settledAmount !== null) {
                $tx->billing_amount = (string) $settledAmount;
            }

            $tx->save();

            Log::info("Clearing settled transaction {$transactionId}", [
                'processor'      => $this->processor,
                'settled_amount' => $settledAmount,
            ]);
        });
    }
}
