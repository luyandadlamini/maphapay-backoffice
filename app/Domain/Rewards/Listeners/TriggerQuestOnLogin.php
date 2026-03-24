<?php

declare(strict_types=1);

namespace App\Domain\Rewards\Listeners;

use App\Domain\Rewards\Services\QuestTriggerService;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Queue\ShouldQueue;

class TriggerQuestOnLogin implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(
        private readonly QuestTriggerService $triggers,
    ) {
    }

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->triggers->trigger($event->user, 'daily-login');
    }
}
