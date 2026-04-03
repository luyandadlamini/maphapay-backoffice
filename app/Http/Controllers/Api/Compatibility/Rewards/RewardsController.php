<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Rewards;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/rewards
 *
 * Returns the rewards list for the authenticated user.
 */
class RewardsController extends Controller
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
            'remark' => 'rewards',
            'data'   => [
                'rewards' => $this->payloadBuilder->rewards($user),
            ],
        ]);
    }
}
