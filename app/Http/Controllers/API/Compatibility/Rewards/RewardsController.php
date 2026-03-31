<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Rewards;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/rewards
 *
 * Returns the rewards list for the authenticated user.
 */
class RewardsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => [],
        ]);
    }
}
