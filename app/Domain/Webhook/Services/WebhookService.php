<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Services;

use App\Domain\Webhook\Jobs\ProcessWebhookDelivery;
use App\Domain\Webhook\Models\Webhook;
use App\Domain\Webhook\Models\WebhookDelivery;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    /**
     * Dispatch a webhook event to all subscribed webhooks.
     */
    public function dispatch(string $eventType, array $payload): void
    {
        // Find all active webhooks subscribed to this event
        $webhooks = Webhook::active()
            ->forEvent($eventType)
            ->get();

        if ($webhooks->isEmpty()) {
            Log::debug("No active webhooks found for event: {$eventType}");

            return;
        }

        Log::info("Dispatching webhook event: {$eventType} to {$webhooks->count()} webhooks");

        // Create delivery records and queue them for processing
        foreach ($webhooks as $webhook) {
            $delivery = $webhook->deliveries()->create(
                [
                'event_type' => $eventType,
                'payload'    => array_merge(
                    $payload,
                    [
                    'event'     => $eventType,
                    'timestamp' => now()->toIso8601String(),
                    ]
                ),
                'status' => WebhookDelivery::STATUS_PENDING,
                ]
            );

            // Queue the delivery for processing
            ProcessWebhookDelivery::dispatch($delivery)
                ->onQueue('webhooks');
        }
    }

    /**
     * Dispatch a webhook event for a specific account.
     */
    public function dispatchAccountEvent(string $eventType, string $accountUuid, array $additionalData = []): void
    {
        $payload = array_merge(
            [
            'account_uuid' => $accountUuid,
            ],
            $additionalData
        );

        $this->dispatch($eventType, $payload);
    }

    /**
     * Dispatch a webhook event for a transaction.
     */
    public function dispatchTransactionEvent(string $eventType, array $transactionData): void
    {
        $this->dispatch($eventType, $transactionData);
    }

    /**
     * Dispatch a webhook event for a transfer.
     */
    public function dispatchTransferEvent(string $eventType, array $transferData): void
    {
        $this->dispatch($eventType, $transferData);
    }

    /**
     * Generate webhook signature for payload.
     */
    public function generateSignature(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Verify webhook signature.
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expectedSignature = $this->generateSignature($payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
