<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Enums\MerchantStatus;
use App\Domain\Commerce\Events\MerchantOnboarded;
use App\Domain\Commerce\Models\Merchant;
use App\Domain\Corporate\Models\BusinessOnboardingCaseStatusHistory;
use App\Domain\Corporate\Models\BusinessOnboardingCase;
use App\Domain\Corporate\Models\CorporateProfile;
use App\Models\Team;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Service for merchant onboarding and lifecycle management.
 *
 * The onboarding authority is persisted in `business_onboarding_cases`; the
 * `merchants` table is the product-facing projection of that lifecycle.
 */
class MerchantOnboardingService
{
    /**
     * Submit a new merchant application.
     *
     * @param array<string, mixed> $businessDetails
     *
     * @return array{merchant_id: string, status: string, onboarding_case_id: string}
     */
    public function submitApplication(
        string $businessName,
        string $businessType,
        string $country,
        string $contactEmail,
        array $businessDetails = [],
    ): array {
        /** @var User|null $user */
        $user = Auth::user();
        /** @var Team|null $team */
        $team = $user?->currentTeam;
        $corporateProfile = $this->resolveCorporateProfile($team);

        /** @var array{merchant_id: string, status: string, onboarding_case_id: string} $result */
        $result = DB::transaction(function () use (
            $businessName,
            $businessType,
            $country,
            $contactEmail,
            $businessDetails,
            $user,
            $team,
            $corporateProfile,
        ): array {
            $merchant = Merchant::create([
                'public_id' => 'merchant_' . Str::lower(Str::random(24)),
                'display_name' => $businessName,
                'icon_url' => $businessDetails['icon_url'] ?? null,
                'accepted_assets' => $businessDetails['accepted_assets'] ?? [],
                'accepted_networks' => $businessDetails['accepted_networks'] ?? [],
                'status' => MerchantStatus::PENDING,
                'terminal_id' => $businessDetails['terminal_id'] ?? null,
                'corporate_profile_id' => $corporateProfile?->id,
            ]);

            $case = BusinessOnboardingCase::create([
                'public_id' => 'onboard_' . Str::lower(Str::random(24)),
                'team_id' => $team?->id,
                'corporate_profile_id' => $corporateProfile?->id,
                'merchant_id' => $merchant->id,
                'relationship_type' => 'merchant',
                'status' => MerchantStatus::PENDING->value,
                'business_name' => $businessName,
                'business_type' => $businessType,
                'country' => $country,
                'contact_email' => $contactEmail,
                'requested_capabilities' => [],
                'business_details' => $businessDetails,
                'activation_requirements' => ['kyb_review', 'merchant_approval'],
                'submitted_by_user_id' => $user?->id,
            ]);

            $merchant->forceFill([
                'business_onboarding_case_id' => $case->id,
            ])->save();

            BusinessOnboardingCaseStatusHistory::query()->create([
                'business_onboarding_case_id' => $case->id,
                'from_status' => null,
                'to_status' => MerchantStatus::PENDING->value,
                'actor_user_id' => $user?->id,
                'reason' => 'Application submitted',
            ]);

            return [
                'merchant_id' => $merchant->public_id,
                'status' => $merchant->status->value,
                'onboarding_case_id' => $case->public_id,
            ];
        });

        return $result;
    }

    public function startReview(string $merchantId, string $reviewerId): void
    {
        $this->transitionMerchant(
            merchantId: $merchantId,
            newStatus: MerchantStatus::UNDER_REVIEW,
            actorUserId: (int) $reviewerId,
            reason: "Review started by {$reviewerId}",
            caseAttributes: ['reviewed_by_user_id' => (int) $reviewerId],
        );
    }

    /**
     * @param array<string, mixed> $approvalDetails
     */
    public function approve(
        string $merchantId,
        string $approverId,
        array $approvalDetails = [],
    ): void {
        $this->transitionMerchant(
            merchantId: $merchantId,
            newStatus: MerchantStatus::APPROVED,
            actorUserId: (int) $approverId,
            reason: "Approved by {$approverId}",
            caseAttributes: [
                'approved_by_user_id' => (int) $approverId,
                'approved_at' => now(),
                'last_decision_reason' => "Approved by {$approverId}",
            ],
            caseMetadata: $approvalDetails === [] ? [] : ['approval_details' => $approvalDetails],
        );
    }

    public function activate(string $merchantId): void
    {
        $merchant = $this->resolveMerchant($merchantId);

        $this->transitionMerchant(
            merchantId: $merchantId,
            newStatus: MerchantStatus::ACTIVE,
            actorUserId: null,
            reason: 'Merchant setup completed',
        );

        Event::dispatch(new MerchantOnboarded(
            merchantId: $merchant->public_id,
            merchantName: $merchant->display_name,
            status: MerchantStatus::ACTIVE,
            onboardedAt: new DateTimeImmutable(),
        ));
    }

    public function suspend(string $merchantId, string $reason): void
    {
        $actorUserId = Auth::id();

        $this->transitionMerchant(
            merchantId: $merchantId,
            newStatus: MerchantStatus::SUSPENDED,
            actorUserId: is_numeric($actorUserId) ? (int) $actorUserId : null,
            reason: $reason,
            caseAttributes: ['last_decision_reason' => $reason],
        );
    }

    public function reactivate(string $merchantId, string $reason): void
    {
        $actorUserId = Auth::id();

        $this->transitionMerchant(
            merchantId: $merchantId,
            newStatus: MerchantStatus::ACTIVE,
            actorUserId: is_numeric($actorUserId) ? (int) $actorUserId : null,
            reason: $reason,
        );
    }

    public function terminate(string $merchantId, string $reason): void
    {
        $actorUserId = Auth::id();

        $this->transitionMerchant(
            merchantId: $merchantId,
            newStatus: MerchantStatus::TERMINATED,
            actorUserId: is_numeric($actorUserId) ? (int) $actorUserId : null,
            reason: $reason,
            caseAttributes: ['last_decision_reason' => $reason],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getMerchant(string $merchantId): array
    {
        $merchant = $this->resolveMerchant($merchantId);
        $case = $this->resolveOnboardingCase($merchant);

        return [
            'merchant_id' => $merchant->public_id,
            'business_name' => $case->business_name,
            'business_type' => $case->business_type,
            'country' => $case->country,
            'contact_email' => $case->contact_email,
            'business_details' => $case->business_details ?? [],
            'status' => $merchant->status->value,
            'created_at' => $case->created_at?->toIso8601String(),
            'updated_at' => $case->updated_at?->toIso8601String(),
            'status_history' => $this->getStatusHistory($merchantId),
        ];
    }

    public function getMerchantStatus(string $merchantId): MerchantStatus
    {
        return $this->resolveMerchant($merchantId)->status;
    }

    public function canAcceptPayments(string $merchantId): bool
    {
        return $this->getMerchantStatus($merchantId)->canAcceptPayments();
    }

    /**
     * @return array<array{status: string, changed_at: string, reason: string}>
     */
    public function getStatusHistory(string $merchantId): array
    {
        $case = $this->resolveOnboardingCase($this->resolveMerchant($merchantId));

        $history = [];

        foreach ($case->statusHistory as $statusHistory) {
            $history[] = [
                'status' => $statusHistory->to_status,
                'changed_at' => $statusHistory->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'reason' => $statusHistory->reason ?? '',
            ];
        }

        return $history;
    }

    /**
     * @return array{risk_score: float, risk_factors: array<string>, recommendation: string}
     */
    public function assessRisk(string $merchantId): array
    {
        $case = $this->resolveOnboardingCase($this->resolveMerchant($merchantId));

        $riskFactors = [];
        $riskScore = 0.0;

        $highRiskTypes = ['gambling', 'crypto', 'adult', 'weapons'];
        if (in_array(strtolower((string) $case->business_type), $highRiskTypes, true)) {
            $riskFactors[] = 'High-risk business category';
            $riskScore += 0.3;
        }

        $highRiskCountries = ['AF', 'KP', 'IR', 'SY'];
        if (in_array(strtoupper((string) $case->country), $highRiskCountries, true)) {
            $riskFactors[] = 'High-risk jurisdiction';
            $riskScore += 0.4;
        }

        $recommendation = match (true) {
            $riskScore >= 0.7 => 'reject',
            $riskScore >= 0.4 => 'enhanced_review',
            default => 'approve',
        };

        $assessment = [
            'risk_score' => min(1.0, $riskScore),
            'risk_factors' => $riskFactors,
            'recommendation' => $recommendation,
        ];

        $case->forceFill([
            'risk_assessment' => $assessment,
        ])->save();

        return $assessment;
    }

    /**
     * @param array<string, mixed> $caseAttributes
     * @param array<string, mixed> $caseMetadata
     */
    private function transitionMerchant(
        string $merchantId,
        MerchantStatus $newStatus,
        ?int $actorUserId,
        string $reason,
        array $caseAttributes = [],
        array $caseMetadata = [],
    ): void {
        DB::transaction(function () use (
            $merchantId,
            $newStatus,
            $actorUserId,
            $reason,
            $caseAttributes,
            $caseMetadata,
        ): void {
            $merchant = $this->resolveMerchant($merchantId);
            $case = $this->resolveOnboardingCase($merchant);
            $currentStatus = $merchant->status;

            if (! $currentStatus->canTransitionTo($newStatus)) {
                throw new RuntimeException(
                    "Cannot transition from {$currentStatus->value} to {$newStatus->value}"
                );
            }

            $merchant->forceFill([
                'status' => $newStatus,
            ])->save();

            $mergedMetadata = $case->metadata ?? [];
            if ($caseMetadata !== []) {
                $mergedMetadata = array_merge($mergedMetadata, $caseMetadata);
            }

            $case->forceFill(array_merge($caseAttributes, [
                'status' => $newStatus->value,
                'metadata' => $mergedMetadata === [] ? null : $mergedMetadata,
            ]))->save();

            BusinessOnboardingCaseStatusHistory::query()->create([
                'business_onboarding_case_id' => $case->id,
                'from_status' => $currentStatus->value,
                'to_status' => $newStatus->value,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'metadata' => $caseMetadata === [] ? null : $caseMetadata,
            ]);
        });
    }

    private function resolveCorporateProfile(?Team $team): ?CorporateProfile
    {
        if (! $team?->is_business_organization) {
            return null;
        }

        return $team->resolveCorporateProfile();
    }

    private function resolveMerchant(string $merchantId): Merchant
    {
        $merchant = Merchant::query()
            ->where('id', $merchantId)
            ->orWhere('public_id', $merchantId)
            ->first();

        if (! $merchant) {
            throw new InvalidArgumentException("Merchant not found: {$merchantId}");
        }

        return $merchant;
    }

    private function resolveOnboardingCase(Merchant $merchant): BusinessOnboardingCase
    {
        /** @var BusinessOnboardingCase|null $case */
        $case = $merchant->businessOnboardingCase;

        if (! $case) {
            throw new InvalidArgumentException("Merchant onboarding case not found: {$merchant->id}");
        }

        return $case;
    }
}
