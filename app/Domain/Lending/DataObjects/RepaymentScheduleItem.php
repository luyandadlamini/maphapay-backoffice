<?php

declare(strict_types=1);

namespace App\Domain\Lending\DataObjects;

use Illuminate\Support\Carbon;

class RepaymentScheduleItem
{
    public function __construct(
        public readonly int $installmentNumber,
        public readonly Carbon $dueDate,
        public readonly string $principalAmount,
        public readonly string $interestAmount,
        public readonly string $totalAmount,
        public readonly string $remainingBalance,
        public readonly string $status = 'pending'
    ) {
    }

    public function toArray(): array
    {
        return [
            'installment_number' => $this->installmentNumber,
            'due_date'           => $this->dueDate->toDateString(),
            'principal_amount'   => $this->principalAmount,
            'interest_amount'    => $this->interestAmount,
            'total_amount'       => $this->totalAmount,
            'remaining_balance'  => $this->remainingBalance,
            'status'             => $this->status,
        ];
    }

    public function isPastDue(): bool
    {
        return $this->dueDate->isPast() && $this->status === 'pending';
    }
}
