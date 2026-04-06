<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\GroupSavings;

use App\Domain\GroupSavings\Services\GroupPocketTransferService;
use App\Http\Controllers\Controller;
use App\Models\GroupPocket;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupPocketController extends Controller
{
    public function __construct(
        private readonly GroupPocketTransferService $transferService,
    ) {
    }

    /** All group pockets across all groups the authenticated user belongs to. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $threadIds = ThreadParticipant::query()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->pluck('thread_id');

        $pockets = GroupPocket::query()
            ->whereIn('thread_id', $threadIds)
            ->where('status', '!=', GroupPocket::STATUS_CLOSED)
            ->with('thread:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => ['pockets' => $pockets->map(function (GroupPocket $p) {
                return $this->formatPocket($p);
            })->values()],
        ]);
    }

    /** Group pockets for a single thread (requester must be a member). */
    public function byThread(Request $request, int $threadId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $thread = Thread::findOrFail($threadId);

        $isMember = ThreadParticipant::query()
            ->where('thread_id', $thread->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isMember) {
            return response()->json(['status' => 'error', 'message' => ['Not a member of this group']], 403);
        }

        $pockets = GroupPocket::query()
            ->where('thread_id', $thread->id)
            ->where('status', '!=', GroupPocket::STATUS_CLOSED)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => ['pockets' => $pockets->map(function (GroupPocket $p) {
                return $this->formatPocket($p);
            })->values()],
        ]);
    }

    /** Create a new group pocket (any member). */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'thread_id'     => 'required|integer|exists:threads,id',
            'name'          => 'required|string|max:150',
            'category'      => 'required|in:travel,transport,tech,emergency,food,health,education,general',
            'color'         => 'required|string|max:7',
            'target_amount' => 'required|numeric|min:1|max:100000',
            'target_date'   => 'nullable|date|after:today',
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var Thread $thread */
        $thread = Thread::findOrFail($validated['thread_id']);

        if (! $thread->isGroup()) {
            return response()->json(['status' => 'error', 'message' => ['Thread must be a group']], 422);
        }

        $isMember = ThreadParticipant::query()
            ->where('thread_id', $thread->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isMember) {
            return response()->json(['status' => 'error', 'message' => ['Not a member of this group']], 403);
        }

        $pocket = GroupPocket::create([
            'thread_id'     => $thread->id,
            'created_by'    => $user->id,
            'name'          => $validated['name'],
            'category'      => $validated['category'],
            'color'         => $validated['color'],
            'target_amount' => $validated['target_amount'],
            'target_date'   => $validated['target_date'] ?? null,
            'status'        => GroupPocket::STATUS_ACTIVE,
        ]);

        return response()->json([
            'status' => 'success',
            'data'   => ['pocket' => $this->formatPocket($pocket)],
        ], 201);
    }

    /** Update name, target, color, is_locked (admin only). */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $pocket = GroupPocket::findOrFail($id);
        /** @var \App\Models\Thread $thread */
        $thread = $pocket->thread;
        $this->authorizeAdmin($request, $thread);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:150',
            'target_amount' => 'sometimes|numeric|min:1|max:100000',
            'target_date'   => 'sometimes|nullable|date|after:today',
            'color'         => 'sometimes|string|max:7',
            'is_locked'     => 'sometimes|boolean',
        ]);

        $pocket->update($validated);

        return response()->json([
            'status' => 'success',
            'data'   => ['pocket' => $this->formatPocket($pocket->fresh() ?? $pocket)],
        ]);
    }

    /** Close a pocket — refunds all members (admin only). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $pocket = GroupPocket::findOrFail($id);
        /** @var \App\Models\Thread $thread */
        $thread = $pocket->thread;
        $this->authorizeAdmin($request, $thread);

        $this->transferService->refundAllContributions($pocket);
        $pocket->update(['status' => GroupPocket::STATUS_CLOSED]);

        return response()->json(['status' => 'success']);
    }

    private function authorizeAdmin(Request $request, Thread $thread): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $isAdmin = ThreadParticipant::query()
            ->where('thread_id', $thread->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->where('role', 'admin')
            ->exists();

        if (! $isAdmin) {
            abort(403, 'Admin access required');
        }
    }

    /** @return array<string, mixed> */
    private function formatPocket(GroupPocket $pocket): array
    {
        return [
            'id'             => $pocket->id,
            'thread_id'      => $pocket->thread_id,
            'thread_name'    => $pocket->thread?->name,
            'created_by'     => $pocket->created_by,
            'name'           => $pocket->name,
            'category'       => $pocket->category,
            'color'          => $pocket->color,
            'target_amount'  => number_format((float) $pocket->target_amount, 2, '.', ''),
            'current_amount' => number_format((float) $pocket->current_amount, 2, '.', ''),
            'target_date'    => $pocket->target_date instanceof \Illuminate\Support\Carbon ? $pocket->target_date->format('Y-m-d') : $pocket->target_date,
            'is_completed'   => $pocket->is_completed,
            'is_locked'      => $pocket->is_locked,
            'status'         => $pocket->status,
        ];
    }
}
