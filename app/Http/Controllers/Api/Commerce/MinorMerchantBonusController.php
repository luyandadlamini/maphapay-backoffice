<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Commerce;

use App\Domain\Account\Services\MinorMerchantBonusService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Throwable;

class MinorMerchantBonusController extends Controller
{
    public function __construct(
        private readonly MinorMerchantBonusService $bonusService,
    ) {
    }

    #[OA\Post(
        path: '/internal/minor-merchant-bonus/award',
        operationId: 'minorMerchantBonusAward',
        summary: 'Award bonus points for QR payment',
        tags: ['Internal'],
        security: [[]]
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['transaction_uuid', 'merchant_partner_id', 'minor_account_uuid', 'amount_szl'], properties: [
        new OA\Property(property: 'transaction_uuid', type: 'string'),
        new OA\Property(property: 'merchant_partner_id', type: 'integer'),
        new OA\Property(property: 'minor_account_uuid', type: 'string'),
        new OA\Property(property: 'amount_szl', type: 'number'),
        new OA\Property(property: 'minor_age', type: 'integer', nullable: true),
    ]))]
    #[OA\Response(response: 200)]
    #[OA\Response(response: 401)]
    #[OA\Response(response: 422)]
    public function award(Request $request): JsonResponse
    {
        $request->validate([
            'transaction_uuid'    => ['required', 'string'],
            'merchant_partner_id' => ['required', 'integer'],
            'minor_account_uuid'  => ['required', 'string'],
            'amount_szl'          => ['required', 'numeric', 'min:0'],
            'minor_age'           => ['nullable', 'integer', 'min:0', 'max:17'],
        ]);

        try {
            $result = $this->bonusService->awardBonus(
                (string) $request->input('transaction_uuid'),
                (int) $request->input('merchant_partner_id'),
                (string) $request->input('minor_account_uuid'),
                (float) $request->input('amount_szl'),
                $request->has('minor_age') ? (int) $request->input('minor_age') : null,
            );

            return response()->json([
                'success' => true,
                'data'    => [
                    'bonus_points_awarded' => $result['bonus_points_awarded'],
                    'multiplier_applied'   => $result['multiplier_applied'],
                    'reason'               => $result['reason'] ?? null,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'BONUS_CALCULATION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}
