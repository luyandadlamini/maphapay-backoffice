<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use Illuminate\Support\Facades\Log;
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

    /**
     * Create a notification for a minor account.
     *
     * @param  string  $minorAccountUuid  The minor account UUID to notify
     * @param  string  $type  The notification type (use TYPE_* constants)
     * @param  array<string, mixed>  $data  Notification data/metadata
     */
    public function notify(string $minorAccountUuid, string $type, array $data): void
    {
        try {
            // Log the notification for now - full implementation would save to database
            Log::debug('Minor notification created', [
                'minor_account_uuid' => $minorAccountUuid,
                'type'               => $type,
                'data'               => $data,
            ]);
        } catch (Throwable $e) {
            Log::warning("MinorNotificationService: failed to create notification [{$type}] for {$minorAccountUuid}: {$e->getMessage()}");
        }
    }
}
