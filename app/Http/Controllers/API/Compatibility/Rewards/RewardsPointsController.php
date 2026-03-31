<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Rewards;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/rewards/points
 *
 * Returns the rewards points balance for the authenticated user.
 */
class RewardsPointsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => [
                'points' => 0,
                'tier'   => 'bronze',
            ],
        ]);
    }
}
