<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\SocialMoney;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/social-money/friends
 */
class SocialFriendsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();

        $friends = DB::table('friendships')
            ->join('users', 'users.id', '=', 'friendships.friend_id')
            ->where('friendships.user_id', $userId)
            ->where('friendships.status', 'accepted')
            ->whereNull('users.frozen_at')
            ->select([
                'users.id',
                'users.name',
                'users.username',
                'users.mobile',
            ])
            ->orderBy('users.name')
            ->get()
            ->map(function ($row): array {
                $name = (string) ($row->name ?? 'User');
                $parts = preg_split('/\s+/', trim($name)) ?: [];
                $first = $parts[0] ?? '';
                $second = $parts[1] ?? '';
                $initials = strtoupper(substr($first, 0, 1).substr($second, 0, 1));

                return [
                    'id' => (string) $row->id,
                    'name' => $name,
                    'handle' => (string) ($row->username ?? ''),
                    'avatarInitials' => $initials !== '' ? $initials : 'U',
                    'avatarColor' => '#5B8DEF',
                    'phoneNumber' => (string) ($row->mobile ?? ''),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'status' => 'success',
            'remark' => 'social_friends',
            'data' => [
                'friends' => $friends,
            ],
        ]);
    }
}

