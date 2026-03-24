<?php

declare(strict_types=1);

namespace App\Domain\Rewards\Listeners;

use App\Domain\Privacy\Events\ProofOfInnocenceGenerated;
use App\Domain\Rewards\Services\QuestTriggerService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

class TriggerQuestOnShield implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(
        private readonly QuestTriggerService $triggers,
    ) {
    }

    public function handle(ProofOfInnocenceGenerated $event): void
    {
        $user = User::find($event->userId);

        if ($user === null) {
            return;
        }

        $this->triggers->trigger($user, 'first-shield');
    }
}
