<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\SocialMoney;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/social-money/friendship-status/{userId}
 */
class SocialFriendshipStatusController extends Controller
{
    public function __invoke(Request $request, int $userId): JsonResponse
    {
        $authId = (int) $request->user()->getAuthIdentifier();

        if ($authId === $userId) {
            return response()->json([
                'status' => 'success',
                'remark' => 'social_friendship_status',
                'data' => ['status' => 'none'],
            ]);
        }

        /** @var User|null $peer */
        $peer = User::query()->whereKey($userId)->whereNull('frozen_at')->first();
        if (! $peer) {
            return response()->json([
                'status' => 'success',
                'remark' => 'social_friendship_status',
                'data' => ['status' => 'none'],
            ]);
        }

        $friends = DB::table('friendships')
            ->where('user_id', $authId)
            ->where('friend_id', $userId)
            ->where('status', 'accepted')
            ->exists();

        if ($friends) {
            return response()->json([
                'status' => 'success',
                'remark' => 'social_friendship_status',
                'data' => [
                    'status' => 'friends',
                    'peer' => $this->mapPeer($peer),
                ],
            ]);
        }

        $outgoing = DB::table('friend_requests')
            ->where('sender_id', $authId)
            ->where('recipient_id', $userId)
            ->where('status', 'pending')
            ->first();
        if ($outgoing) {
            return response()->json([
                'status' => 'success',
                'remark' => 'social_friendship_status',
                'data' => [
                    'status' => 'pending_outgoing',
                    'requestId' => (string) $outgoing->id,
                    'peer' => $this->mapPeer($peer),
                ],
            ]);
        }

        $incoming = DB::table('friend_requests')
            ->where('sender_id', $userId)
            ->where('recipient_id', $authId)
            ->where('status', 'pending')
            ->first();
        if ($incoming) {
            return response()->json([
                'status' => 'success',
                'remark' => 'social_friendship_status',
                'data' => [
                    'status' => 'pending_incoming',
                    'requestId' => (string) $incoming->id,
                    'peer' => $this->mapPeer($peer),
                ],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'remark' => 'social_friendship_status',
            'data' => [
                'status' => 'none',
                'peer' => $this->mapPeer($peer),
            ],
        ]);
    }

    private function mapPeer(User $peer): array
    {
        $name = (string) ($peer->name ?? 'User');
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $second = $parts[1] ?? '';
        $initials = strtoupper(substr($first, 0, 1).substr($second, 0, 1));

        return [
            'id' => (string) $peer->id,
            'name' => $name,
            'handle' => (string) ($peer->username ?? ''),
            'avatarInitials' => $initials !== '' ? $initials : 'U',
            'avatarColor' => '#5B8DEF',
            'phoneNumber' => (string) ($peer->mobile ?? ''),
        ];
    }
}

