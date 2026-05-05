<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Rewards;

use App\Domain\Rewards\Services\RewardsService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RewardsRedeemController extends Controller
{
    public function __construct(
        private readonly RewardsService $rewardsService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $rewardId = $request->input('reward_id');

        if (! is_string($rewardId) || trim($rewardId) === '') {
            return response()->json([
                'status' => 'error',
                'remark' => 'reward_redeem',
                'message' => ['Reward ID is required.'],
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $redemption = $this->rewardsService->redeemItem($user, $rewardId);

            return response()->json([
                'status' => 'success',
                'remark' => 'reward_redeem',
                'message' => ['Reward redeemed successfully.'],
                'data' => $redemption,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => 'error',
                'remark' => 'reward_redeem',
                'message' => [$exception->getMessage()],
            ], 422);
        }
    }
}
