<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Services;

use App\Domain\Corporate\Models\CorporateActionApprovalRequest;
use App\Models\Team;
use App\Models\User;
use InvalidArgumentException;

class CorporateActionPolicy
{
    private const GOVERNED_ACTION_TYPES = [
        'treasury_affecting'   => 'request_approve',
        'membership_change'    => 'request_approve',
        'api_ownership_change' => 'direct_elevated',
    ];

    /**
     * Classify a corporate action as 'request_approve', 'direct_elevated', or 'blocked'.
     */
    public function classify(string $actionType, User $requester, Team $team): string
    {
        return self::GOVERNED_ACTION_TYPES[$actionType] ?? 'blocked';
    }

    /**
     * Persist a corporate action approval request.
     *
     * Records the request with all metadata but does NOT execute the action.
     *
     * @param array<int|string, mixed> $evidence
     */
    public function submitApprovalRequest(
        User $requester,
        Team $team,
        string $actionType,
        string $targetType,
        string $targetIdentifier,
        array $evidence = [],
    ): CorporateActionApprovalRequest {
        $classification = $this->classify($actionType, $requester, $team);

        if ($classification === 'blocked') {
            throw new InvalidArgumentException(
                "Action type '{$actionType}' is not a governed corporate action type."
            );
        }

        $profile = $team->resolveCorporateProfile();

        /** @var CorporateActionApprovalRequest $request */
        $request = CorporateActionApprovalRequest::query()->create([
            'corporate_profile_id' => $profile->id,
            'action_type'          => $actionType,
            'action_status'        => 'pending',
            'requester_id'         => $requester->id,
            'target_type'          => $targetType,
            'target_identifier'    => $targetIdentifier,
            'evidence'             => $evidence === [] ? null : $evidence,
        ]);

        return $request;
    }

    /**
     * Approve a pending approval request.
     *
     * Requires that the reviewer is not the same person as the requester.
     */
    public function approve(
        CorporateActionApprovalRequest $request,
        User $reviewer,
        string $reason = '',
    ): void {
        if ($request->requester_id === $reviewer->id) {
            throw new InvalidArgumentException('A requester cannot self-approve their own corporate action request.');
        }

        $request->forceFill([
            'action_status' => 'approved',
            'reviewer_id'   => $reviewer->id,
            'reviewed_at'   => now(),
            'review_reason' => $reason,
        ])->save();
    }

    /**
     * Reject a pending approval request.
     */
    public function reject(
        CorporateActionApprovalRequest $request,
        User $reviewer,
        string $reason,
    ): void {
        $request->forceFill([
            'action_status' => 'rejected',
            'reviewer_id'   => $reviewer->id,
            'reviewed_at'   => now(),
            'review_reason' => $reason,
        ])->save();
    }
}
