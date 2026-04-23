<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountAuditLog;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MinorNotificationService
{
    // Spend approval types
    public const TYPE_APPROVAL_REQUESTED = 'approval_requested';

    public const TYPE_APPROVAL_APPROVED = 'approval_approved';

    public const TYPE_APPROVAL_DECLINED = 'approval_declined';

    // Chore types (Phase 4)
    public const TYPE_CHORE_ASSIGNED = 'chore_assigned';

    public const TYPE_CHORE_APPROVED = 'chore_approved';

    public const TYPE_CHORE_REJECTED = 'chore_rejected';

    public const TYPE_POINTS_EARNED = 'points_earned';

    public const TYPE_REWARD_REDEEMED = 'reward_redeemed';

    public const TYPE_FAMILY_FUNDING_LINK_CREATED = 'family_funding_link_created';

    public const TYPE_FAMILY_FUNDING_LINK_EXPIRED = 'family_funding_link_expired';

    public const TYPE_FAMILY_FUNDING_ATTEMPT_INITIATED = 'family_funding_attempt_initiated';

    public const TYPE_FAMILY_FUNDING_ATTEMPT_SUCCEEDED = 'family_funding_attempt_succeeded';

    public const TYPE_FAMILY_FUNDING_ATTEMPT_FAILED = 'family_funding_attempt_failed';

    public const TYPE_FAMILY_FUNDING_CREDITED = 'family_funding_credited';

    public const TYPE_FAMILY_SUPPORT_TRANSFER_INITIATED = 'family_support_transfer_initiated';

    public const TYPE_FAMILY_SUPPORT_TRANSFER_SUCCEEDED = 'family_support_transfer_succeeded';

    public const TYPE_FAMILY_SUPPORT_TRANSFER_FAILED = 'family_support_transfer_failed';

    public const TYPE_FAMILY_SUPPORT_TRANSFER_REFUNDED = 'family_support_transfer_refunded';

    private const ACTION_MAP = [
        self::TYPE_APPROVAL_REQUESTED => 'minor.approval.requested',
        self::TYPE_APPROVAL_APPROVED  => 'minor.approval.approved',
        self::TYPE_APPROVAL_DECLINED  => 'minor.approval.declined',
        self::TYPE_CHORE_ASSIGNED     => 'minor.chore.assigned',
        self::TYPE_CHORE_APPROVED     => 'minor.chore.approved',
        self::TYPE_CHORE_REJECTED     => 'minor.chore.rejected',
        self::TYPE_POINTS_EARNED      => 'minor.points.earned',
        self::TYPE_REWARD_REDEEMED    => 'minor.reward.redeemed',
        self::TYPE_FAMILY_FUNDING_LINK_CREATED => 'minor.family_funding_link.created',
        self::TYPE_FAMILY_FUNDING_LINK_EXPIRED => 'minor.family_funding_link.expired',
        self::TYPE_FAMILY_FUNDING_ATTEMPT_INITIATED => 'minor.family_funding_attempt.initiated',
        self::TYPE_FAMILY_FUNDING_ATTEMPT_SUCCEEDED => 'minor.family_funding_attempt.succeeded',
        self::TYPE_FAMILY_FUNDING_ATTEMPT_FAILED => 'minor.family_funding_attempt.failed',
        self::TYPE_FAMILY_FUNDING_CREDITED => 'minor.family_funding_attempt.credited',
        self::TYPE_FAMILY_SUPPORT_TRANSFER_INITIATED => 'minor.family_support_transfer.initiated',
        self::TYPE_FAMILY_SUPPORT_TRANSFER_SUCCEEDED => 'minor.family_support_transfer.succeeded',
        self::TYPE_FAMILY_SUPPORT_TRANSFER_FAILED => 'minor.family_support_transfer.failed',
        self::TYPE_FAMILY_SUPPORT_TRANSFER_REFUNDED => 'minor.family_support_transfer.refunded',
    ];

    /**
     * Create a notification for a minor account.
     *
     * @param  string  $minorAccountUuid  The minor account UUID to notify
     * @param  string  $type  The notification type (use TYPE_* constants)
     * @param  array<string, mixed>  $data  Notification data/metadata
     */
    public function notify(
        string $minorAccountUuid,
        string $type,
        array $data,
        ?string $actorUserUuid = null,
        ?string $targetType = null,
        ?string $targetId = null,
    ): void {
        try {
            $minorAccount = Account::query()
                ->where('uuid', $minorAccountUuid)
                ->first();

            $resolvedActor = $actorUserUuid ?? $minorAccount?->user_uuid;

            if ($minorAccount === null || $resolvedActor === null) {
                throw new RuntimeException('Unable to resolve minor account or actor for durable notification.');
            }

            $action = self::ACTION_MAP[$type] ?? "minor.notification.{$type}";

            AccountAuditLog::query()->create([
                'account_uuid'    => $minorAccountUuid,
                'actor_user_uuid' => $resolvedActor,
                'action'          => $action,
                'target_type'     => $targetType,
                'target_id'       => $targetId,
                'metadata'        => array_merge($data, ['notification_type' => $type]),
                'created_at'      => now(),
            ]);

            Log::debug('Minor notification persisted', [
                'minor_account_uuid' => $minorAccountUuid,
                'type'               => $type,
                'action'             => $action,
                'target_type'        => $targetType,
                'target_id'          => $targetId,
            ]);
        } catch (Throwable $e) {
            Log::warning("MinorNotificationService: failed to create notification [{$type}] for {$minorAccountUuid}: {$e->getMessage()}");
        }
    }
}
