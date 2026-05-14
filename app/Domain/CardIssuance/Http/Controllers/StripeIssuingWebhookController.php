<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Http\Controllers;

use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\ValueObjects\StripeUsdToSzlConverter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class StripeIssuingWebhookController extends Controller
{
    public function __construct(
        private readonly CardIssuerInterface $issuer,
        private readonly StripeUsdToSzlConverter $converter,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        if (! is_string($signature) || ! $this->issuer->verifyWebhookSignature($payload, $signature)) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        $event = json_decode($payload, true);
        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response()->json(['error' => 'malformed payload'], 400);
        }

        $existing = DB::table('stripe_webhook_events')
            ->where('event_id', $event['id'])
            ->first();

        if ($existing !== null && $existing->processed_at !== null) {
            return response()->json(['status' => 'duplicate']);
        }

        if ($existing === null) {
            DB::table('stripe_webhook_events')->insert([
                'event_id'    => $event['id'],
                'event_type'  => $event['type'],
                'received_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        try {
            match ($event['type']) {
                'issuing_authorization.created' => $this->handleAuthorizationCreated($event),
                'issuing_transaction.created' => $this->handleTransactionCreated($event),
                'issuing_card.updated' => $this->handleCardUpdated($event),
                default => Log::info('Ignoring Stripe Issuing event', [
                    'type' => $event['type'],
                    'id'   => $event['id'],
                ]),
            };

            DB::table('stripe_webhook_events')
                ->where('event_id', $event['id'])
                ->update(['processed_at' => now(), 'updated_at' => now()]);
        } catch (Throwable $e) {
            Log::error('Stripe webhook handler failed', [
                'event_id' => $event['id'],
                'type'     => $event['type'],
                'error'    => $e->getMessage(),
            ]);

            return response()->json(['error' => 'handler failed'], 500);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleAuthorizationCreated(array $event): void
    {
        $auth = $event['data']['object'] ?? [];
        if (! is_array($auth)) {
            return;
        }

        $card = $this->findLocalCard((string) data_get($auth, 'card.id', ''));
        if (! $card instanceof Card) {
            return;
        }

        $amountUsdCents = abs((int) ($auth['amount'] ?? 0));
        $approved = (bool) ($auth['approved'] ?? false);
        $externalId = (string) ($auth['id'] ?? Str::uuid()->toString());

        DB::table('card_transactions')->updateOrInsert(
            ['external_id' => $externalId],
            [
                'card_id'                  => $card->id,
                'user_id'                  => $card->user_id,
                'processor_transaction_id' => $externalId,
                'authorization_id'         => $externalId,
                'merchant_name'            => (string) data_get($auth, 'merchant_data.name', 'Unknown merchant'),
                'merchant_category'        => (string) data_get($auth, 'merchant_data.category', ''),
                'amount_cents'             => $amountUsdCents,
                'currency'                 => 'USD',
                'billing_amount'           => $this->converter->toBillingAmount($amountUsdCents),
                'status'                   => $approved ? 'authorised' : 'declined',
                'transacted_at'            => now(),
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleTransactionCreated(array $event): void
    {
        $tx = $event['data']['object'] ?? [];
        if (! is_array($tx)) {
            return;
        }

        $card = $this->findLocalCard((string) data_get($tx, 'card.id', ''));
        if (! $card instanceof Card) {
            return;
        }

        $externalId = (string) ($tx['id'] ?? Str::uuid()->toString());
        $authorizationId = isset($tx['authorization']) ? (string) $tx['authorization'] : null;
        $amountUsdCents = abs((int) ($tx['amount'] ?? 0));

        DB::table('card_transactions')->updateOrInsert(
            ['external_id' => $externalId],
            [
                'card_id'                  => $card->id,
                'user_id'                  => $card->user_id,
                'processor_transaction_id' => $externalId,
                'authorization_id'         => $authorizationId,
                'merchant_name'            => (string) data_get($tx, 'merchant_data.name', 'Unknown merchant'),
                'merchant_category'        => (string) data_get($tx, 'merchant_data.category', ''),
                'amount_cents'             => $amountUsdCents,
                'currency'                 => 'USD',
                'billing_amount'           => $this->converter->toBillingAmount($amountUsdCents),
                'status'                   => 'settled',
                'settled_at'               => now(),
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
        );

        if ($authorizationId !== null && $authorizationId !== '') {
            DB::table('card_transactions')
                ->where(function ($query) use ($authorizationId): void {
                    $query->where('authorization_id', $authorizationId)
                        ->orWhere('external_id', $authorizationId);
                })
                ->update(['status' => 'settled', 'settled_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleCardUpdated(array $event): void
    {
        $stripeCard = $event['data']['object'] ?? [];
        if (! is_array($stripeCard)) {
            return;
        }

        $cardToken = (string) ($stripeCard['id'] ?? '');
        $status = match ((string) ($stripeCard['status'] ?? '')) {
            'active' => 'active',
            'inactive' => 'frozen_by_admin',
            'canceled' => 'cancelled',
            default => null,
        };

        if ($cardToken === '' || $status === null) {
            return;
        }

        DB::table('cards')
            ->where('issuer_card_token', $cardToken)
            ->update(['status' => $status, 'updated_at' => now()]);
    }

    private function findLocalCard(string $cardToken): ?Card
    {
        if ($cardToken === '') {
            return null;
        }

        return Card::query()->where('issuer_card_token', $cardToken)->first();
    }
}
