<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\ValueObjects\CardTransaction as CardTransactionValueObject;
use App\Domain\CardSubscriptions\Http\Concerns\RespondsWithCardApiEnvelope;
use App\Domain\CardSubscriptions\Http\Requests\CardDisputeRequest;
use App\Domain\CardSubscriptions\Models\CardTransaction as CardTransactionRecord;
use App\Domain\CardSubscriptions\Services\CardProductAuthorizationCoordinator;
use App\Http\Controllers\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardTransactionController extends Controller
{
    use RespondsWithCardApiEnvelope;

    public function __construct(
        private readonly CardProductAuthorizationCoordinator $cardProductAuthorization,
    ) {
    }

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
                'id'                     => (string) $t->id,
                'card_id'                => (string) $t->card_id,
                'transaction_type'       => $t->status === 'pending' ? 'authorisation' : 'clearing',
                'status'                 => $t->status === 'authorised' ? 'approved' : $t->status,
                'amount'                 => number_format($t->amount_cents / 100, 2, '.', ''),
                'currency'               => $t->currency,
                'billing_amount'         => $t->billing_amount ?? number_format($t->amount_cents / 100, 2, '.', ''),
                'billing_currency'       => 'SZL',
                'merchant_name'          => $t->merchant_name,
                'merchant_country'       => null,
                'merchant_category_code' => $t->merchant_category,
                'fx_rate'                => null,
                'fx_fee'                 => '0.00',
                'mapha_fee'              => '0.00',
                'scheme_fee'             => '0.00',
                'decline_reason'         => null,
                'authorised_at'          => $t->created_at?->toIso8601String(),
                'settled_at'             => $t->settled_at?->toIso8601String(),
            ];
        })->values()->all();

        return $this->cardSuccess('card_transactions', [
            'transactions' => $data,
            'pagination'   => [
                'cursor'   => null,
                'has_more' => false,
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
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
            timestamp: new DateTimeImmutable()
        );

        return $this->cardSuccess('card_transaction', [
            'transaction' => (new JsonResource($transaction->toArray()))->resolve($request),
        ]);
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
            'card_id'        => $cardId,
            'transaction_id' => $transactionId,
            'dispute'        => [
                'reason'          => $request->validated('reason'),
                'description'     => $request->validated('description'),
                'disputed_amount' => $request->validated('disputed_amount'),
            ],
        ], $idempotencyKey);
    }
}
