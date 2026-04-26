<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;

class MinorFamilyFundingPolicy
{
    public const SUPPORTED_PROVIDER = 'mtn_momo';

    public function __construct(
        private readonly ?MinorAccountAccessService $minorAccountAccessService = null,
    ) {
    }

    /**
     * @param  array<int, string>|null  $providerOptions
     */
    public function validateLinkCreation(
        User $actor,
        Account $minorAccount,
        string $amountMode,
        ?string $fixedAmount,
        ?string $targetAmount,
        ?array $providerOptions,
        ?CarbonInterface $expiresAt,
    ): MinorFamilyFundingPolicyResult {
        if ($minorAccount->type !== 'minor') {
            return $this->deny('Funding links can only be created for minor accounts.');
        }

        if (! $this->accessService()->hasGuardianAccess($actor, $minorAccount)) {
            return $this->deny('Guardian or co-guardian access is required.');
        }

        $providerResult = $this->validateProviders($providerOptions);
        if (! $providerResult->allowed) {
            return $providerResult;
        }

        if ($expiresAt !== null && $expiresAt->isPast()) {
            return $this->deny('Funding links must expire in the future.');
        }

        return match ($amountMode) {
            MinorFamilyFundingLink::AMOUNT_MODE_FIXED  => $this->validateFixedLink($fixedAmount),
            MinorFamilyFundingLink::AMOUNT_MODE_CAPPED => $this->validateCappedLink($targetAmount),
            default                                    => $this->deny('Unsupported funding link amount mode.'),
        };
    }

    public function validateFundingAttempt(
        MinorFamilyFundingLink $link,
        string $amount,
        string $provider,
    ): MinorFamilyFundingPolicyResult {
        if (! $link->isActive()) {
            return $this->deny($link->isExpired()
                ? 'Funding link has expired.'
                : 'Funding link is not active.');
        }

        if (! $this->isSupportedProvider($provider)) {
            return $this->deny("Provider [{$provider}] is not supported in Phase 9A.");
        }

        if (! $link->supportsProvider($provider)) {
            return $this->deny("Provider [{$provider}] is not enabled for this funding link.");
        }

        if (! $this->isPositiveAmount($amount)) {
            return $this->deny('Funding amount must be a valid positive number.');
        }

        if ($link->isFixedAmount()) {
            $comparison = $this->compareAmounts($amount, (string) $link->fixed_amount);

            if ($comparison === null) {
                return $this->deny('Funding link amount configuration is invalid.');
            }

            if ($comparison !== 0) {
                return $this->deny('Funding amount must match the fixed link amount.');
            }
        }

        if ($link->isCapped()) {
            $remaining = $link->remainingAmount() ?? '0.00';

            $comparison = $this->compareAmounts($amount, $remaining);

            if ($comparison === null) {
                return $this->deny('Funding link amount configuration is invalid.');
            }

            if ($comparison === 1) {
                return $this->deny('Funding amount exceeds the remaining link capacity.');
            }
        }

        return $this->allow();
    }

    public function validateOutboundSupportTransfer(
        User $actor,
        Account $minorAccount,
        Account $sourceAccount,
        string $provider,
        string $amount,
    ): MinorFamilyFundingPolicyResult {
        if ($minorAccount->type !== 'minor') {
            return $this->deny('Support transfers can only target minor accounts.');
        }

        if (! $this->accessService()->hasGuardianAccess($actor, $minorAccount)) {
            return $this->deny('Guardian or co-guardian access is required.');
        }

        if (! $this->isSupportedProvider($provider)) {
            return $this->deny("Provider [{$provider}] is not supported in Phase 9A.");
        }

        if (! $this->isPositiveAmount($amount)) {
            return $this->deny('Transfer amount must be a valid positive number.');
        }

        if ($sourceAccount->user_uuid === $minorAccount->user_uuid || $sourceAccount->uuid === $minorAccount->uuid) {
            return $this->deny('Phase 9A support transfers must use a guardian-owned source account.');
        }

        if ($sourceAccount->user_uuid !== $actor->uuid) {
            return $this->deny('Source account must belong to the acting user.');
        }

        return $this->allow();
    }

    /**
     * @param  array<int, string>|null  $providerOptions
     */
    private function validateProviders(?array $providerOptions): MinorFamilyFundingPolicyResult
    {
        $providers = $providerOptions ?? [self::SUPPORTED_PROVIDER];

        foreach ($providers as $provider) {
            if (! $this->isSupportedProvider($provider)) {
                return $this->deny("Provider [{$provider}] is not supported in Phase 9A.");
            }
        }

        return $this->allow();
    }

    private function validateFixedLink(?string $fixedAmount): MinorFamilyFundingPolicyResult
    {
        if (! is_string($fixedAmount) || ! $this->isPositiveAmount($fixedAmount)) {
            return $this->deny('Fixed funding links require a positive fixed amount.');
        }

        return $this->allow();
    }

    private function validateCappedLink(?string $targetAmount): MinorFamilyFundingPolicyResult
    {
        if (! is_string($targetAmount) || ! $this->isPositiveAmount($targetAmount)) {
            return $this->deny('Capped funding links require a positive target amount.');
        }

        return $this->allow();
    }

    private function isSupportedProvider(string $provider): bool
    {
        return trim($provider) === self::SUPPORTED_PROVIDER;
    }

    private function isPositiveAmount(string $amount): bool
    {
        return $this->compareAmounts($amount, '0') === 1;
    }

    private function compareAmounts(?string $left, ?string $right): ?int
    {
        $left = $this->normaliseNumericAmount($left);
        $right = $this->normaliseNumericAmount($right);

        if ($left === null || $right === null) {
            return null;
        }

        return BigDecimal::of($left)->compareTo(BigDecimal::of($right));
    }

    private function normaliseNumericAmount(?string $amount): ?string
    {
        if (! is_string($amount)) {
            return null;
        }

        $amount = trim($amount);

        if ($amount === '' || ! preg_match('/^-?\d+(?:\.\d+)?$/', $amount)) {
            return null;
        }

        return $amount;
    }

    private function accessService(): MinorAccountAccessService
    {
        return $this->minorAccountAccessService ?? app(MinorAccountAccessService::class);
    }

    private function allow(): MinorFamilyFundingPolicyResult
    {
        return MinorFamilyFundingPolicyResult::allow();
    }

    private function deny(string $reason): MinorFamilyFundingPolicyResult
    {
        return MinorFamilyFundingPolicyResult::deny($reason);
    }
}
