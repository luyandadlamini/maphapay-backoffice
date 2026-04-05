<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\SocialMoney;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/social-money/summary.
 *
 * Returns the social money hub summary for the authenticated user.
 * Friends count, threads, pending requests, and unread messages.
 */
class SocialSummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $friendsCount = DB::table('friendships')
            ->where('user_id', $userId)
            ->where('status', 'accepted')
            ->count();
        $pendingIncoming = DB::table('friend_requests')
            ->where('recipient_id', $userId)
            ->where('status', 'pending')
            ->count();

        return response()->json([
            'status' => 'success',
            'data'   => [
                // Expected by RN `SocialSummary` normalizer.
                'youOweTotal'       => 0,
                'owedToYouTotal'    => 0,
                'activeSplitsCount' => 0,
                'topSplits'         => [],
                'settleTarget'      => null,
                'remindTargets'     => [],
                // Backward-compatible extra counters.
                'friends_count'    => $friendsCount,
                'threads_count'    => $friendsCount,
                'pending_requests' => $pendingIncoming,
                'unread_messages'  => 0,
            ],
        ]);
    }
}
