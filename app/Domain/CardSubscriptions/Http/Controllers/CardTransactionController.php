<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\ValueObjects\CardTransaction as CardTransactionValueObject;
use App\Domain\CardSubscriptions\Http\Requests\CardDisputeRequest;
use App\Domain\CardSubscriptions\Http\Resources\CardDisputeResource;
use App\Domain\CardSubscriptions\Models\CardTransaction as CardTransactionRecord;
use App\Domain\CardSubscriptions\Services\CardDisputeService;
use App\Domain\CardSubscriptions\ValueObjects\DisputeInput;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardTransactionController extends Controller
{
    public function __construct(
        private readonly CardDisputeService $disputeService
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
                'id'                  => (string) $t->id,
                'status'              => $t->status,
                'amount'              => number_format($t->amount_cents / 100, 2, '.', ''),
                'currency'            => $t->currency,
                'merchant_name'       => $t->merchant_name,
                'merchant_category'   => $t->merchant_category,
                'settled_at'          => $t->settled_at?->toIso8601String(),
                'authorization_id'    => $t->authorization_id,
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $cardId, string $transactionId): JsonResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        
        // Mock retrieval
        $transaction = new CardTransactionValueObject(
            transactionId: $transactionId,
            cardToken: $cardId,
            merchantName: 'Mock Merchant',
            merchantCategory: 'Mock Category',
            amountCents: 1000,
            currency: 'ZAR',
            status: 'settled',
            timestamp: new \DateTimeImmutable()
        );

        return new JsonResource($transaction->toArray());
    }

    public function dispute(CardDisputeRequest $request, string $cardId, string $transactionId): CardDisputeResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        
        $transaction = new CardTransactionValueObject(
            transactionId: $transactionId,
            cardToken: $cardId,
            merchantName: 'Mock Merchant',
            merchantCategory: 'Mock Category',
            amountCents: 1000,
            currency: 'ZAR',
            status: 'settled',
            timestamp: new \DateTimeImmutable()
        );

        $input = new DisputeInput(
            reason: $request->validated('reason'),
            description: $request->validated('description'),
            amountCents: (int) round(((float) $request->validated('disputed_amount')) * 100)
        );

        $dispute = $this->disputeService->open(
            user: $user,
            transaction: $transaction,
            input: $input
        );

        return new CardDisputeResource($dispute);
    }
}
