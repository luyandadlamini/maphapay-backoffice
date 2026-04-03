<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Notifications;

use App\Domain\Mobile\Models\MobilePushNotification;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushNotificationsReadController extends Controller
{
    public function __construct(
        private readonly NotificationsPayloadBuilder $payloadBuilder,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notification = MobilePushNotification::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if ($notification === null) {
            return response()->json([
                'status' => 'error',
                'remark' => 'push_notification_not_found',
                'message' => ['Notification not found'],
            ], 404);
        }

        if ($notification->read_at === null) {
            $notification->forceFill([
                'status' => MobilePushNotification::STATUS_READ,
                'read_at' => now(),
            ])->save();
        }

        return response()->json([
            'status' => 'success',
            'remark' => 'push_notification_read',
            'data' => [
                'notification' => $this->payloadBuilder->notification($notification->fresh()),
            ],
        ]);
    }
}
