<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Rewards;

use App\Domain\Rewards\Models\RewardProfile;
use App\Domain\Rewards\Models\RewardRedemption;
use App\Domain\Rewards\Models\RewardShopItem;
use App\Models\User;
use Carbon\CarbonImmutable;

class RewardsPayloadBuilder
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function rewards(User $user, ?CarbonImmutable $changedSince = null): array
    {
        $profile = $this->profile($user);
        $redeemedItemIds = RewardRedemption::query()
            ->where('reward_profile_id', $profile->id)
            ->where('status', 'completed')
            ->pluck('shop_item_id')
            ->all();

        $query = RewardShopItem::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('title');

        if ($changedSince !== null) {
            $query->where(function ($builder) use ($changedSince, $profile): void {
                $builder->where('updated_at', '>', $changedSince)
                    ->orWhereHas('redemptions', function ($redemptions) use ($changedSince, $profile): void {
                        $redemptions
                            ->where('reward_profile_id', $profile->id)
                            ->where('updated_at', '>', $changedSince);
                    });
            });
        }

        return $query->get()
            ->map(function (RewardShopItem $item) use ($redeemedItemIds): array {
                $metadata = is_array($item->metadata) ? $item->metadata : [];

                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'points_cost' => $item->points_cost,
                    'category_id' => $item->category,
                    'image' => $metadata['image'] ?? null,
                    'bg_color' => $metadata['bg_color'] ?? null,
                    'expires_at' => $metadata['expires_at'] ?? null,
                    'is_redeemed' => in_array($item->id, $redeemedItemIds, true),
                    'updated_at' => $item->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function points(User $user): array
    {
        $profile = $this->profile($user);
        $currentBalance = (int) $profile->points_balance;

        $nextRewardThreshold = RewardShopItem::query()
            ->where('is_active', true)
            ->where('points_cost', '>', $currentBalance)
            ->min('points_cost');

        return [
            'currentBalance' => $currentBalance,
            'lifetimeEarned' => $currentBalance,
            'tier' => $this->tierForBalance($currentBalance),
            'nextRewardThreshold' => $nextRewardThreshold ?? max(500, ((int) ceil($currentBalance * 1.5)) + 500),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function latestTimestamps(User $user): array
    {
        $profile = $this->profile($user);

        return [
            RewardShopItem::query()->where('is_active', true)->max('updated_at'),
            $profile->updated_at,
            RewardRedemption::query()
                ->where('reward_profile_id', $profile->id)
                ->max('updated_at'),
        ];
    }

    private function profile(User $user): RewardProfile
    {
        return RewardProfile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'xp' => 0,
                'level' => 1,
                'current_streak' => 0,
                'longest_streak' => 0,
                'points_balance' => 0,
            ],
        );
    }

    private function tierForBalance(int $balance): string
    {
        return match (true) {
            $balance >= 5000 => 'platinum',
            $balance >= 2500 => 'gold',
            $balance >= 1000 => 'silver',
            default => 'bronze',
        };
    }
}
