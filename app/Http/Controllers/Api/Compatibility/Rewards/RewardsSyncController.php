<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Rewards;

use App\Http\Controllers\Api\Compatibility\Concerns\ParsesChangedSince;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardsSyncController extends Controller
{
    use ParsesChangedSince;

    public function __construct(
        private readonly RewardsPayloadBuilder $payloadBuilder,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $changedSince = $this->parseChangedSince($request);

        return response()->json([
            'status' => 'success',
            'remark' => 'rewards_sync',
            'data'   => [
                'rewards' => $this->payloadBuilder->rewards($user, $changedSince),
                'points'  => $this->payloadBuilder->points($user),
            ],
            'deleted_ids'     => [],
            'next_sync_token' => $this->nextSyncToken($this->payloadBuilder->latestTimestamps($user)),
        ]);
    }
}
