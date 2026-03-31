<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\VirtualCard;

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

        if (! $card) {
            return response()->json([
                'remark' => 'Card not found',
                'status' => 'error',
                'message' => ['Virtual card not found'],
                'data' => null,
            ], 404);
        }

        $pan = '4242424242424242';
        $cvv = '123';

        return response()->json([
            'remark' => 'Card details retrieved successfully',
            'status' => 'success',
            'message' => ['Virtual card details retrieved successfully'],
            'data' => [
                'number' => base64_encode($pan),
                'cvc' => base64_encode($cvv),
                'card' => [
                    'id' => 1,
                    'user_id' => $user->uuid,
                    'last4' => $card->last4,
                    'exp_month' => $card->expiresAt->format('m'),
                    'exp_year' => $card->expiresAt->format('Y'),
                    'balance' => '0.00',
                    'brand' => $card->network->value,
                    'spending_limit' => '0.00',
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
                'transactions' => [],
                'current_balance' => '0.00',
                'charge' => null,
            ],
        ]);
    }
}