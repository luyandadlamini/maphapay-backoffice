<?php
declare(strict_types=1);
namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorReward;
use App\Domain\Account\Models\MinorRewardRedemption;
use Illuminate\Validation\ValidationException;

class MinorRewardService
{
    public function __construct(private readonly MinorPointsService $points) {}

    /**
     * @throws ValidationException
     */
    public function redeem(Account $minorAccount, MinorReward $reward): MinorRewardRedemption
    {
        if (! $reward->is_active) {
            throw ValidationException::withMessages(['reward' => ['This reward is not currently available.']]);
        }

        if (! $reward->hasStock()) {
            throw ValidationException::withMessages(['reward' => ['This reward is out of stock.']]);
        }

        // Deduct points first — throws ValidationException if insufficient
        $this->points->deduct(
            $minorAccount,
            $reward->points_cost,
            'redemption',
            "Redeemed: {$reward->name}",
            null // will be updated to redemption UUID below
        );

        $redemption = MinorRewardRedemption::create([
            'minor_account_uuid' => $minorAccount->uuid,
            'minor_reward_id'    => $reward->id,
            'points_cost'        => $reward->points_cost,
            'status'             => 'pending',
        ]);

        // Update the ledger entry reference_id to link to this redemption
        \App\Domain\Account\Models\MinorPointsLedger::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->where('source', 'redemption')
            ->whereNull('reference_id')
            ->latest()
            ->first()
            ?->update(['reference_id' => $redemption->id]);

        // Decrement stock (skip for unlimited)
        if ($reward->stock !== -1) {
            $reward->decrement('stock');
        }

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
