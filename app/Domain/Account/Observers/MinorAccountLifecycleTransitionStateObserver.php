<?php

declare(strict_types=1);

namespace App\Domain\Account\Observers;

use App\Domain\Account\Exceptions\InvalidLifecycleStateTransitionException;
use App\Domain\Account\Models\MinorAccountLifecycleTransition;

class MinorAccountLifecycleTransitionStateObserver
{
    private const array ALLOWED_TRANSITIONS = [
        MinorAccountLifecycleTransition::STATE_PENDING => [
            MinorAccountLifecycleTransition::STATE_COMPLETED,
            MinorAccountLifecycleTransition::STATE_BLOCKED,
        ],
    ];

    public function saving(MinorAccountLifecycleTransition $transition): void
    {
        if (! $transition->isDirty('state')) {
            return;
        }

        $from = $transition->getOriginal('state');
        $to = $transition->state;

        if ($from === null) {
            if ($to !== MinorAccountLifecycleTransition::STATE_PENDING) {
                throw new InvalidLifecycleStateTransitionException(
                    "New lifecycle transitions must start in PENDING state; got '{$to}'."
                );
            }

            return;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new InvalidLifecycleStateTransitionException(
                "Invalid transition from '{$from}' to '{$to}'. Allowed targets: " . implode(', ', $allowed) . '.'
            );
        }
    }
}
