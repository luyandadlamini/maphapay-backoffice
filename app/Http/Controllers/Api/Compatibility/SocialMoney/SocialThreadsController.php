<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\SocialMoney;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        $userId = (int) $request->user()->getAuthIdentifier();
        $friends = DB::table('friendships')
            ->join('users', 'users.id', '=', 'friendships.friend_id')
            ->where('friendships.user_id', $userId)
            ->where('friendships.status', 'accepted')
            ->whereNull('users.frozen_at')
            ->select(['users.id', 'users.name'])
            ->orderBy('users.name')
            ->get();

        $threads = $friends->map(function ($row) use ($userId): array {
            $friendId = (int) $row->id;
            $name = (string) ($row->name ?? 'User');
            $parts = preg_split('/\s+/', trim($name)) ?: [];
            $first = $parts[0] ?? '';
            $second = $parts[1] ?? '';
            $initials = strtoupper(substr($first, 0, 1).substr($second, 0, 1));

            $a = min($userId, $friendId);
            $b = max($userId, $friendId);
            $chatKey = "social_chat:{$a}:{$b}";
            $messages = Cache::get($chatKey, []);
            $last = is_array($messages) && $messages !== [] ? end($messages) : null;
            $preview = is_array($last) ? (string) ($last['text'] ?? '') : '';
            $lastTs = is_array($last) ? (string) ($last['timestamp'] ?? now()->toISOString()) : now()->toISOString();

            return [
                'friendId' => (string) $friendId,
                'friendName' => $name,
                'avatarInitials' => $initials !== '' ? $initials : 'U',
                'avatarColor' => '#5B8DEF',
                'lastMessage' => $preview,
                'lastTimestamp' => $lastTs,
                'unreadCount' => 0,
                'rowSubtitle' => $preview,
                'pillVariant' => 'none',
                'pillLabel' => null,
                'hasPendingAction' => false,
            ];
        })->values()->all();

        return response()->json([
            'status' => 'success',
            'data' => [
                'threads' => $threads,
            ],
        ]);
    }
}
