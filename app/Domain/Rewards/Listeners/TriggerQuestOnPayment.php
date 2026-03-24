<?php

declare(strict_types=1);

namespace App\Domain\Rewards\Listeners;

use App\Domain\Account\Events\MoneyTransferred;
use App\Domain\Account\Models\Account;
use App\Domain\Rewards\Services\QuestTriggerService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

class TriggerQuestOnPayment implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(
        private readonly QuestTriggerService $triggers,
    ) {
    }

    public function handle(MoneyTransferred $event): void
    {
        // Look up the sender's user from the account UUID
        $account = Account::where('uuid', (string) $event->from)->first();

        if ($account === null) {
            return;
        }

        $user = User::where('uuid', $account->user_uuid)->first();

        if ($user === null) {
            return;
        }

        $this->triggers->triggerMultiple($user, ['first-payment', 'daily-transaction']);
    }
}
