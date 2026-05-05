<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\VirtualCard;

use App\Domain\CardIssuance\Enums\CardStatus;
use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Domain\CardIssuance\ValueObjects\VirtualCard;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VirtualCardUnfreezeController extends Controller
{
    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $cardService = app(CardProvisioningService::class);
        $card = $cardService->getCard($id);

        if (! $card || ($card->metadata['user_id'] ?? null) !== $user->uuid) {
            return response()->json([
                'status'  => 'error',
                'message' => ['Virtual card not found'],
                'data'    => null,
            ], 404);
        }

        if ($card->status === CardStatus::FROZEN && ! $cardService->unfreezeCard($id)) {
            return response()->json([
                'status'  => 'error',
                'message' => ['Unable to unfreeze virtual card'],
                'data'    => null,
            ], 422);
        }

        $updatedCard = $cardService->getCard($id) ?? $card;

        return response()->json([
            'status'  => 'success',
            'message' => 'Virtual card unfrozen successfully',
            'data'    => [
                'card' => $this->formatCard($user, $updatedCard),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCard(User $user, VirtualCard $card): array
    {
        return [
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
        ];
    }
}
