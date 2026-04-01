<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\SocialMoney;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/social-money/friend-requests
 *
 * Compatibility endpoint for mobile Add Friend action.
 */
class SocialFriendRequestStoreController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'userId' => ['required', 'integer', 'exists:users,id'],
        ]);

        /** @var User $authUser */
        $authUser = $request->user();
        $senderId = (int) $authUser->getAuthIdentifier();
        $recipientId = (int) $validated['userId'];

        if ($senderId === $recipientId) {
            return response()->json([
                'status' => 'error',
                'remark' => 'social_friend_request',
                'message' => ['You cannot add yourself.'],
            ], 422);
        }

        $payload = DB::transaction(function () use ($senderId, $recipientId): array {
            // Already friends: return accepted for idempotent UX.
            $alreadyFriends = DB::table('friendships')
                ->where('user_id', $senderId)
                ->where('friend_id', $recipientId)
                ->where('status', 'accepted')
                ->exists();

            if ($alreadyFriends) {
                return [
                    'requestId' => null,
                    'accepted' => true,
                ];
            }

            // If recipient already requested sender, auto-accept that request.
            $incoming = DB::table('friend_requests')
                ->where('sender_id', $recipientId)
                ->where('recipient_id', $senderId)
                ->where('status', 'pending')
                ->first();

            if ($incoming !== null) {
                DB::table('friend_requests')
                    ->where('id', $incoming->id)
                    ->update([
                        'status' => 'accepted',
                        'updated_at' => now(),
                    ]);

                DB::table('friendships')->upsert(
                    [
                        [
                            'user_id' => $senderId,
                            'friend_id' => $recipientId,
                            'status' => 'accepted',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'user_id' => $recipientId,
                            'friend_id' => $senderId,
                            'status' => 'accepted',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ],
                    ['user_id', 'friend_id'],
                    ['status', 'updated_at'],
                );

                return [
                    'requestId' => (string) $incoming->id,
                    'accepted' => true,
                ];
            }

            DB::table('friend_requests')->upsert(
                [
                    'sender_id' => $senderId,
                    'recipient_id' => $recipientId,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['sender_id', 'recipient_id'],
                ['status', 'updated_at'],
            );

            $requestId = DB::table('friend_requests')
                ->where('sender_id', $senderId)
                ->where('recipient_id', $recipientId)
                ->value('id');

            return [
                'requestId' => $requestId !== null ? (string) $requestId : null,
                'accepted' => false,
            ];
        });

        return response()->json([
            'status' => 'success',
            'remark' => 'social_friend_request',
            'data' => $payload,
        ]);
    }
}

