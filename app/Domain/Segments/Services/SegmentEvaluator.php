<?php

declare(strict_types=1);

namespace App\Domain\Segments\Services;

use App\Domain\Segments\Enums\SegmentSource;
use App\Domain\Segments\Models\CustomerSegment;
use App\Domain\Segments\Models\SegmentMembership;
use App\Domain\Segments\ValueObjects\SegmentRuleGroup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SegmentEvaluator
{
    public function evaluate(int $userId, CustomerSegment $segment): bool
    {
        return match ($segment->source) {
            SegmentSource::Static  => $this->hasActiveStaticMembership($userId, $segment->id),
            SegmentSource::Dynamic => $this->evaluateDynamic($userId, $segment),
            SegmentSource::Hybrid  => $this->hasActiveStaticMembership($userId, $segment->id)
                                      && $this->evaluateDynamic($userId, $segment),
        };
    }

    /**
     * @return array<int>
     */
    public function userSegmentIds(int $userId): array
    {
        /** @var array<int> */
        return Cache::remember(
            "segment_evaluator.user.{$userId}",
            300,
            function () use ($userId): array {
                return CustomerSegment::active()
                    ->get()
                    ->filter(fn (CustomerSegment $segment): bool => $this->evaluate($userId, $segment))
                    ->pluck('id')
                    ->values()
                    ->all();
            }
        );
    }

    private function hasActiveStaticMembership(int $userId, int $segmentId): bool
    {
        return SegmentMembership::query()
            ->where('user_id', $userId)
            ->where('segment_id', $segmentId)
            ->active()
            ->exists();
    }

    private function evaluateDynamic(int $userId, CustomerSegment $segment): bool
    {
        if (empty($segment->rules)) {
            return false;
        }

        $user = User::find($userId);

        if ($user === null) {
            return false;
        }

        return SegmentRuleGroup::fromArray($segment->rules)->evaluate($this->buildContext($user));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(User $user): array
    {
        $account = $user->account()->first();
        $team = $user->currentTeam;

        $kycTier = 0;

        if ($user->kyc_submitted_at !== null) {
            $kycTier = 1;
        }

        if ($user->kyc_approved_at !== null) {
            $kycTier = 2;
        }

        return [
            'user.kyc_tier'           => $kycTier,
            'user.sponsored_tx_used'  => $user->sponsored_tx_used ?? 0,
            'user.sponsored_tx_limit' => $user->sponsored_tx_limit ?? 0,
            'account.type'            => $account?->type,
            'account.tier'            => $account?->tier,
            'team.organization_type'  => $team?->organization_type,
            'team.is_business'        => $team?->is_business_organization,
            'user.roles'              => $user->getRoleNames()->toArray(),
        ];
    }
}
