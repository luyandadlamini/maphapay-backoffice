<?php

declare(strict_types=1);

namespace App\Domain\Lending\Events;

use DateTimeImmutable;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LoanApplicationApproved extends ShouldBeStored
{
    public function __construct(
        public string $applicationId,
        public string $approvedAmount,
        public float $interestRate,
        public array $terms,
        public string $approvedBy,
        public DateTimeImmutable $approvedAt
    ) {
    }
}
