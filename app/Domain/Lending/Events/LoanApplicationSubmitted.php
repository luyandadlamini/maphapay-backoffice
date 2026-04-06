<?php

declare(strict_types=1);

namespace App\Domain\Lending\Events;

use DateTimeImmutable;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LoanApplicationSubmitted extends ShouldBeStored
{
    public function __construct(
        public string $applicationId,
        public string $borrowerId,
        public string $requestedAmount,
        public int $termMonths,
        public string $purpose,
        public array $borrowerInfo,
        public DateTimeImmutable $submittedAt
    ) {
    }
}
