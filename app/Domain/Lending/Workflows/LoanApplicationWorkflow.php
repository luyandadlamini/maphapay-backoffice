<?php

declare(strict_types=1);

namespace App\Domain\Lending\Workflows;

use App\Domain\Lending\Workflows\Activities\LoanApplicationActivities;
use Workflow\ActivityStub;
use Workflow\Workflow;
use Workflow\WorkflowStub;

class LoanApplicationWorkflow extends Workflow
{
    private ActivityStub $activities;

    public function __construct()
    {
        $this->activities = WorkflowStub::newActivityStub(
            LoanApplicationActivities::class,
            [
                'startToCloseTimeout' => 300, // 5 minutes
                'retryAttempts'       => 3,
            ]
        );
    }

    public function execute(
        string $applicationId,
        string $borrowerId,
        string $requestedAmount,
        int $termMonths,
        string $purpose,
        array $borrowerInfo
    ) {
        // Step 1: Create loan application
        yield $this->activities->createApplication(
            $applicationId,
            $borrowerId,
            $requestedAmount,
            $termMonths,
            $purpose,
            $borrowerInfo
        );

        // Step 2: Perform KYC check
        $kycResult = yield $this->activities->performKYCCheck($borrowerId);

        if (! $kycResult['passed']) {
            yield $this->activities->rejectApplication(
                $applicationId,
                ['KYC check failed: ' . $kycResult['reason']],
                'system'
            );

            return [
                'status'        => 'rejected',
                'reason'        => 'KYC check failed',
                'applicationId' => $applicationId,
            ];
        }

        // Step 3: Run credit check
        $creditScore = yield $this->activities->runCreditCheck($borrowerId);

        yield $this->activities->recordCreditScore(
            $applicationId,
            $creditScore['score'],
            $creditScore['bureau'],
            $creditScore['report']
        );

        // Step 4: Assess risk
        $riskAssessment = yield $this->activities->assessRisk(
            $applicationId,
            $borrowerId,
            $requestedAmount,
            $termMonths,
            $creditScore
        );

        yield $this->activities->recordRiskAssessment(
            $applicationId,
            $riskAssessment['rating'],
            $riskAssessment['defaultProbability'],
            $riskAssessment['riskFactors']
        );

        // Step 5: Determine interest rate and approval
        $decision = yield $this->activities->makeDecision(
            $applicationId,
            $requestedAmount,
            $termMonths,
            $creditScore,
            $riskAssessment
        );

        if ($decision['approved']) {
            // Step 6: Approve application
            yield $this->activities->approveApplication(
                $applicationId,
                $decision['approvedAmount'],
                $decision['interestRate'],
                $decision['terms']
            );

            // Step 7: Create loan
            $loanId = yield $this->activities->createLoan(
                $applicationId,
                $borrowerId,
                $decision['approvedAmount'],
                $decision['interestRate'],
                $termMonths,
                $decision['terms']
            );

            // Step 8: Notify borrower
            yield $this->activities->notifyBorrower(
                $borrowerId,
                'approved',
                [
                    'applicationId'  => $applicationId,
                    'loanId'         => $loanId,
                    'approvedAmount' => $decision['approvedAmount'],
                    'interestRate'   => $decision['interestRate'],
                    'termMonths'     => $termMonths,
                ]
            );

            return [
                'status'         => 'approved',
                'applicationId'  => $applicationId,
                'loanId'         => $loanId,
                'approvedAmount' => $decision['approvedAmount'],
                'interestRate'   => $decision['interestRate'],
            ];
        } else {
            // Reject application
            yield $this->activities->rejectApplication(
                $applicationId,
                $decision['rejectionReasons'],
                'system'
            );

            yield $this->activities->notifyBorrower(
                $borrowerId,
                'rejected',
                [
                    'applicationId' => $applicationId,
                    'reasons'       => $decision['rejectionReasons'],
                ]
            );

            return [
                'status'        => 'rejected',
                'applicationId' => $applicationId,
                'reasons'       => $decision['rejectionReasons'],
            ];
        }
    }
}
