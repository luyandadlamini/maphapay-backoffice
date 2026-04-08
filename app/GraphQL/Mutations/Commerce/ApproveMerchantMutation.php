<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\Commerce;

use App\Domain\Commerce\Models\Merchant;
use App\Domain\Commerce\Services\MerchantOnboardingService;
use App\Domain\Corporate\Enums\CorporateCapability;
use App\Domain\Corporate\Services\CorporateCapabilityGate;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

final class ApproveMerchantMutation
{
    public function __construct(
        private readonly MerchantOnboardingService $merchantOnboardingService,
        private readonly CorporateCapabilityGate $corporateCapabilityGate,
    ) {
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $rootValue, array $args): Merchant
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user) {
            throw new AuthenticationException('Unauthenticated.');
        }

        $merchant = Merchant::findOrFail($args['id']);
        /** @var \App\Models\Team|null $team */
        $team = $user->currentTeam;

        if ($team?->is_business_organization) {
            if ($merchant->corporateProfile?->team_id !== $team->id) {
                throw new AuthorizationException('The merchant does not belong to the authenticated business context.');
            }

            $this->corporateCapabilityGate->authorize($user, $team, CorporateCapability::COMPLIANCE_REVIEW);
        }

        $this->merchantOnboardingService->approve(
            $args['id'],
            (string) $user->id,
        );

        /** @var Merchant $refreshedMerchant */
        $refreshedMerchant = $merchant->fresh();

        return $refreshedMerchant;
    }
}
