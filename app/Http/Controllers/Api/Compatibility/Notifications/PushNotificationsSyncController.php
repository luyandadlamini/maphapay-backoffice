<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Notifications;

use App\Domain\Mobile\Models\MobilePushNotification;
use App\Http\Controllers\Api\Compatibility\Concerns\ParsesChangedSince;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushNotificationsSyncController extends Controller
{
    use ParsesChangedSince;

    public function __construct(
        private readonly NotificationsPayloadBuilder $payloadBuilder,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $changedSince = $this->parseChangedSince($request);

        $query = MobilePushNotification::query()
            ->where('user_id', $user->id)
            ->orderBy('updated_at');

        if ($changedSince !== null) {
            $query->where('updated_at', '>', $changedSince);
        }

        $notifications = $query->get();

        return response()->json([
            'status' => 'success',
            'remark' => 'push_notifications_sync',
            'items' => $notifications
                ->map(fn (MobilePushNotification $notification): array => $this->payloadBuilder->notification($notification))
                ->values()
                ->all(),
            'deleted_ids' => [],
            'next_sync_token' => $this->nextSyncToken(
                MobilePushNotification::query()
                    ->where('user_id', $user->id)
                    ->pluck('updated_at')
                    ->all(),
            ),
        ]);
    }
}
