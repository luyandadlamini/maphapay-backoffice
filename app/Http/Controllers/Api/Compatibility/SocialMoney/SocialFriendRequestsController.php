<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\SocialMoney;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Friend-request lifecycle endpoints:
 * - GET  /api/social-money/friend-requests/incoming
 * - GET  /api/social-money/friend-requests/outgoing
 * - POST /api/social-money/friend-requests/{id}/accept
 * - POST /api/social-money/friend-requests/{id}/reject
 * - POST /api/social-money/friend-requests/{id}/cancel
 */
class SocialFriendRequestsController extends Controller
{
    public function incoming(Request $request): JsonResponse
    {
        $authId = (int) $request->user()->getAuthIdentifier();
        $requests = DB::table('friend_requests')
            ->join('users', 'users.id', '=', 'friend_requests.sender_id')
            ->where('friend_requests.recipient_id', $authId)
            ->where('friend_requests.status', 'pending')
            ->whereNull('users.frozen_at')
            ->select([
                'friend_requests.id',
                'friend_requests.created_at',
                'users.id as user_id',
                'users.name',
                'users.username',
                'users.mobile',
            ])
            ->orderByDesc('friend_requests.created_at')
            ->get()
            ->map(fn ($row) => $this->mapRequestRow($row))
            ->values()
            ->all();

        return response()->json([
            'status' => 'success',
            'remark' => 'social_friend_requests_incoming',
            'data' => ['requests' => $requests],
        ]);
    }

    public function outgoing(Request $request): JsonResponse
    {
        $authId = (int) $request->user()->getAuthIdentifier();
        $requests = DB::table('friend_requests')
            ->join('users', 'users.id', '=', 'friend_requests.recipient_id')
            ->where('friend_requests.sender_id', $authId)
            ->where('friend_requests.status', 'pending')
            ->whereNull('users.frozen_at')
            ->select([
                'friend_requests.id',
                'friend_requests.created_at',
                'users.id as user_id',
                'users.name',
                'users.username',
                'users.mobile',
            ])
            ->orderByDesc('friend_requests.created_at')
            ->get()
            ->map(fn ($row) => $this->mapRequestRow($row))
            ->values()
            ->all();

        return response()->json([
            'status' => 'success',
            'remark' => 'social_friend_requests_outgoing',
            'data' => ['requests' => $requests],
        ]);
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $authId = (int) $request->user()->getAuthIdentifier();
        $fr = DB::table('friend_requests')
            ->where('id', $id)
            ->where('recipient_id', $authId)
            ->where('status', 'pending')
            ->first();

        if (! $fr) {
            return $this->notFound();
        }

        DB::transaction(function () use ($fr): void {
            DB::table('friend_requests')->where('id', $fr->id)->update([
                'status' => 'accepted',
                'updated_at' => now(),
            ]);

            DB::table('friendships')->upsert(
                [
                    [
                        'user_id' => $fr->sender_id,
                        'friend_id' => $fr->recipient_id,
                        'status' => 'accepted',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'user_id' => $fr->recipient_id,
                        'friend_id' => $fr->sender_id,
                        'status' => 'accepted',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ],
                ['user_id', 'friend_id'],
                ['status', 'updated_at'],
            );
        });

        return response()->json([
            'status' => 'success',
            'remark' => 'social_friend_requests_accept',
            'data' => [],
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $authId = (int) $request->user()->getAuthIdentifier();
        $updated = DB::table('friend_requests')
            ->where('id', $id)
            ->where('recipient_id', $authId)
            ->where('status', 'pending')
            ->update(['status' => 'rejected', 'updated_at' => now()]);

        if ($updated === 0) {
            return $this->notFound();
        }

        return response()->json([
            'status' => 'success',
            'remark' => 'social_friend_requests_reject',
            'data' => [],
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $authId = (int) $request->user()->getAuthIdentifier();
        $updated = DB::table('friend_requests')
            ->where('id', $id)
            ->where('sender_id', $authId)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        if ($updated === 0) {
            return $this->notFound();
        }

        return response()->json([
            'status' => 'success',
            'remark' => 'social_friend_requests_cancel',
            'data' => [],
        ]);
    }

    private function mapRequestRow(object $row): array
    {
        $name = (string) ($row->name ?? 'User');
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $second = $parts[1] ?? '';
        $initials = strtoupper(substr($first, 0, 1).substr($second, 0, 1));

        return [
            'id' => (string) $row->id,
            'userId' => (string) $row->user_id,
            'name' => $name,
            'handle' => (string) ($row->username ?? ''),
            'avatarInitials' => $initials !== '' ? $initials : 'U',
            'avatarColor' => '#5B8DEF',
            'phoneNumber' => (string) ($row->mobile ?? ''),
            'createdAt' => (string) $row->created_at,
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'remark' => 'social_friend_requests',
            'message' => ['Not found.'],
        ], 404);
    }
}

