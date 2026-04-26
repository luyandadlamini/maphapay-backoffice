<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorCardRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MinorCardRequestService
{
    public function __construct(
        private readonly MinorAccountAccessService $accessService,
    ) {
    }

    /**
     * @param  array<string, string>|null  $limits
     */
    public function createRequest(User $requester, Account $minor, string $network, ?array $limits): MinorCardRequest
    {
        $this->guardCanRequest($requester, $minor);

        if ($minor->tier !== 'rise') {
            throw new InvalidArgumentException('Virtual cards are only available for Rise tier (ages 13+)');
        }

        $hasActiveCard = $this->minorHasActiveCard($minor);
        if ($hasActiveCard) {
            throw new InvalidArgumentException('Minor already has an active virtual card');
        }

        $hasPendingRequest = MinorCardRequest::where('minor_account_uuid', $minor->uuid)
            ->where('status', MinorCardConstants::STATUS_PENDING_APPROVAL)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
        if ($hasPendingRequest) {
            throw new InvalidArgumentException('A pending card request already exists');
        }

        $requestType = $this->accessService->hasGuardianAccess($requester, $minor)
            ? MinorCardConstants::REQUEST_TYPE_PARENT_INITIATED
            : MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED;

        /** @var array{daily?: string, monthly?: string, single_transaction?: string}|null $limits */
        return MinorCardRequest::create([
            'minor_account_uuid'      => $minor->uuid,
            'requested_by_user_uuid'  => $requester->uuid,
            'request_type'            => $requestType,
            'status'                  => MinorCardConstants::STATUS_PENDING_APPROVAL,
            'requested_network'       => $network,
            'requested_daily_limit'   => $limits['daily'] ?? null,
            'requested_monthly_limit' => $limits['monthly'] ?? null,
            'requested_single_limit'  => $limits['single_transaction'] ?? null,
            'expires_at'              => now()->addHours(MinorCardConstants::REQUEST_EXPIRY_HOURS),
        ]);
    }

    public function approve(User $guardian, MinorCardRequest $request): MinorCardRequest
    {
        if (! $request->canBeApproved()) {
            throw new InvalidArgumentException('Request cannot be approved in its current state');
        }

        $request->update([
            'status'                => MinorCardConstants::STATUS_APPROVED,
            'approved_by_user_uuid' => $guardian->uuid,
            'approved_at'           => now(),
        ]);

        return $request->refresh();
    }

    public function deny(User $guardian, MinorCardRequest $request, string $reason): MinorCardRequest
    {
        if (! $request->canBeApproved()) {
            throw new InvalidArgumentException('Request cannot be denied in its current state');
        }

        $request->update([
            'status'        => MinorCardConstants::STATUS_DENIED,
            'denial_reason' => $reason,
        ]);

        return $request->refresh();
    }

    private function guardCanRequest(User $requester, Account $minor): void
    {
        $isMinor = $requester->uuid === $minor->user_uuid;
        $isGuardian = $this->accessService->hasGuardianAccess($requester, $minor);

        if (! $isMinor && ! $isGuardian) {
            throw new InvalidArgumentException('Only the minor or their guardian can request a card');
        }
    }

    private function minorHasActiveCard(Account $minor): bool
    {
        return DB::table('cards')
            ->where('minor_account_uuid', $minor->uuid)
            ->whereIn('status', ['active', 'frozen'])
            ->exists();
    }
}
