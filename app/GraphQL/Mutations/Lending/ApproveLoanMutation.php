<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\Lending;

use App\Domain\Lending\Models\LoanApplication;
use App\Domain\Lending\Services\LoanApplicationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ApproveLoanMutation
{
    public function __construct(
        private readonly LoanApplicationService $loanApplicationService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $rootValue, array $args): LoanApplication
    {
        $user = Auth::user();

        if (! $user) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if (! Gate::allows('superadmin')) {
            throw new AuthorizationException('Only superadmins can approve loans.');
        }

        /** @var LoanApplication|null $application */
        $application = LoanApplication::query()->find($args['id']);

        if (! $application) {
            throw new ModelNotFoundException('Loan application not found.');
        }

        $this->loanApplicationService->processApplication(
            applicationId: (string) $application->id,
            borrowerId: (string) $application->borrower_id,
            requestedAmount: (string) ($args['approved_amount'] ?? $application->requested_amount),
            termMonths: (int) $application->term_months,
            purpose: (string) $application->purpose,
            borrowerInfo: [],
        );

        return $application->fresh() ?? $application;
    }
}
