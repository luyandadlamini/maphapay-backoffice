<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorChore;
use App\Domain\Account\Models\MinorChoreCompletion;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Domain\Account\Services\MinorChoreService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MinorChoreController extends Controller
{
    public function __construct(
        private readonly MinorAccountAccessService $accessService,
        private readonly MinorChoreService $choreService,
    ) {
    }

    /**
     * GET /api/accounts/minor/{uuid}/chores.
     *
     * List all chores for a minor account.
     * Accessible by child or guardian.
     */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $minorAccount = Account::query()->where('uuid', $uuid)->firstOrFail();
        $userAccount = $this->requireAccess($request, $minorAccount);

        $paginated = MinorChore::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->with(['completions' => function ($query) {
                $query->orderByDesc('created_at');
            }])
            ->orderByDesc('created_at')
            ->paginate(20);

        $chores = $paginated->map(fn (MinorChore $chore) => [
            'id'            => $chore->id,
            'title'         => $chore->title,
            'description'   => $chore->description,
            'payout_points' => $chore->payout_points,
            'payout_type'   => $chore->payout_type,
            'due_at'        => $chore->due_at?->toIso8601String(),
            'recurrence'    => $chore->recurrence,
            'status'        => $chore->status,
            'created_at'    => $chore->created_at?->toIso8601String(),
            'updated_at'    => $chore->updated_at?->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $chores,
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    /**
     * POST /api/accounts/minor/{uuid}/chores.
     *
     * Create a new chore for a minor account.
     * Only guardians can create chores.
     */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $minorAccount = Account::query()->where('uuid', $uuid)->firstOrFail();
        $userAccount = $this->requireGuardian($request, $minorAccount, 'create');

        $validated = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string|max:1000',
            'payout_points' => 'required|integer|min:1|max:10000',
            'due_at'        => 'nullable|date|after:now',
            'recurrence'    => 'nullable|in:weekly,monthly',
        ]);

        try {
            $chore = $this->choreService->create($userAccount, $minorAccount, $validated);

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'            => $chore->id,
                    'title'         => $chore->title,
                    'description'   => $chore->description,
                    'payout_points' => $chore->payout_points,
                    'payout_type'   => $chore->payout_type,
                    'due_at'        => $chore->due_at?->toIso8601String(),
                    'recurrence'    => $chore->recurrence,
                    'status'        => $chore->status,
                    'created_at'    => $chore->created_at?->toIso8601String(),
                ],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Chore creation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('MinorChoreController: store failed', [
                'minor_account_uuid' => $minorAccount->uuid,
                'error'              => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the chore.',
            ], 500);
        }
    }

    /**
     * DELETE /api/accounts/minor/{uuid}/chores/{choreId}.
     *
     * Cancel a chore.
     * Only guardians can cancel chores.
     */
    public function destroy(Request $request, string $uuid, string $choreId): JsonResponse
    {
        $minorAccount = Account::query()->where('uuid', $uuid)->firstOrFail();
        $userAccount = $this->requireGuardian($request, $minorAccount, 'delete');

        $chore = MinorChore::query()
            ->where('id', $choreId)
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->firstOrFail();

        $chore->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Chore cancelled.',
            'data'    => [
                'id'     => $chore->id,
                'status' => $chore->status,
            ],
        ]);
    }

    /**
     * POST /api/accounts/minor/{uuid}/chores/{choreId}/complete.
     *
     * Submit a chore completion.
     * Accessible by child or guardian.
     */
    public function complete(Request $request, string $uuid, string $choreId): JsonResponse
    {
        $minorAccount = Account::query()->where('uuid', $uuid)->firstOrFail();
        $userAccount = $this->requireAccess($request, $minorAccount);

        $chore = MinorChore::query()
            ->where('id', $choreId)
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->firstOrFail();

        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        try {
            $completion = $this->choreService->submitCompletion(
                $chore,
                $validated['note'] ?? null
            );

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'              => $completion->id,
                    'chore_id'        => $completion->chore_id,
                    'status'          => $completion->status,
                    'submission_note' => $completion->submission_note,
                    'created_at'      => $completion->created_at?->toIso8601String(),
                ],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Chore completion submission failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('MinorChoreController: complete failed', [
                'minor_account_uuid' => $minorAccount->uuid,
                'chore_id'           => $choreId,
                'error'              => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while submitting the chore completion.',
            ], 500);
        }
    }

    /**
     * POST /api/accounts/minor/{uuid}/chores/{choreId}/approve/{completionId}.
     *
     * Approve a chore completion and award points.
     * Only guardians can approve.
     */
    public function approve(Request $request, string $uuid, string $choreId, string $completionId): JsonResponse
    {
        $minorAccount = Account::query()->where('uuid', $uuid)->firstOrFail();
        $userAccount = $this->requireGuardian($request, $minorAccount, 'approve');

        $chore = MinorChore::query()
            ->where('id', $choreId)
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->firstOrFail();

        $completion = MinorChoreCompletion::query()
            ->where('id', $completionId)
            ->where('chore_id', $choreId)
            ->firstOrFail();

        try {
            $this->choreService->approve($completion, $userAccount);
            $completion->refresh();

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'                       => $completion->id,
                    'chore_id'                 => $completion->chore_id,
                    'status'                   => $completion->status,
                    'submission_note'          => $completion->submission_note,
                    'reviewed_by_account_uuid' => $completion->reviewed_by_account_uuid,
                    'reviewed_at'              => $completion->reviewed_at?->toIso8601String(),
                    'payout_processed_at'      => $completion->payout_processed_at?->toIso8601String(),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Chore approval failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('MinorChoreController: approve failed', [
                'minor_account_uuid' => $minorAccount->uuid,
                'chore_id'           => $choreId,
                'completion_id'      => $completionId,
                'error'              => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while approving the chore.',
            ], 500);
        }
    }

    /**
     * POST /api/accounts/minor/{uuid}/chores/{choreId}/reject/{completionId}.
     *
     * Reject a chore completion.
     * Only guardians can reject.
     */
    public function reject(Request $request, string $uuid, string $choreId, string $completionId): JsonResponse
    {
        $minorAccount = Account::query()->where('uuid', $uuid)->firstOrFail();
        $userAccount = $this->requireGuardian($request, $minorAccount, 'reject');

        $chore = MinorChore::query()
            ->where('id', $choreId)
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->firstOrFail();

        $completion = MinorChoreCompletion::query()
            ->where('id', $completionId)
            ->where('chore_id', $choreId)
            ->firstOrFail();

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $this->choreService->reject($completion, $userAccount, $validated['reason']);
            $completion->refresh();

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'                       => $completion->id,
                    'chore_id'                 => $completion->chore_id,
                    'status'                   => $completion->status,
                    'submission_note'          => $completion->submission_note,
                    'rejection_reason'         => $completion->rejection_reason,
                    'reviewed_by_account_uuid' => $completion->reviewed_by_account_uuid,
                    'reviewed_at'              => $completion->reviewed_at?->toIso8601String(),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Chore rejection failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('MinorChoreController: reject failed', [
                'minor_account_uuid' => $minorAccount->uuid,
                'chore_id'           => $choreId,
                'completion_id'      => $completionId,
                'error'              => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while rejecting the chore.',
            ], 500);
        }
    }

    /**
     * Private authorization helper.
     *
     * Ensures the authenticated user is the child or a guardian of the minor account.
     * Throws 401 if not authenticated, 403 if not authorized.
     */
    private function requireAccess(Request $request, Account $minorAccount): Account
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $this->authorize('view', [MinorChore::class, $minorAccount]);

        return Account::query()
            ->where('user_uuid', $user->uuid)
            ->where('uuid', '!=', $minorAccount->uuid)
            ->orderByRaw("case when type = 'personal' then 0 else 1 end")
            ->orderBy('id')
            ->first() ?? $minorAccount;
    }

    /**
     * Private authorization helper.
     *
     * Ensures the authenticated user is a guardian of the minor account.
     * Throws 401 if not authenticated, 403 if not authorized.
     */
    private function requireGuardian(Request $request, Account $minorAccount, string $ability = 'create'): Account
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $this->authorize($ability, [MinorChore::class, $minorAccount]);

        return $this->accessService->authorizeGuardian(
            $user,
            $minorAccount,
            $request->attributes->get('account_uuid')
        );
    }
}
