<?php

declare(strict_types=1);

namespace App\Domain\VirtualsAgent\DataObjects;

final class AgdpMetrics
{
    public function __construct(
        public readonly int $totalPaymentsCents,
        public readonly int $totalTransactions,
        public readonly int $activeAgents,
        public readonly int $totalAgents,
        public readonly string $period,
        public readonly string $calculatedAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'totalPaymentsCents' => $this->totalPaymentsCents,
            'totalTransactions'  => $this->totalTransactions,
            'activeAgents'       => $this->activeAgents,
            'totalAgents'        => $this->totalAgents,
            'period'             => $this->period,
            'calculatedAt'       => $this->calculatedAt,
        ];
    }
}
