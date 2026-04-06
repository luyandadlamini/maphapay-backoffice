<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class PoolGovernanceVoteInitiated extends ShouldBeStored
{
    public function __construct(
        public readonly string $poolId,
        public readonly string $proposalId,
        public readonly string $proposalType, // 'fee_change', 'parameter_update', 'emergency_action'
        public readonly array $proposedChanges,
        public readonly string $initiatedBy,
        public readonly string $votingDeadline,
        public readonly array $metadata = []
    ) {
    }
}
