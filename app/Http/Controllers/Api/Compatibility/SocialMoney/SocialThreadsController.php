<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\SocialMoney;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MaphaPay compatibility endpoint: social money threads for the authenticated user.
 *
 * Response envelope:
 * {
 *   status: 'success',
 *   data: {
 *     threads: [
 *       {
 *         friendId, friendName, avatarInitials, avatarColor,
 *         lastMessage, lastTimestamp, unreadCount, rowSubtitle,
 *         pillVariant, pillLabel, hasPendingAction
 *       }
 *     ]
 *   }
 * }
 */
class SocialThreadsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'threads' => [],
            ],
        ]);
    }
}
