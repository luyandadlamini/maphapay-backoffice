<?php

declare(strict_types=1);

namespace App\Domain\Lending\Events;

use DateTimeImmutable;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LoanSettledEarly extends ShouldBeStored
{
    public function __construct(
        public string $loanId,
        public string $settlementAmount,
        public string $outstandingBalance,
        public string $settledBy,
        public DateTimeImmutable $settledAt
    ) {
    }
}
