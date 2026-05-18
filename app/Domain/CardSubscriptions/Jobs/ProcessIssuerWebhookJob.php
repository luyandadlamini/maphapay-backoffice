<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fan-out dispatcher: routes a raw webhook to the correct typed handler job.
 *
 * Each handler job runs its logic inside its own DB::transaction so that
 * failures can be retried independently.
 */
class ProcessIssuerWebhookJob implements ShouldQueue
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
        public readonly string $eventType,
        public readonly array $payload
    ) {
    }

    public function handle(): void
    {
        match ($this->eventType) {
            'authorisation' => HandleAuthorisationWebhookJob::dispatchSync($this->processor, $this->payload),
            'clearing'      => HandleClearingWebhookJob::dispatchSync($this->processor, $this->payload),
            'reversal'      => HandleReversalWebhookJob::dispatchSync($this->processor, $this->payload),
            'refund'        => HandleRefundWebhookJob::dispatchSync($this->processor, $this->payload),
            default         => Log::warning("Unknown webhook event type: {$this->eventType}", [
                'processor' => $this->processor,
                'event_id'  => $this->payload['event_id'] ?? null,
            ]),
        };
    }
}
