<?php

declare(strict_types=1);

namespace App\Domain\Lending\Services;

interface CreditScoringService
{
    /**
     * Get credit score for a borrower.
     *
     * @return array{score: int, bureau: string, report: array}
     */
    public function getScore(string $borrowerId): array;

    /**
     * Check if borrower meets minimum credit requirements.
     */
    public function meetsMinimumRequirements(string $borrowerId, int $minimumScore = 600): bool;

    /**
     * Get credit history.
     */
    public function getCreditHistory(string $borrowerId): array;
}
