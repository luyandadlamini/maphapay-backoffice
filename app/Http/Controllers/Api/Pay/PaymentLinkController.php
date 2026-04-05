<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pay;

use App\Domain\Payment\Services\PaymentLinkService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PaymentLinkController extends Controller
{
    public function __construct(
        private readonly PaymentLinkService $paymentLinkService,
    ) {
    }

    /**
     * Validate payment token and return link data (unauthenticated).
     */
    public function show(string $token): JsonResponse
    {
        $data = $this->paymentLinkService->getPaymentLinkData($token);

        if (! $data) {
            return response()->json([
                'status'  => 'error',
                'message' => ['Payment link not found or expired'],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }
}
