<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CardWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        // 1. Verify webhook signature
        $signature = $request->header('X-Processor-Signature');
        
        if (!$this->verifySignature($request->getContent(), $signature)) {
            Log::warning('Invalid card webhook signature.');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 2. Parse payload
        $payload = $request->all();
        $eventType = $payload['type'] ?? 'unknown';

        Log::info("Received Card Webhook: {$eventType}", ['payload' => $payload]);

        // 3. Dispatch to appropriate service based on event type
        // In a real implementation, we'd map this to jobs/handlers.
        // e.g. CardWebhookJob::dispatch($payload);

        return response()->json(['status' => 'received']);
    }

    private function verifySignature(string $payload, ?string $signature): bool
    {
        // Implement actual signature verification using configured processor secret
        // For now, assume true in development if not strictly enforced.
        if (config('app.env') === 'local') {
            return true;
        }
        
        // Example: hash_equals(hash_hmac('sha256', $payload, config('services.card_processor.webhook_secret')), $signature)
        return !empty($signature);
    }
}
