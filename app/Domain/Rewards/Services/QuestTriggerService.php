<?php

declare(strict_types=1);

namespace App\Domain\Rewards\Services;

use App\Domain\Rewards\Models\RewardQuest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Maps domain events to quest slugs and triggers auto-completion.
 *
 * Quest slugs from RewardsSeeder:
 * - daily-login (repeatable)
 * - first-payment (one-time)
 * - daily-transaction (repeatable)
 * - first-shield (one-time)
 * - complete-profile (one-time)
 * - first-card (one-time)
 */
class QuestTriggerService
{
    public function __construct(
        private readonly RewardsService $rewards,
    ) {
    }

    /**
     * Attempt to complete a quest by its slug for a user.
     *
     * Silently ignores: quest not found, already completed (non-repeatable),
     * inactive quests. Only logs on actual completion or unexpected errors.
     */
    public function trigger(User $user, string $questSlug): void
    {
        $quest = RewardQuest::where('slug', $questSlug)
            ->where('is_active', true)
            ->first();

        if ($quest === null) {
            return;
        }

        try {
            $this->rewards->completeQuest($user, $quest->id);

            Log::info('Rewards: Quest auto-completed', [
                'user_id' => $user->id,
                'quest'   => $questSlug,
            ]);
        } catch (RuntimeException $e) {
            // Already completed or other expected error — silently ignore
            if (! str_contains($e->getMessage(), 'already completed')) {
                Log::debug('Rewards: Quest trigger skipped', [
                    'user_id' => $user->id,
                    'quest'   => $questSlug,
                    'reason'  => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Trigger multiple quests at once.
     *
     * @param array<string> $slugs
     */
    public function triggerMultiple(User $user, array $slugs): void
    {
        foreach ($slugs as $slug) {
            $this->trigger($user, $slug);
        }
    }
}
