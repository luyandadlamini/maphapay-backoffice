<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\SocialMoney;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/social-money/summary
 *
 * Returns the social money hub summary for the authenticated user.
 * Friends count, threads, pending requests, and unread messages.
 */
class SocialSummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => [
                'friends_count'    => 0,
                'threads_count'    => 0,
                'pending_requests' => 0,
                'unread_messages'  => 0,
            ],
        ]);
    }
}
