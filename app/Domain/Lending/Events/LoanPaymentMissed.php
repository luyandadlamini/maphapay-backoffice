<?php

declare(strict_types=1);

namespace App\Domain\Lending\Events;

use DateTimeImmutable;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LoanPaymentMissed extends ShouldBeStored
{
    public function __construct(
        public string $loanId,
        public int $paymentNumber,
        public DateTimeImmutable $missedAt
    ) {
    }
}
