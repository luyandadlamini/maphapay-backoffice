<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorReward;
use App\Domain\Account\Models\MinorRewardRedemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MinorRewardService
{
    public function __construct(
        private readonly MinorPointsService $points,
        private readonly MinorNotificationService $notifications,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function redeem(Account $minorAccount, MinorReward $reward): MinorRewardRedemption
    {
        /** @var MinorRewardRedemption $redemption */
        $redemption = DB::transaction(function () use ($minorAccount, $reward): MinorRewardRedemption {
            /** @var MinorReward $lockedReward */
            $lockedReward = MinorReward::query()
                ->lockForUpdate()
                ->findOrFail($reward->id);

            if (! $lockedReward->is_active) {
                throw ValidationException::withMessages(['reward' => ['This reward is not currently available.']]);
            }

            if (! $lockedReward->hasStock()) {
                throw ValidationException::withMessages(['reward' => ['This reward is out of stock.']]);
            }

            $redemption = MinorRewardRedemption::query()->create([
                'minor_account_uuid' => $minorAccount->uuid,
                'minor_reward_id'    => $lockedReward->id,
                'points_cost'        => $lockedReward->points_cost,
                'status'             => 'pending',
            ]);

            $this->points->deduct(
                $minorAccount,
                $lockedReward->points_cost,
                'redemption',
                "Redeemed: {$lockedReward->name}",
                (string) $redemption->id,
                true,
            );

            if ($lockedReward->stock !== -1) {
                $lockedReward->decrement('stock');
            }

            $this->notifications->notify(
                $minorAccount->uuid,
                MinorNotificationService::TYPE_REWARD_REDEEMED,
                [
                    'reward_id'     => $lockedReward->id,
                    'reward_name'   => $lockedReward->name,
                    'points_cost'   => $lockedReward->points_cost,
                    'redemption_id' => (string) $redemption->id,
                ],
                $minorAccount->user_uuid,
                'minor_reward_redemption',
                (string) $redemption->id,
            );

            return $redemption->refresh();
        });

        return $redemption;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, MinorReward> */
    public function availableCatalog(Account $minorAccount): \Illuminate\Database\Eloquent\Collection
    {
        return MinorReward::active()
            ->where('min_permission_level', '<=', $minorAccount->permission_level ?? 1)
            ->get();
    }
}
