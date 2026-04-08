<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\Commerce;

use App\Domain\Commerce\Models\Merchant;
use App\Domain\Commerce\Services\MerchantOnboardingService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

final class SubmitMerchantApplicationMutation
{
    public function __construct(
        private readonly MerchantOnboardingService $merchantOnboardingService,
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

        $result = $this->merchantOnboardingService->submitApplication(
            businessName: $args['display_name'],
            businessType: 'merchant',
            country: 'US',
            contactEmail: $user->email ?? '',
            businessDetails: [
                'icon_url'          => $args['icon_url'] ?? null,
                'accepted_assets'   => $args['accepted_assets'] ?? null,
                'accepted_networks' => $args['accepted_networks'] ?? null,
                'terminal_id'       => $args['terminal_id'] ?? null,
            ],
        );

        /** @var Merchant */
        return Merchant::query()
            ->where('public_id', $result['merchant_id'])
            ->firstOrFail();
    }
}
