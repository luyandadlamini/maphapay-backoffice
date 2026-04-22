<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Domain\Account\Services\MinorPointsService;
use App\Domain\Account\Services\MinorRewardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MinorRewardsCatalogController extends Controller
{
    public function __construct(
        private readonly MinorAccountAccessService $accessService,
        private readonly MinorRewardService $rewardService,
        private readonly MinorPointsService $pointsService,
    ) {
    }

    public function index(Request $request, string $uuid): JsonResponse
    {
        $minorAccount = Account::query()->where('uuid', $uuid)->firstOrFail();

        $this->accessService->authorizeView($request->user(), $minorAccount);

        $rewards = $this->rewardService->availableCatalog($minorAccount)
            ->map(fn ($reward) => $this->rewardService->catalogPayload($minorAccount, $reward))
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'minor_account_uuid' => $minorAccount->uuid,
                'points_balance'     => $this->pointsService->getBalance($minorAccount),
                'approval_threshold' => $this->rewardService->approvalThreshold(),
                'rewards'            => $rewards,
            ],
        ]);
    }

    public function show(Request $request, string $uuid, string $rewardId): JsonResponse
    {
        $minorAccount = Account::query()->where('uuid', $uuid)->firstOrFail();

        $this->accessService->authorizeView($request->user(), $minorAccount);

        $reward = $this->rewardService->findCatalogReward($minorAccount, $rewardId);

        return response()->json([
            'success' => true,
            'data'    => [
                'minor_account_uuid' => $minorAccount->uuid,
                'points_balance'     => $this->pointsService->getBalance($minorAccount),
                'approval_threshold' => $this->rewardService->approvalThreshold(),
                'reward'             => $this->rewardService->catalogPayload($minorAccount, $reward),
            ],
        ]);
    }
}
