<?php

declare(strict_types=1);

namespace App\Domain\Cgo\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class RefundRequested extends ShouldBeStored
{
    public static string $queue = 'events';

    public function __construct(
        public readonly string $refundId,
        public readonly string $investmentId,
        public readonly string $userId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $reason,
        public readonly ?string $reasonDetails,
        public readonly string $initiatedBy,
        public readonly array $metadata = []
    ) {
    }
}
