<?php

declare(strict_types=1);

namespace App\Domain\Lending\Services;

use App\Domain\Lending\Aggregates\LoanApplication;

interface RiskAssessmentService
{
    /**
     * Assess loan risk.
     *
     * @return array{rating: string, defaultProbability: float, riskFactors: array}
     */
    public function assessLoan(
        LoanApplication $application,
        array $creditScore,
        array $additionalFactors = []
    ): array;

    /**
     * Calculate risk-adjusted interest rate.
     */
    public function calculateRiskAdjustedRate(string $riskRating, float $baseRate): float;

    /**
     * Get risk factors for a borrower.
     */
    public function getBorrowerRiskFactors(string $borrowerId): array;
}
