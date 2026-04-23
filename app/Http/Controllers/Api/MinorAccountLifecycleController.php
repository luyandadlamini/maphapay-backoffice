<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorAccountLifecycleException;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Domain\Account\Services\MinorAccountLifecycleService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MinorAccountLifecycleController extends Controller
{
    public function __construct(
        private readonly MinorAccountAccessService $accessService,
        private readonly MinorAccountLifecycleService $lifecycleService,
    ) {
    }

    public function show(Request $request, string $minorAccountUuid): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $minorAccount = Account::query()->where('uuid', $minorAccountUuid)->firstOrFail();
        $this->authorizeRead($actor, $minorAccount);

        return response()->json([
            'success' => true,
            'data' => $this->lifecycleService->lifecycleSnapshot($minorAccount),
        ]);
    }

    public function transitions(Request $request, string $minorAccountUuid): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $minorAccount = Account::query()->where('uuid', $minorAccountUuid)->firstOrFail();
        $this->authorizeRead($actor, $minorAccount);

        $items = $this->lifecycleService->transitionQueryForAccount($minorAccount)
            ->paginate(15)
            ->through(fn ($transition): array => [
                'transition_uuid' => $transition->id,
                'transition_type' => $transition->transition_type,
                'state' => $transition->state,
                'effective_at' => $transition->effective_at?->toIso8601String(),
                'executed_at' => $transition->executed_at?->toIso8601String(),
                'blocked_reason_code' => $transition->blocked_reason_code,
                'metadata' => $transition->metadata,
            ]);

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function reviewActions(Request $request, string $minorAccountUuid): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $minorAccount = Account::query()->where('uuid', $minorAccountUuid)->firstOrFail();

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:rerun_evaluation,acknowledge_exception,resolve_exception,mark_manual_verification_complete'],
            'exception_uuid' => ['nullable', 'string', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $action = (string) $validated['action'];

        if ($action === 'rerun_evaluation') {
            $this->authorizeMutation($actor, $minorAccount, true);
            $result = $this->lifecycleService->evaluateAccount($minorAccount, 'api');

            return response()->json([
                'success' => true,
                'data' => [
                    'transition_state' => $minorAccount->fresh()?->minor_transition_state,
                    'result' => $result,
                ],
            ], 202);
        }

        $this->authorizeMutation($actor, $minorAccount, false);

        $exception = MinorAccountLifecycleException::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->where('id', $validated['exception_uuid'] ?? '')
            ->firstOrFail();

        $note = (string) ($validated['note'] ?? '');

        if ($action === 'acknowledge_exception' || $action === 'mark_manual_verification_complete') {
            if ($note === '') {
                return response()->json([
                    'message' => 'A note is required for this action.',
                ], 422);
            }

            $this->lifecycleService->acknowledgeException($exception, $actor, $note);

            return response()->json([
                'success' => true,
                'data' => [
                    'exception_uuid' => $exception->id,
                    'status' => $exception->fresh()?->status,
                ],
            ], 202);
        }

        if ($note === '') {
            return response()->json([
                'message' => 'A note is required for this action.',
            ], 422);
        }

        $resolved = $this->lifecycleService->resolveException($exception, $actor, $note, 'api');

        return response()->json([
            'success' => true,
            'data' => [
                'exception_uuid' => $resolved->id,
                'status' => $resolved->status,
            ],
        ], 202);
    }

    private function authorizeRead(User $actor, Account $minorAccount): void
    {
        if ($actor->can('view-transactions')) {
            return;
        }

        $this->accessService->authorizeView($actor, $minorAccount);
    }

    private function authorizeMutation(User $actor, Account $minorAccount, bool $allowGuardian): void
    {
        if ($actor->can('view-transactions')) {
            return;
        }

        if (! $allowGuardian) {
            abort(403);
        }

        $this->accessService->authorizeGuardian($actor, $minorAccount);
    }

    private function authenticatedUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user() ?? abort(401);

        return $user;
    }
}
