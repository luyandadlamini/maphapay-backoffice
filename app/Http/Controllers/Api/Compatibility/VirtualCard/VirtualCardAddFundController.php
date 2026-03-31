<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\VirtualCard;

use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VirtualCardAddFundController extends Controller
{
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

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

        $newBalance = $cardService->addFunds($id, (float) $validated['amount']);

        return response()->json([
            'remark' => 'Funds added successfully',
            'status' => 'success',
            'message' => ['Funds added to virtual card successfully'],
            'data' => [
                'card' => [
                    'id' => 1,
                    'user_id' => $user->uuid,
                    'last4' => $card->last4,
                    'exp_month' => $card->expiresAt->format('m'),
                    'exp_year' => $card->expiresAt->format('Y'),
                    'balance' => $newBalance,
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
                    'is_default' => false,
                    'card_holder' => [
                        'name' => $card->cardholderName,
                    ],
                ],
            ],
        ]);
    }
}