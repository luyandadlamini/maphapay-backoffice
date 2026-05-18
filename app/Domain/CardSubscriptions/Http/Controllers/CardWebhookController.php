<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use App\Domain\CardSubscriptions\Jobs\ProcessIssuerWebhookJob;
use App\Domain\CardSubscriptions\Models\CardAuditLog;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ingests raw webhook events from card processors.
 *
 * Processing order per 08-processor-gateway.md §5:
 *   1. Raw body read
 *   2. Signature verification (returns 401 without any logging on mismatch)
 *   3. JSON parse
 *   4. Idempotency check against card_audit_logs
 *   5. Audit raw body (BEFORE mutation)
 *   6. Dispatch job → 200
 */
class CardWebhookController extends Controller
{
    public function handle(
        string $processor,
        string $eventType,
        Request $request,
        CardIssuerInterface $adapter,
        CardAuditService $auditService
    ): JsonResponse {
        // Step 1 — raw body (must be read before any parsing)
        $rawBody = $request->getContent();
        $signature = $request->header('X-Webhook-Signature', '');

        // Step 2 — signature verification (constant-time via hash_equals in adapter)
        if (! $adapter->verifyWebhookSignature($rawBody, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Step 3 — parse JSON
        /** @var array<string, mixed> $payload */
        $payload = json_decode($rawBody, true) ?? [];
        $processorEventId = (string) ($payload['event_id'] ?? '');

        if ($processorEventId === '') {
            Log::warning('Card webhook missing event_id.', [
                'processor'  => $processor,
                'event_type' => $eventType,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        // Step 4 — idempotency check
        $alreadyProcessed = CardAuditLog::query()
            ->where('action', 'processor.webhook_received')
            ->where('metadata->event_id', $processorEventId)
            ->exists();

        if ($alreadyProcessed) {
            return response()->json(['status' => 'received']); // 200 replay
        }

        // Step 5 — audit receipt BEFORE any mutation
        $auditService->record(
            actorType:   'processor',
            actorId:     null,
            action:      'processor.webhook_received',
            entityType:  'processor_event',
            entityId:    $processorEventId,
            beforeState: null,
            afterState:  null,
            metadata:    [
                'processor'  => $processor,
                'event_type' => $eventType,
                'event_id'   => $processorEventId,
                'raw_body'   => $rawBody,
            ],
        );

        // Step 6 — dispatch typed job
        ProcessIssuerWebhookJob::dispatch($processor, $eventType, $payload);

        Log::info("Card webhook queued: {$eventType}", [
            'processor' => $processor,
            'event_id'  => $processorEventId,
        ]);

        return response()->json(['status' => 'queued']);
    }
}
