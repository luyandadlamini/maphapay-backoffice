<?php

declare(strict_types=1);

namespace App\Domain\Analytics\DTO;

/**
 * Read-only row for targets that fail basic sanity (non-positive amount).
 */
final readonly class RevenueTargetAnomalyRowDto
{
    public function __construct(
        public string $id,
        public string $periodMonth,
        public string $streamCode,
        public string $amount,
        public string $currency,
    ) {
    }
}
