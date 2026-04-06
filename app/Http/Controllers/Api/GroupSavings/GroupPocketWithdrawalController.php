<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\GroupSavings;

use App\Domain\GroupSavings\Services\GroupPocketTransferService;
use App\Http\Controllers\Controller;
use App\Models\GroupPocket;
use App\Models\GroupPocketWithdrawalRequest;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class GroupPocketWithdrawalController extends Controller
{
    public function __construct(
        private readonly GroupPocketTransferService $transferService,
    ) {
    }

    /** Member requests a withdrawal. */
    public function request(Request $request, int $pocketId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note'   => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $pocket = GroupPocket::findOrFail($pocketId);

        $isMember = ThreadParticipant::query()
            ->where('thread_id', $pocket->thread_id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isMember) {
            return response()->json(['status' => 'error', 'message' => ['Not a member of this group']], 403);
        }

        if ($pocket->is_locked) {
            return response()->json(['status' => 'error', 'message' => ['Pocket is locked — withdrawals are disabled']], 422);
        }

        // @phpstan-ignore argument.type
        if (bccomp((string) $pocket->current_amount, (string) $validated['amount'], 2) < 0) {
            return response()->json(['status' => 'error', 'message' => ['Requested amount exceeds pocket balance']], 422);
        }

        $withdrawalRequest = GroupPocketWithdrawalRequest::create([
            'group_pocket_id' => $pocket->id,
            'requested_by'    => $user->id,
            'amount'          => $validated['amount'],
            'note'            => $validated['note'] ?? null,
            'status'          => GroupPocketWithdrawalRequest::STATUS_PENDING,
        ]);

        return response()->json([
            'status' => 'success',
            'data'   => ['request' => $this->formatRequest($withdrawalRequest)],
        ], 201);
    }

    /** Admin approves a pending withdrawal request. */
    public function approve(Request $request, int $pocketId, int $requestId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $pocket = GroupPocket::findOrFail($pocketId);
        $this->authorizeAdmin($request, $pocket);

        $withdrawalRequest = GroupPocketWithdrawalRequest::where('group_pocket_id', $pocketId)
            ->findOrFail($requestId);

        try {
            $withdrawalRequest = $this->transferService->approveWithdrawal($withdrawalRequest, $user);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => [$e->getMessage()]], 422);
        }

        return response()->json([
            'status' => 'success',
            'data'   => ['request' => $this->formatRequest($withdrawalRequest)],
        ]);
    }

    /** Admin rejects a pending withdrawal request. */
    public function reject(Request $request, int $pocketId, int $requestId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $pocket = GroupPocket::findOrFail($pocketId);
        $this->authorizeAdmin($request, $pocket);

        $withdrawalRequest = GroupPocketWithdrawalRequest::where('group_pocket_id', $pocketId)
            ->findOrFail($requestId);

        if ($withdrawalRequest->status !== GroupPocketWithdrawalRequest::STATUS_PENDING) {
            return response()->json(['status' => 'error', 'message' => ['Request is not pending']], 422);
        }

        $withdrawalRequest->update([
            'status'      => GroupPocketWithdrawalRequest::STATUS_REJECTED,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'data'   => ['request' => $this->formatRequest($withdrawalRequest->fresh() ?? $withdrawalRequest)],
        ]);
    }

    private function authorizeAdmin(Request $request, GroupPocket $pocket): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $isAdmin = ThreadParticipant::query()
            ->where('thread_id', $pocket->thread_id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->where('role', 'admin')
            ->exists();

        if (! $isAdmin) {
            abort(403, 'Admin access required');
        }
    }

    /** @return array<string, mixed> */
    private function formatRequest(GroupPocketWithdrawalRequest $req): array
    {
        return [
            'id'              => $req->id,
            'group_pocket_id' => $req->group_pocket_id,
            'requested_by'    => $req->requested_by,
            'amount'          => number_format((float) $req->amount, 2, '.', ''),
            'note'            => $req->note,
            'status'          => $req->status,
            'reviewed_by'     => $req->reviewed_by,
            'reviewed_at'     => $req->reviewed_at?->toISOString(),
            'created_at'      => $req->created_at?->toISOString(),
        ];
    }
}
