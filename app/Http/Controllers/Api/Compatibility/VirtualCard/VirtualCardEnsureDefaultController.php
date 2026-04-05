<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\VirtualCard;

use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VirtualCardEnsureDefaultController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $user = request()->user();

        $cardService = app(CardProvisioningService::class);

        $existingCards = $cardService->listUserCards($user->uuid);
        if (count($existingCards) > 0) {
            $card = $existingCards[0];

            return response()->json([
                'status'  => 'success',
                'message' => 'Default virtual card already exists',
                'data'    => [
                    'card' => [
                        'id'             => 1,
                        'user_id'        => $user->uuid,
                        'last4'          => $card->last4,
                        'exp_month'      => $card->expiresAt->format('m'),
                        'exp_year'       => $card->expiresAt->format('Y'),
                        'balance'        => '0.00',
                        'brand'          => $card->network->value,
                        'spending_limit' => '0.00',
                        'current_spend'  => '0.00',
                        'cardholder_id'  => $user->uuid,
                        'card_id'        => $card->cardToken,
                        'card_type'      => 'virtual',
                        'charged_at'     => $card->expiresAt->format('Y-m-d'),
                        'status'         => $card->status->value,
                        'created_at'     => now()->toDateString(),
                        'updated_at'     => now()->toDateString(),
                        'is_default'     => true,
                        'card_holder'    => [
                            'name' => $card->cardholderName,
                        ],
                    ],
                ],
            ]);
        }

        $card = $cardService->createCard(
            userId: $user->uuid,
            cardholderName: $user->name ?? 'Card Holder',
            metadata: ['is_default' => true],
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Default virtual card created successfully',
            'data'    => [
                'card' => [
                    'id'             => 1,
                    'user_id'        => $user->uuid,
                    'last4'          => $card->last4,
                    'exp_month'      => $card->expiresAt->format('m'),
                    'exp_year'       => $card->expiresAt->format('Y'),
                    'balance'        => '0.00',
                    'brand'          => $card->network->value,
                    'spending_limit' => '0.00',
                    'current_spend'  => '0.00',
                    'cardholder_id'  => $user->uuid,
                    'card_id'        => $card->cardToken,
                    'card_type'      => 'virtual',
                    'charged_at'     => $card->expiresAt->format('Y-m-d'),
                    'status'         => $card->status->value,
                    'created_at'     => now()->toDateString(),
                    'updated_at'     => now()->toDateString(),
                    'is_default'     => true,
                    'card_holder'    => [
                        'name' => $card->cardholderName,
                    ],
                ],
            ],
        ], 201);
    }
}
