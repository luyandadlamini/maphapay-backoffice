<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\WalletLinking;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/wallet-linking.
 *
 * Returns linked external wallets (e.g. MTN MoMo) for the authenticated user.
 */
class WalletLinkingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => [],
        ]);
    }
}
