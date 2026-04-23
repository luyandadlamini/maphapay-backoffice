<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Domain\Account\Services\MinorFamilyFundingPolicy;
use App\Domain\Account\Services\MinorFamilyFundingPolicyResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorFamilyFundingPolicyTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function guardian_and_co_guardian_can_create_bounded_links_if_access_is_valid(): void
    {
        $minorAccount = $this->makeMinorAccount();
        $guardian = User::factory()->make();
        $coGuardian = User::factory()->make();

        $accessService = Mockery::mock(MinorAccountAccessService::class);
        $accessService->shouldReceive('hasGuardianAccess')
            ->once()
            ->with($guardian, $minorAccount)
            ->andReturnTrue();
        $accessService->shouldReceive('hasGuardianAccess')
            ->once()
            ->with($coGuardian, $minorAccount)
            ->andReturnTrue();

        $policy = new MinorFamilyFundingPolicy($accessService);

        $guardianResult = $policy->validateLinkCreation(
            actor: $guardian,
            minorAccount: $minorAccount,
            amountMode: 'capped',
            fixedAmount: null,
            targetAmount: '500.00',
            providerOptions: ['mtn_momo'],
            expiresAt: CarbonImmutable::now()->addDay(),
        );

        $coGuardianResult = $policy->validateLinkCreation(
            actor: $coGuardian,
            minorAccount: $minorAccount,
            amountMode: 'capped',
            fixedAmount: null,
            targetAmount: '500.00',
            providerOptions: ['mtn_momo'],
            expiresAt: CarbonImmutable::now()->addDay(),
        );

        $this->assertTrue($guardianResult->allowed);
        $this->assertNull($guardianResult->reason);
        $this->assertInstanceOf(MinorFamilyFundingPolicyResult::class, $guardianResult);
        $this->assertTrue($coGuardianResult->allowed);
        $this->assertNull($coGuardianResult->reason);
    }

    #[Test]
    public function expired_links_are_rejected(): void
    {
        $policy = new MinorFamilyFundingPolicy(Mockery::mock(MinorAccountAccessService::class));

        $link = new MinorFamilyFundingLink([
            'status' => 'active',
            'amount_mode' => 'fixed',
            'fixed_amount' => '100.00',
            'provider_options' => ['mtn_momo'],
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);

        $result = $policy->validateFundingAttempt(
            link: $link,
            amount: '100.00',
            provider: 'mtn_momo',
        );

        $this->assertFalse($result->allowed);
        $this->assertSame('Funding link has expired.', $result->reason);
    }

    #[Test]
    public function capped_links_reject_amounts_above_the_remaining_amount(): void
    {
        $policy = new MinorFamilyFundingPolicy(Mockery::mock(MinorAccountAccessService::class));

        $link = new MinorFamilyFundingLink([
            'status' => 'active',
            'amount_mode' => 'capped',
            'target_amount' => '200.00',
            'collected_amount' => '150.00',
            'provider_options' => ['mtn_momo'],
            'expires_at' => CarbonImmutable::now()->addDay(),
        ]);

        $result = $policy->validateFundingAttempt(
            link: $link,
            amount: '60.00',
            provider: 'mtn_momo',
        );

        $this->assertFalse($result->allowed);
        $this->assertSame('Funding amount exceeds the remaining link capacity.', $result->reason);
    }

    #[Test]
    public function unsupported_providers_are_rejected(): void
    {
        $minorAccount = $this->makeMinorAccount();
        $actor = User::factory()->make();

        $accessService = Mockery::mock(MinorAccountAccessService::class);
        $accessService->shouldReceive('hasGuardianAccess')
            ->once()
            ->with($actor, $minorAccount)
            ->andReturnTrue();

        $policy = new MinorFamilyFundingPolicy($accessService);

        $result = $policy->validateLinkCreation(
            actor: $actor,
            minorAccount: $minorAccount,
            amountMode: 'fixed',
            fixedAmount: '100.00',
            targetAmount: null,
            providerOptions: ['airtel_money'],
            expiresAt: CarbonImmutable::now()->addDay(),
        );

        $this->assertFalse($result->allowed);
        $this->assertSame('Provider [airtel_money] is not supported in Phase 9A.', $result->reason);
    }

    #[Test]
    public function source_accounts_must_belong_to_the_acting_user_for_outbound_support_transfer(): void
    {
        $minorAccount = $this->makeMinorAccount();
        $actor = User::factory()->make();
        $sourceAccount = Account::make([
            'uuid' => 'other-guardian-account-uuid',
            'user_uuid' => 'different-user-uuid',
            'type' => 'personal',
            'name' => 'Different Guardian Personal',
        ]);

        $accessService = Mockery::mock(MinorAccountAccessService::class);
        $accessService->shouldReceive('hasGuardianAccess')
            ->once()
            ->with($actor, $minorAccount)
            ->andReturnTrue();

        $policy = new MinorFamilyFundingPolicy($accessService);

        $result = $policy->validateOutboundSupportTransfer(
            actor: $actor,
            minorAccount: $minorAccount,
            sourceAccount: $sourceAccount,
            provider: 'mtn_momo',
            amount: '50.00',
        );

        $this->assertFalse($result->allowed);
        $this->assertSame('Source account must belong to the acting user.', $result->reason);
    }

    #[Test]
    public function minor_owned_source_account_is_rejected_for_outbound_support_transfer_in_phase_9a(): void
    {
        $minorAccount = $this->makeMinorAccount();
        $actor = User::factory()->make();
        $sourceAccount = Account::make([
            'uuid' => 'source-account-uuid',
            'user_uuid' => $minorAccount->user_uuid,
            'type' => 'personal',
            'name' => 'Minor Owned Personal',
        ]);

        $accessService = Mockery::mock(MinorAccountAccessService::class);
        $accessService->shouldReceive('hasGuardianAccess')
            ->once()
            ->with($actor, $minorAccount)
            ->andReturnTrue();

        $policy = new MinorFamilyFundingPolicy($accessService);

        $result = $policy->validateOutboundSupportTransfer(
            actor: $actor,
            minorAccount: $minorAccount,
            sourceAccount: $sourceAccount,
            provider: 'mtn_momo',
            amount: '50.00',
        );

        $this->assertFalse($result->allowed);
        $this->assertSame('Phase 9A support transfers must use a guardian-owned source account.', $result->reason);
    }

    #[Test]
    public function malformed_numeric_inputs_are_denied_cleanly(): void
    {
        $minorAccount = $this->makeMinorAccount();
        $actor = User::factory()->make([
            'uuid' => 'guardian-user-uuid',
        ]);

        $accessService = Mockery::mock(MinorAccountAccessService::class);
        $accessService->shouldReceive('hasGuardianAccess')
            ->twice()
            ->with($actor, $minorAccount)
            ->andReturnTrue();

        $policy = new MinorFamilyFundingPolicy($accessService);

        $linkCreationResult = $policy->validateLinkCreation(
            actor: $actor,
            minorAccount: $minorAccount,
            amountMode: 'fixed',
            fixedAmount: 'not-a-number',
            targetAmount: null,
            providerOptions: ['mtn_momo'],
            expiresAt: CarbonImmutable::now()->addDay(),
        );

        $fundingAttemptLink = new MinorFamilyFundingLink([
            'status' => 'active',
            'amount_mode' => 'capped',
            'target_amount' => '200.00',
            'collected_amount' => '10.00',
            'provider_options' => ['mtn_momo'],
            'expires_at' => CarbonImmutable::now()->addDay(),
        ]);

        $fundingAttemptResult = $policy->validateFundingAttempt(
            link: $fundingAttemptLink,
            amount: 'bad-input',
            provider: 'mtn_momo',
        );

        $transferResult = $policy->validateOutboundSupportTransfer(
            actor: $actor,
            minorAccount: $minorAccount,
            sourceAccount: Account::make([
                'uuid' => 'guardian-source-account-uuid',
                'user_uuid' => $actor->uuid,
                'type' => 'personal',
                'name' => 'Guardian Personal',
            ]),
            provider: 'mtn_momo',
            amount: 'still-not-a-number',
        );

        $this->assertFalse($linkCreationResult->allowed);
        $this->assertSame('Fixed funding links require a positive fixed amount.', $linkCreationResult->reason);
        $this->assertFalse($fundingAttemptResult->allowed);
        $this->assertSame('Funding amount must be a valid positive number.', $fundingAttemptResult->reason);
        $this->assertFalse($transferResult->allowed);
        $this->assertSame('Transfer amount must be a valid positive number.', $transferResult->reason);
    }

    #[Test]
    public function link_status_helpers_reflect_phase_9a_lifecycle_states(): void
    {
        $draftLink = new MinorFamilyFundingLink([
            'status' => MinorFamilyFundingLink::STATUS_DRAFT,
        ]);
        $pausedLink = new MinorFamilyFundingLink([
            'status' => MinorFamilyFundingLink::STATUS_PAUSED,
        ]);
        $completedLink = new MinorFamilyFundingLink([
            'status' => MinorFamilyFundingLink::STATUS_COMPLETED,
        ]);
        $activeLink = new MinorFamilyFundingLink([
            'status' => MinorFamilyFundingLink::STATUS_ACTIVE,
            'expires_at' => CarbonImmutable::now()->addMinute(),
        ]);

        $this->assertTrue($draftLink->isDraft());
        $this->assertTrue($pausedLink->isPaused());
        $this->assertTrue($completedLink->isCompleted());
        $this->assertTrue($completedLink->isTerminal());
        $this->assertTrue($activeLink->isActive());
        $this->assertTrue($activeLink->canAcceptFunding());
    }

    #[Test]
    public function funding_attempt_and_support_transfer_helpers_reflect_provider_lifecycle_states(): void
    {
        $pendingAttempt = new MinorFamilyFundingAttempt([
            'status' => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
        ]);
        $successfulUncreditedAttempt = new MinorFamilyFundingAttempt([
            'status' => MinorFamilyFundingAttempt::STATUS_SUCCESSFUL_UNCREDITED,
        ]);
        $creditedAttempt = new MinorFamilyFundingAttempt([
            'status' => MinorFamilyFundingAttempt::STATUS_CREDITED,
        ]);

        $pendingTransfer = new MinorFamilySupportTransfer([
            'status' => MinorFamilySupportTransfer::STATUS_PENDING_PROVIDER,
        ]);
        $failedRefundedTransfer = new MinorFamilySupportTransfer([
            'status' => MinorFamilySupportTransfer::STATUS_FAILED_REFUNDED,
            'wallet_refunded_at' => CarbonImmutable::now(),
        ]);
        $successfulTransfer = new MinorFamilySupportTransfer([
            'status' => MinorFamilySupportTransfer::STATUS_SUCCESSFUL,
        ]);

        $this->assertTrue($pendingAttempt->isPendingProvider());
        $this->assertTrue($successfulUncreditedAttempt->requiresCreditReconciliation());
        $this->assertTrue($creditedAttempt->isCredited());

        $this->assertTrue($pendingTransfer->isPendingProvider());
        $this->assertTrue($failedRefundedTransfer->isFailed());
        $this->assertTrue($failedRefundedTransfer->isRefunded());
        $this->assertTrue($failedRefundedTransfer->isTerminal());
        $this->assertTrue($successfulTransfer->isSuccessful());
        $this->assertTrue($successfulTransfer->isTerminal());
    }

    private function makeMinorAccount(): Account
    {
        return Account::make([
            'uuid' => 'minor-account-uuid',
            'user_uuid' => 'minor-user-uuid',
            'type' => 'minor',
            'name' => 'Minor Wallet',
        ]);
    }
}
