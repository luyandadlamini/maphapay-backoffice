<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Notifications;

use App\Domain\Mobile\Models\MobilePushNotification;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushNotificationsController extends Controller
{
    public function __construct(
        private readonly NotificationsPayloadBuilder $payloadBuilder,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $paginator = MobilePushNotification::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        return response()->json([
            'status' => 'success',
            'remark' => 'push_notifications',
            'data' => [
                'notifications' => [
                    'data' => collect($paginator->items())
                        ->map(fn (MobilePushNotification $notification): array => $this->payloadBuilder->notification($notification))
                        ->values()
                        ->all(),
                    'current_page' => $paginator->currentPage(),
                    'next_page_url' => $paginator->nextPageUrl(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }
}
