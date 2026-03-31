<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\VirtualCard;

use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VirtualCardCancelController extends Controller
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

        $cardService->cancelCard($id, 'User requested cancellation');

        return response()->json([
            'remark' => 'Card cancelled successfully',
            'status' => 'success',
            'message' => ['Virtual card cancelled successfully'],
            'data' => [
                'card_id' => $id,
            ],
        ]);
    }
}