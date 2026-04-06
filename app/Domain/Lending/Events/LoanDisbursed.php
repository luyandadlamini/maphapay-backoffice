<?php

declare(strict_types=1);

namespace App\Domain\Lending\Events;

use DateTimeImmutable;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LoanDisbursed extends ShouldBeStored
{
    public function __construct(
        public string $loanId,
        public string $amount,
        public DateTimeImmutable $disbursedAt
    ) {
    }
}
