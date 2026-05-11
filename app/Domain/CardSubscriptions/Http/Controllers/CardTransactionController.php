<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\ValueObjects\CardTransaction as CardTransactionValueObject;
use App\Domain\CardSubscriptions\Http\Requests\CardDisputeRequest;
use App\Domain\CardSubscriptions\Models\CardTransaction as CardTransactionRecord;
use App\Domain\CardSubscriptions\Services\CardProductAuthorizationCoordinator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardTransactionController extends Controller
{
    public function __construct(
        private readonly CardProductAuthorizationCoordinator $cardProductAuthorization,
    ) {}

    public function index(Request $request, string $cardId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $card = Card::query()
            ->whereKey($cardId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $rows = CardTransactionRecord::query()
            ->where('card_id', $card->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $data = $rows->map(static function (CardTransactionRecord $t): array {
            return [
                'id'                => (string) $t->id,
                'status'            => $t->status,
                'amount'            => number_format($t->amount_cents / 100, 2, '.', ''),
                'currency'          => $t->currency,
                'merchant_name'     => $t->merchant_name,
                'merchant_category' => $t->merchant_category,
                'settled_at'        => $t->settled_at?->toIso8601String(),
                'authorization_id'  => $t->authorization_id,
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $id): JsonResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $transaction = new CardTransactionValueObject(
            transactionId: $id,
            cardToken: $id,
            merchantName: 'Mock Merchant',
            merchantCategory: 'Mock Category',
            amountCents: 1000,
            currency: 'ZAR',
            status: 'settled',
            timestamp: new \DateTimeImmutable()
        );

        return new JsonResource($transaction->toArray());
    }

    public function dispute(CardDisputeRequest $request, string $transactionId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $cardId = $request->validated('card_id');

        Card::query()
            ->whereKey($cardId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'dispute_transaction', [
            'card_id'         => $cardId,
            'transaction_id'  => $transactionId,
            'dispute'         => [
                'reason'           => $request->validated('reason'),
                'description'      => $request->validated('description'),
                'disputed_amount'  => $request->validated('disputed_amount'),
            ],
        ], $idempotencyKey);
    }
}
