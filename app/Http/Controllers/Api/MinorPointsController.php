<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorPointsLedger;
use App\Domain\Account\Models\MinorRewardRedemption;
use App\Domain\Account\Services\MinorPointsService;
use App\Domain\Account\Services\MinorRewardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class MinorPointsController extends Controller
{
    public function __construct(
        private readonly MinorPointsService $pointsService,
        private readonly MinorRewardService $rewardService,
    ) {
    }

    /**
     * GET /api/accounts/minor/{uuid}/points
     *
     * Returns the current points balance for a minor account.
     */
    public function balance(Request $request, string $uuid): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $account);

        $balance = $this->pointsService->getBalance($account);

        return response()->json([
            'success' => true,
            'data'    => [
                'minor_account_uuid' => $account->uuid,
                'balance'            => $balance,
            ],
        ]);
    }

    /**
     * GET /api/accounts/minor/{uuid}/points/history
     *
     * Returns the points ledger history for a minor account.
     */
    public function history(Request $request, string $uuid): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $account);

        $paginated = MinorPointsLedger::query()
            ->where('minor_account_uuid', $account->uuid)
            ->orderByDesc('created_at')
            ->paginate(20);

        $ledgerEntries = $paginated->map(fn(MinorPointsLedger $entry) => [
            'id'            => $entry->id,
            'points'        => $entry->points,
            'source'        => $entry->source,
            'description'   => $entry->description,
            'reference_id'  => $entry->reference_id,
            'created_at'    => $entry->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'minor_account_uuid' => $account->uuid,
                'history'            => $ledgerEntries,
            ],
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    /**
     * GET /api/accounts/minor/{uuid}/rewards
     *
     * Returns the available rewards catalog for a minor account.
     */
    public function catalog(Request $request, string $uuid): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $account);

        $rewards = $this->rewardService->availableCatalog($account)
            ->map(fn($reward) => [
                'id'                  => $reward->id,
                'name'                => $reward->name,
                'description'         => $reward->description,
                'points_cost'         => $reward->points_cost,
                'type'                => $reward->type,
                'metadata'            => $reward->metadata,
                'stock'               => $reward->stock,
                'is_active'           => $reward->is_active,
                'min_permission_level' => $reward->min_permission_level,
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'minor_account_uuid' => $account->uuid,
                'rewards'            => $rewards,
            ],
        ]);
    }

    /**
     * POST /api/accounts/minor/{uuid}/rewards/{rewardId}/redeem
     *
     * Redeems a reward for a minor account.
     */
    public function redeem(Request $request, string $uuid, string $rewardId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $account);

        $reward = \App\Domain\Account\Models\MinorReward::query()
            ->where('id', $rewardId)
            ->firstOrFail();

        try {
            $redemption = $this->rewardService->redeem($account, $reward);

            return response()->json([
                'success' => true,
                'data'    => [
                    'redemption_id'      => $redemption->id,
                    'minor_account_uuid' => $redemption->minor_account_uuid,
                    'reward_id'          => $redemption->minor_reward_id,
                    'points_cost'        => $redemption->points_cost,
                    'status'             => $redemption->status,
                    'created_at'         => $redemption->created_at?->toIso8601String(),
                ],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Redemption failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MinorPointsController: redeem failed', [
                'user_uuid'  => $user->uuid,
                'account_uuid' => $account->uuid,
                'reward_id'  => $rewardId,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing the redemption.',
            ], 500);
        }
    }

    /**
     * GET /api/accounts/minor/{uuid}/rewards/redemptions
     *
     * Returns the redemption history for a minor account.
     */
    public function redemptions(Request $request, string $uuid): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $account);

        $paginated = MinorRewardRedemption::query()
            ->where('minor_account_uuid', $account->uuid)
            ->with('reward')
            ->orderByDesc('created_at')
            ->paginate(20);

        $redemptions = $paginated->map(fn(MinorRewardRedemption $redemption) => [
            'id'                 => $redemption->id,
            'reward_id'          => $redemption->minor_reward_id,
            'reward_name'        => $redemption->reward?->name,
            'points_cost'        => $redemption->points_cost,
            'status'             => $redemption->status,
            'fulfilled_at'       => $redemption->fulfilled_at?->toIso8601String(),
            'created_at'         => $redemption->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'minor_account_uuid' => $account->uuid,
                'redemptions'        => $redemptions,
            ],
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    /**
     * Private authorization helper.
     *
     * Ensures the authenticated user is the child or a guardian of the minor account.
     */
    private function authorize(string $ability, Account $account): void
    {
        /** @var \App\Models\User $user */
        $user = request()->user();
        if (! $user) {
            abort(401);
        }
        // Guardian or the child themselves can view points
        // Policy check: user's account must be the minor account OR have guardian membership
        $userAccount = Account::where('user_uuid', $user->uuid)->first();
        $isChild     = $userAccount?->uuid === $account->uuid;
        $isGuardian  = AccountMembership::query()
            ->where('account_uuid', $userAccount?->uuid ?? '')
            ->where('minor_account_uuid', $account->uuid)
            ->where('role', 'guardian')
            ->exists();

        if (! $isChild && ! $isGuardian) {
            abort(403, 'Forbidden. Only the child or their guardian may access this resource.');
        }
    }
}
