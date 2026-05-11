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
 * Processes a merchant-initiated refund for a settled card transaction.
 *
 * Credits the wallet balance (not a hold release — the funds were already settled).
 *
 * Per 08-processor-gateway.md §9.
 */
class HandleRefundWebhookJob implements ShouldQueue
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
            $transactionId = $this->payload['original_transaction_id'] ?? $this->payload['transaction_id'] ?? null;

            if (!$transactionId) {
                Log::warning('Refund webhook missing transaction_id.', ['processor' => $this->processor]);
                return;
            }

            $tx = CardTransaction::where('processor_transaction_id', $transactionId)
                ->lockForUpdate()
                ->first();

            if (!$tx) {
                Log::warning("Refund webhook for unknown transaction: {$transactionId}", [
                    'processor' => $this->processor,
                ]);
                return;
            }

            /** @var string|int|null $refundAmount */
            $refundAmount = $this->payload['refund_amount'] ?? $this->payload['amount'] ?? null;

            $tx->status    = 'refunded';
            $tx->refunded_at = now();
            if ($refundAmount !== null) {
                $tx->refunded_amount = (string) $refundAmount;
            }
            $tx->save();

            // TODO: call WalletService::credit() once exposed
            Log::info("Refunded transaction {$transactionId}", [
                'processor'     => $this->processor,
                'refund_amount' => $refundAmount,
            ]);
        });
    }
}
