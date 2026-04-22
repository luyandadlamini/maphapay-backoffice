<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorRewardRedemption;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Domain\Account\Services\MinorRedemptionOrderService;
use App\Domain\Account\Services\MinorRewardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MinorRedemptionOrdersController extends Controller
{
    public function __construct(
        private readonly MinorAccountAccessService $accessService,
        private readonly MinorRewardService $rewardService,
        private readonly MinorRedemptionOrderService $redemptionOrders,
    ) {
    }

    public function submit(Request $request, string $uuid): JsonResponse
    {
        $minorAccount = Account::query()->where('uuid', $uuid)->firstOrFail();

        $this->accessService->authorizeView($request->user(), $minorAccount);

        $validated = $request->validate([
            'reward_id' => ['required', 'string'],
            'quantity'  => ['sometimes', 'integer', 'min:1'],
        ]);

        $reward = $this->rewardService->findCatalogReward($minorAccount, (string) $validated['reward_id']);
        $quantity = (int) ($validated['quantity'] ?? 1);

        try {
            $redemption = $this->redemptionOrders->submit($minorAccount, $reward, $quantity);

            return response()->json([
                'success' => true,
                'data'    => $this->transformRedemption($redemption),
            ], 201);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Redemption submission failed.',
                'errors'  => $exception->errors(),
            ], 422);
        }
    }

    public function index(Request $request, string $uuid): JsonResponse
    {
        $minorAccount = Account::query()->where('uuid', $uuid)->firstOrFail();

        $this->accessService->authorizeView($request->user(), $minorAccount);

        $paginated = $this->redemptionOrders->list($minorAccount);
        $orders = $paginated->getCollection()
            ->map(fn (MinorRewardRedemption $redemption) => $this->transformRedemption($redemption))
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'minor_account_uuid' => $minorAccount->uuid,
                'redemptions'        => $orders,
            ],
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    public function approve(Request $request, string $uuid, string $id): JsonResponse
    {
        $minorAccount = Account::query()->where('uuid', $uuid)->firstOrFail();
        $guardianAccount = $this->accessService->authorizeGuardian(
            $request->user(),
            $minorAccount,
            $request->header('X-Account-Context')
        );

        try {
            $redemption = $this->redemptionOrders->approve($minorAccount, $id, $guardianAccount);

            return response()->json([
                'success' => true,
                'data'    => $this->transformRedemption($redemption),
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Redemption approval failed.',
                'errors'  => $exception->errors(),
            ], 422);
        }
    }

    public function decline(Request $request, string $uuid, string $id): JsonResponse
    {
        $minorAccount = Account::query()->where('uuid', $uuid)->firstOrFail();
        $guardianAccount = $this->accessService->authorizeGuardian(
            $request->user(),
            $minorAccount,
            $request->header('X-Account-Context')
        );

        try {
            $redemption = $this->redemptionOrders->decline($minorAccount, $id, $guardianAccount);

            return response()->json([
                'success' => true,
                'data'    => $this->transformRedemption($redemption),
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Redemption decline failed.',
                'errors'  => $exception->errors(),
            ], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function transformRedemption(MinorRewardRedemption $redemption): array
    {
        $reward = $redemption->relationLoaded('reward') ? $redemption->reward : $redemption->reward()->first();
        $quantity = $this->rewardService->quantityForRedemption($redemption->setRelation('reward', $reward));

        return [
            'id'                 => $redemption->id,
            'minor_account_uuid' => $redemption->minor_account_uuid,
            'reward_id'          => $redemption->minor_reward_id,
            'reward_name'        => $reward?->name,
            'quantity'           => $quantity,
            'points_cost'        => $redemption->points_cost,
            'status'             => $redemption->status,
            'requires_approval'  => $redemption->status === 'awaiting_approval' || $redemption->points_cost > $this->rewardService->approvalThreshold(),
            'fulfilled_at'       => $redemption->fulfilled_at?->toIso8601String(),
            'created_at'         => $redemption->created_at?->toIso8601String(),
        ];
    }
}
