<?php

declare(strict_types=1);

namespace App\Domain\Account\Observers;

use App\Domain\Account\Models\MinorAccountLifecycleException;
use App\Domain\Account\Models\MinorAccountLifecycleTransition;
use RuntimeException;

class MinorAccountLifecycleTransitionObserver
{
    public function deleting(MinorAccountLifecycleTransition $transition): void
    {
        $hasReferencingExceptions = MinorAccountLifecycleException::query()
            ->where('transition_id', $transition->id)
            ->exists();

        if ($hasReferencingExceptions) {
            throw new RuntimeException(
                'Cannot delete a lifecycle transition that has referencing exceptions. ' .
                'Resolve or acknowledge all exceptions before deleting the transition.'
            );
        }
    }
}
