<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorReward;
use App\Domain\Account\Models\MinorRewardRedemption;
use Illuminate\Database\Eloquent\Collection;
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
    public function redeem(
        Account $minorAccount,
        MinorReward $reward,
        int $quantity = 1,
        string $status = 'pending',
        bool $deductPoints = true,
    ): MinorRewardRedemption {
        $this->assertValidQuantity($quantity);

        /** @var MinorRewardRedemption $redemption */
        $redemption = DB::transaction(function () use ($minorAccount, $reward, $quantity, $status, $deductPoints): MinorRewardRedemption {
            /** @var MinorReward $lockedReward */
            $lockedReward = MinorReward::query()
                ->lockForUpdate()
                ->findOrFail($reward->id);

            $this->assertRedeemableForAccount($minorAccount, $lockedReward);
            $this->assertHasSufficientStock($lockedReward, $quantity);

            $pointsCost = $this->calculateTotalPointsCost($lockedReward, $quantity);

            $redemption = MinorRewardRedemption::query()->create([
                'minor_account_uuid' => $minorAccount->uuid,
                'minor_reward_id'    => $lockedReward->id,
                'points_cost'        => $pointsCost,
                'status'             => $status,
            ]);

            if ($deductPoints) {
                $this->points->deduct(
                    $minorAccount,
                    $pointsCost,
                    'redemption',
                    "Redeemed: {$lockedReward->name}",
                    (string) $redemption->id,
                    true,
                );
            }

            if ($lockedReward->stock !== -1) {
                $lockedReward->decrement('stock', $quantity);
            }

            $this->notifications->notify(
                $minorAccount->uuid,
                MinorNotificationService::TYPE_REWARD_REDEEMED,
                [
                    'reward_id'     => $lockedReward->id,
                    'reward_name'   => $lockedReward->name,
                    'points_cost'   => $pointsCost,
                    'quantity'      => $quantity,
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

    /** @return Collection<int, MinorReward> */
    public function availableCatalog(Account $minorAccount): Collection
    {
        return MinorReward::active()
            ->where('min_permission_level', '<=', $minorAccount->permission_level ?? 1)
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->orderBy('name')
            ->get();
    }

    public function findCatalogReward(Account $minorAccount, string $rewardId): MinorReward
    {
        /** @var MinorReward $reward */
        $reward = MinorReward::query()->findOrFail($rewardId);

        $this->assertRedeemableForAccount($minorAccount, $reward);

        return $reward;
    }

    public function calculateTotalPointsCost(MinorReward $reward, int $quantity = 1): int
    {
        $this->assertValidQuantity($quantity);

        return $this->unitPrice($reward) * $quantity;
    }

    public function requiresApproval(MinorReward $reward, int $quantity = 1): bool
    {
        return $this->calculateTotalPointsCost($reward, $quantity) > $this->approvalThreshold();
    }

    public function canAfford(Account $minorAccount, MinorReward $reward, int $quantity = 1): bool
    {
        return $this->points->getBalance($minorAccount) >= $this->calculateTotalPointsCost($reward, $quantity);
    }

    public function quantityForRedemption(MinorRewardRedemption $redemption): int
    {
        $unitPrice = $this->unitPrice($redemption->reward);

        if ($unitPrice <= 0) {
            return 1;
        }

        $quantity = intdiv(max($redemption->points_cost, $unitPrice), $unitPrice);

        return max(1, $quantity);
    }

    public function approvalThreshold(): int
    {
        return (int) config('minor_accounts.redemptions.approval_threshold', 250);
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogPayload(Account $minorAccount, MinorReward $reward): array
    {
        $isAffordable = $this->canAfford($minorAccount, $reward);

        return [
            'id'                   => $reward->id,
            'name'                 => $reward->name,
            'category'             => $reward->category,
            'description'          => $reward->description,
            'image_url'            => $reward->image_url,
            'price_points'         => $this->unitPrice($reward),
            'type'                 => $reward->type,
            'metadata'             => $reward->metadata,
            'stock_remaining'      => $reward->stock,
            'is_active'            => $reward->is_active,
            'is_featured'          => $reward->is_featured,
            'requires_approval'    => $this->requiresApproval($reward),
            'is_affordable'        => $isAffordable,
            'can_redeem'           => $isAffordable && $reward->hasStock(),
            'min_permission_level' => $reward->min_permission_level,
        ];
    }

    private function unitPrice(?MinorReward $reward): int
    {
        if ($reward === null) {
            return 0;
        }

        return (int) ($reward->price_points ?? $reward->points_cost);
    }

    private function assertValidQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be at least 1.'],
            ]);
        }
    }

    private function assertRedeemableForAccount(Account $minorAccount, MinorReward $reward): void
    {
        if (! $reward->is_active) {
            throw ValidationException::withMessages(['reward' => ['This reward is not currently available.']]);
        }

        if (($minorAccount->permission_level ?? 1) < $reward->min_permission_level) {
            throw ValidationException::withMessages(['reward' => ['This reward is not available for this minor account.']]);
        }
    }

    private function assertHasSufficientStock(MinorReward $reward, int $quantity): void
    {
        if (! $reward->hasStock() || ($reward->stock !== -1 && $reward->stock < $quantity)) {
            throw ValidationException::withMessages(['reward' => ['This reward is out of stock.']]);
        }
    }
}
