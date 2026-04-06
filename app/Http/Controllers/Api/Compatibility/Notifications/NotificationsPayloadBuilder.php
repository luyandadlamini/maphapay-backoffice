<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Notifications;

use App\Domain\Mobile\Models\MobilePushNotification;

class NotificationsPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function notification(MobilePushNotification $notification): array
    {
        return [
            'id'         => $notification->id,
            'title'      => $notification->title,
            'remark'     => $notification->body,
            'body'       => $notification->body,
            'type'       => $notification->notification_type,
            'status'     => $notification->status,
            'user_read'  => $notification->read_at !== null || $notification->status === MobilePushNotification::STATUS_READ,
            'created_at' => $notification->created_at->toIso8601String(),
            'updated_at' => $notification->updated_at->toIso8601String(),
        ];
    }
}
