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
 * Reverses a previously authorised card transaction (auth reversal, not a refund).
 *
 * Releases the wallet hold placed at authorisation time.
 *
 * Per 08-processor-gateway.md §9.
 */
class HandleReversalWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $processor,
        public readonly array $payload
    ) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            $transactionId = $this->payload['transaction_id'] ?? $this->payload['authorization_id'] ?? null;

            if (!$transactionId) {
                Log::warning('Reversal webhook missing transaction_id.', ['processor' => $this->processor]);
                return;
            }

            $tx = CardTransaction::where('processor_transaction_id', $transactionId)
                ->orWhere('processor_transaction_id', $transactionId)
                ->lockForUpdate()
                ->first();

            if (!$tx) {
                Log::warning("Reversal webhook for unknown transaction: {$transactionId}", [
                    'processor' => $this->processor,
                ]);
                return;
            }

            $tx->status     = 'reversed';
            $tx->reversed_at = now();
            $tx->save();

            // TODO: call WalletService::releaseHold() when WalletService exposes it
            Log::info("Reversed transaction {$transactionId}", ['processor' => $this->processor]);
        });
    }
}
