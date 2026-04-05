<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Rewards;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/rewards/points.
 *
 * Returns the rewards points balance for the authenticated user.
 */
class RewardsPointsController extends Controller
{
    public function __construct(
        private readonly RewardsPayloadBuilder $payloadBuilder,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        return response()->json([
            'status' => 'success',
            'remark' => 'rewards_points',
            'data'   => [
                'points' => $this->payloadBuilder->points($user),
            ],
        ]);
    }
}
