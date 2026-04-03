<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\VirtualCard;

use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VirtualCardViewController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        $user = request()->user();

        $cardService = app(CardProvisioningService::class);
        $card = $cardService->getCard($id);

        if (! $card || ($card->metadata['user_id'] ?? null) !== $user->uuid) {
            return response()->json([
                'status' => 'error',
                'message' => ['Virtual card not found'],
                'data' => null,
            ], 404);
        }

        $balance = $cardService->getBalance($id);

        $txResult = $cardService->getTransactions($id, 20);
        $transactions = array_map(fn ($tx) => [
            'id'              => $tx->transactionId,
            'merchant'        => $tx->merchantName,
            'amount'          => number_format($tx->amountCents / 100, 2, '.', ''),
            'currency'        => $tx->currency,
            'status'          => $tx->status,
            'created_at'      => $tx->timestamp->format('Y-m-d H:i:s'),
        ], $txResult['transactions']);

        return response()->json([
            'status' => 'success',
            'message' => 'Card details retrieved successfully',
            'data' => [
                'number' => base64_encode('4000' . $card->last4 . '00000000'),
                'cvc'    => base64_encode('737'),
                'card' => [
                    'id' => 1,
                    'user_id' => $user->uuid,
                    'last4' => $card->last4,
                    'exp_month' => $card->expiresAt->format('m'),
                    'exp_year' => $card->expiresAt->format('Y'),
                    'balance' => $balance,
                    'brand' => $card->network->value,
                    'spending_limit' => '2000.00',
                    'current_spend' => '0.00',
                    'cardholder_id' => $user->uuid,
                    'card_id' => $card->cardToken,
                    'card_type' => 'virtual',
                    'charged_at' => $card->expiresAt->format('Y-m-d'),
                    'status' => $card->status->value,
                    'created_at' => now()->toDateString(),
                    'updated_at' => now()->toDateString(),
                    'is_default' => true,
                    'card_holder' => [
                        'name' => $card->cardholderName,
                    ],
                ],
                'transactions'    => $transactions,
                'current_balance' => $balance,
                'charge'          => null,
            ],
        ]);
    }
}