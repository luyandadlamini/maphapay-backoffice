<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\SocialMoney;

use App\Domain\GroupSavings\Services\GroupPocketTransferService;
use App\Domain\SocialMoney\Events\Broadcast\GroupUpdated;
use App\Domain\SocialMoney\Services\SystemMessageService;
use App\Http\Controllers\Controller;
use App\Models\GroupPocket;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    public function __construct(
        private readonly SystemMessageService $systemMessages,
        private readonly GroupPocketTransferService $groupPocketTransferService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:100',
            'memberIds'   => 'required|array|min:1',
            'memberIds.*' => 'integer',
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $userId = (int) $user->getAuthIdentifier();
        $memberIds = array_map('intval', $request->input('memberIds'));
        $maxParticipants = config('social.max_group_participants', 15);

        if (count($memberIds) + 1 > $maxParticipants) {
            return response()->json([
                'status'  => 'error',
                'message' => "Group cannot exceed {$maxParticipants} members",
            ], 422);
        }

        $friendCount = DB::table('friendships')
            ->where('user_id', $userId)
            ->whereIn('friend_id', $memberIds)
            ->where('status', 'accepted')
            ->count();

        if ($friendCount !== count($memberIds)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'All members must be your friends',
            ], 422);
        }

        $thread = DB::transaction(function () use ($request, $userId, $memberIds): Thread {
            $thread = Thread::create([
                'type'       => 'group',
                'name'       => $request->input('name'),
                'created_by' => $userId,
            ]);

            ThreadParticipant::create([
                'thread_id' => $thread->id,
                'user_id'   => $userId,
                'role'      => 'admin',
                'joined_at' => now(),
            ]);

            foreach ($memberIds as $memberId) {
                ThreadParticipant::create([
                    'thread_id' => $thread->id,
                    'user_id'   => $memberId,
                    'role'      => 'member',
                    'joined_at' => now(),
                    'added_by'  => $userId,
                ]);
            }

            return $thread->load('activeParticipants.user:id,name');
        });

        return response()->json([
            'status' => 'success',
            'data'   => [
                'thread' => [
                    'id'           => (string) $thread->id,
                    'type'         => 'group',
                    'name'         => $thread->name,
                    'participants' => $thread->activeParticipants->map(fn (ThreadParticipant $participant) => [
                        'userId' => (string) $participant->user_id,
                        'name'   => $participant->user !== null ? $participant->user->name : 'User',
                        'role'   => $participant->role,
                    ])->values()->all(),
                ],
            ],
        ]);
    }

    public function update(Request $request, int $threadId): JsonResponse
    {
        $request->validate([
            'name'       => 'sometimes|string|max:100',
            'avatar_url' => 'sometimes|nullable|string|max:500',
        ]);

        $thread = Thread::findOrFail($threadId);
        $this->authorizeAdmin($request, $thread);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $actorName = $user->name;

        if ($request->has('name')) {
            $newName = (string) $request->input('name');
            $thread->update(['name' => $newName]);
            $this->systemMessages->groupRenamed($thread, $userId, $actorName, $newName);
            $this->broadcastToParticipants($thread, 'renamed', $request, metadata: ['newName' => $newName]);
        }

        if ($request->has('avatar_url')) {
            $thread->update(['avatar_url' => $request->input('avatar_url')]);
            $this->broadcastToParticipants($thread, 'avatar_changed', $request);
        }

        return response()->json(['status' => 'success']);
    }

    public function destroy(Request $request, int $threadId): JsonResponse
    {
        $thread = Thread::findOrFail($threadId);
        $this->authorizeAdmin($request, $thread);

        $this->broadcastToParticipants($thread, 'deleted', $request);
        $thread->delete();

        return response()->json(['status' => 'success']);
    }

    public function addMembers(Request $request, int $threadId): JsonResponse
    {
        $request->validate([
            'userIds'   => 'required|array|min:1',
            'userIds.*' => 'integer',
        ]);

        $thread = Thread::findOrFail($threadId);
        $this->authorizeAdmin($request, $thread);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $newUserIds = array_map('intval', $request->input('userIds'));

        $currentActive = ThreadParticipant::where('thread_id', $thread->id)
            ->whereNull('left_at')
            ->count();

        if ($currentActive + count($newUserIds) > $thread->max_participants) {
            return response()->json(['status' => 'error', 'message' => 'Would exceed group member limit'], 422);
        }

        $friendCount = DB::table('friendships')
            ->where('user_id', $userId)
            ->whereIn('friend_id', $newUserIds)
            ->where('status', 'accepted')
            ->count();

        if ($friendCount !== count($newUserIds)) {
            return response()->json(['status' => 'error', 'message' => 'All new members must be your friends'], 422);
        }

        $actorName = $user->name;

        foreach ($newUserIds as $newUserId) {
            $existing = ThreadParticipant::where('thread_id', $thread->id)
                ->where('user_id', $newUserId)
                ->first();

            if ($existing !== null) {
                $existing->update(['left_at' => null, 'joined_at' => now(), 'added_by' => $userId]);
            } else {
                ThreadParticipant::create([
                    'thread_id' => $thread->id,
                    'user_id'   => $newUserId,
                    'role'      => 'member',
                    'joined_at' => now(),
                    'added_by'  => $userId,
                ]);
            }

            $targetUser = User::find($newUserId);
            $targetName = $targetUser !== null ? $targetUser->name : 'User';
            $this->systemMessages->memberAdded($thread, $userId, $newUserId, $actorName, $targetName);
            $this->broadcastToParticipants($thread, 'member_added', $request, $newUserId, $targetName);
        }

        return response()->json(['status' => 'success']);
    }

    public function removeMember(Request $request, int $threadId, int $memberId): JsonResponse
    {
        $thread = Thread::findOrFail($threadId);
        $this->authorizeAdmin($request, $thread);

        $participant = ThreadParticipant::where('thread_id', $thread->id)
            ->where('user_id', $memberId)
            ->whereNull('left_at')
            ->firstOrFail();

        $memberUser = User::findOrFail($memberId);
        $groupPockets = GroupPocket::query()
            ->where('thread_id', $thread->id)
            ->where('status', '!=', GroupPocket::STATUS_CLOSED)
            ->get();

        foreach ($groupPockets as $groupPocket) {
            $this->groupPocketTransferService->refundMemberContributions($groupPocket, $memberUser);
        }

        $participant->update(['left_at' => now()]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $actorName = $user->name;
        $targetUser = User::find($memberId);
        $targetName = $targetUser !== null ? $targetUser->name : 'User';

        $this->systemMessages->memberRemoved($thread, $userId, $memberId, $actorName, $targetName);
        $this->broadcastToParticipants($thread, 'member_removed', $request, $memberId, $targetName);
        event(new GroupUpdated($memberId, $thread->id, 'member_removed', $userId, $actorName, $memberId, $targetName));

        return response()->json(['status' => 'success']);
    }

    public function leave(Request $request, int $threadId): JsonResponse
    {
        $thread = Thread::findOrFail($threadId);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();

        $participant = ThreadParticipant::where('thread_id', $thread->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->firstOrFail();

        $activeMembers = ThreadParticipant::where('thread_id', $thread->id)
            ->whereNull('left_at')
            ->count();

        if ($activeMembers <= 1) {
            $thread->delete();

            return response()->json(['status' => 'success']);
        }

        if ($participant->isAdmin()) {
            $otherAdmins = ThreadParticipant::where('thread_id', $thread->id)
                ->whereNull('left_at')
                ->where('role', 'admin')
                ->where('user_id', '!=', $userId)
                ->count();

            if ($otherAdmins === 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Promote another member to admin before leaving',
                ], 422);
            }
        }

        // Refund member's contributions across all active group pockets in this thread
        $groupPockets = GroupPocket::query()
            ->where('thread_id', $thread->id)
            ->where('status', '!=', GroupPocket::STATUS_CLOSED)
            ->get();

        foreach ($groupPockets as $groupPocket) {
            $this->groupPocketTransferService->refundMemberContributions($groupPocket, $user);
        }

        $participant->update(['left_at' => now()]);
        $actorName = $user->name;
        $this->systemMessages->memberLeft($thread, $userId, $actorName);
        $this->broadcastToParticipants($thread, 'member_left', $request);

        return response()->json(['status' => 'success']);
    }

    public function changeRole(Request $request, int $threadId, int $memberId): JsonResponse
    {
        $request->validate(['role' => 'required|in:admin,member']);

        $thread = Thread::findOrFail($threadId);
        $this->authorizeAdmin($request, $thread);

        $newRole = (string) $request->input('role');
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();

        if ($newRole === 'member' && $memberId === $userId) {
            $otherAdmins = ThreadParticipant::where('thread_id', $thread->id)
                ->whereNull('left_at')
                ->where('role', 'admin')
                ->where('user_id', '!=', $userId)
                ->count();

            if ($otherAdmins === 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot demote yourself as the last admin',
                ], 422);
            }
        }

        $participant = ThreadParticipant::where('thread_id', $thread->id)
            ->where('user_id', $memberId)
            ->whereNull('left_at')
            ->firstOrFail();

        $participant->update(['role' => $newRole]);

        $actorName = $user->name;
        $targetUser = User::find($memberId);
        $targetName = $targetUser !== null ? $targetUser->name : 'User';
        $this->systemMessages->roleChanged($thread, $userId, $actorName, $targetName, $newRole);
        $this->broadcastToParticipants($thread, 'role_changed', $request, $memberId, $targetName, ['newRole' => $newRole]);

        return response()->json(['status' => 'success']);
    }

    private function authorizeAdmin(Request $request, Thread $thread): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $userId = (int) $user->getAuthIdentifier();

        $isAdmin = ThreadParticipant::where('thread_id', $thread->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->where('role', 'admin')
            ->exists();

        if (! $isAdmin) {
            abort(403, 'Admin access required');
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function broadcastToParticipants(
        Thread $thread,
        string $action,
        Request $request,
        ?int $targetUserId = null,
        ?string $targetUserName = null,
        array $metadata = [],
    ): void {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $userId = (int) $user->getAuthIdentifier();
        $actorName = $user->name;

        $participantIds = ThreadParticipant::where('thread_id', $thread->id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $userId)
            ->pluck('user_id');

        foreach ($participantIds as $recipientId) {
            event(new GroupUpdated(
                recipientId: (int) $recipientId,
                threadId: $thread->id,
                action: $action,
                actorId: $userId,
                actorName: $actorName,
                targetUserId: $targetUserId,
                targetUserName: $targetUserName,
                metadata: $metadata,
            ));
        }
    }
}
