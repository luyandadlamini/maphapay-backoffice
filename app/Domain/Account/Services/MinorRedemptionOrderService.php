<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorPointsLedger;
use App\Domain\Account\Models\MinorReward;
use App\Domain\Account\Models\MinorRewardRedemption;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MinorRedemptionOrderService
{
    public function __construct(
        private readonly MinorRewardService $rewards,
        private readonly MinorPointsService $points,
        private readonly MinorNotificationService $notifications,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function submit(Account $minorAccount, MinorReward $reward, int $quantity): MinorRewardRedemption
    {
        if (! $this->rewards->canAfford($minorAccount, $reward, $quantity)) {
            throw ValidationException::withMessages([
                'quantity' => ['The requested quantity exceeds the child points balance.'],
            ]);
        }

        $requiresApproval = $this->rewards->requiresApproval($reward, $quantity);

        return $this->rewards->redeem(
            $minorAccount,
            $reward,
            $quantity,
            $requiresApproval ? 'awaiting_approval' : 'approved',
            ! $requiresApproval,
        );
    }

    /**
     * @return LengthAwarePaginator<int, MinorRewardRedemption>
     */
    public function list(Account $minorAccount): LengthAwarePaginator
    {
        return MinorRewardRedemption::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->with('reward')
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    /**
     * @throws ValidationException
     */
    public function approve(Account $minorAccount, string $redemptionId, Account $guardianAccount): MinorRewardRedemption
    {
        /** @var MinorRewardRedemption $redemption */
        $redemption = DB::transaction(function () use ($minorAccount, $redemptionId, $guardianAccount): MinorRewardRedemption {
            /** @var MinorRewardRedemption $redemption */
            $redemption = MinorRewardRedemption::query()
                ->where('id', $redemptionId)
                ->where('minor_account_uuid', $minorAccount->uuid)
                ->lockForUpdate()
                ->with('reward')
                ->firstOrFail();

            if ($redemption->status === 'approved') {
                return $redemption;
            }

            if ($redemption->status !== 'awaiting_approval') {
                throw ValidationException::withMessages([
                    'redemption' => ['Only redemptions awaiting approval can be approved.'],
                ]);
            }

            $reward = MinorReward::query()
                ->lockForUpdate()
                ->findOrFail($redemption->minor_reward_id);

            $this->points->deduct(
                $minorAccount,
                $redemption->points_cost,
                'redemption',
                "Redeemed: {$reward->name}",
                $redemption->id,
                true,
            );

            $redemption->forceFill(['status' => 'approved'])->save();

            $this->notifications->notify(
                $minorAccount->uuid,
                MinorNotificationService::TYPE_APPROVAL_APPROVED,
                [
                    'redemption_id' => $redemption->id,
                    'reward_id' => $redemption->minor_reward_id,
                    'guardian_account_uuid' => $guardianAccount->uuid,
                    'points_cost' => $redemption->points_cost,
                ],
                $guardianAccount->user_uuid,
                'minor_reward_redemption',
                $redemption->id,
            );

            return $redemption->refresh();
        });

        return $redemption;
    }

    /**
     * @throws ValidationException
     */
    public function decline(Account $minorAccount, string $redemptionId, Account $guardianAccount): MinorRewardRedemption
    {
        /** @var MinorRewardRedemption $redemption */
        $redemption = DB::transaction(function () use ($minorAccount, $redemptionId, $guardianAccount): MinorRewardRedemption {
            /** @var MinorRewardRedemption $redemption */
            $redemption = MinorRewardRedemption::query()
                ->where('id', $redemptionId)
                ->where('minor_account_uuid', $minorAccount->uuid)
                ->lockForUpdate()
                ->with('reward')
                ->firstOrFail();

            if ($redemption->status === 'declined') {
                return $redemption;
            }

            if ($redemption->status !== 'awaiting_approval') {
                throw ValidationException::withMessages([
                    'redemption' => ['Only redemptions awaiting approval can be declined.'],
                ]);
            }

            $reward = MinorReward::query()
                ->lockForUpdate()
                ->findOrFail($redemption->minor_reward_id);

            if ($reward->stock !== -1) {
                $reward->increment('stock', $this->rewards->quantityForRedemption($redemption));
            }

            $deduction = MinorPointsLedger::query()
                ->where('minor_account_uuid', $minorAccount->uuid)
                ->where('source', 'redemption')
                ->where('reference_id', $redemption->id)
                ->lockForUpdate()
                ->first();

            if ($deduction !== null) {
                $this->points->award(
                    $minorAccount,
                    abs($deduction->points),
                    'redemption_refund',
                    "Refunded: {$reward->name}",
                    $redemption->id,
                    true,
                );
            }

            $redemption->forceFill(['status' => 'declined'])->save();

            $this->notifications->notify(
                $minorAccount->uuid,
                MinorNotificationService::TYPE_APPROVAL_DECLINED,
                [
                    'redemption_id' => $redemption->id,
                    'reward_id' => $redemption->minor_reward_id,
                    'guardian_account_uuid' => $guardianAccount->uuid,
                ],
                $guardianAccount->user_uuid,
                'minor_reward_redemption',
                $redemption->id,
            );

            return $redemption->refresh();
        });

        return $redemption;
    }
}
