<?php

declare(strict_types=1);

namespace App\Domain\Rewards\Listeners;

use App\Domain\CardIssuance\Events\CardProvisioned;
use App\Domain\Rewards\Services\QuestTriggerService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

class TriggerQuestOnCardCreated implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(
        private readonly QuestTriggerService $triggers,
    ) {
    }

    public function handle(CardProvisioned $event): void
    {
        $user = User::find($event->userId);

        if ($user === null) {
            return;
        }

        $this->triggers->trigger($user, 'first-card');
    }
}
