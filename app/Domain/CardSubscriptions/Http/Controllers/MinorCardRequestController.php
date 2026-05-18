<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorCardRequest;
use App\Domain\Account\Services\MinorCardRequestService;
use App\Domain\CardSubscriptions\Http\Requests\MinorCardDenyRequest;
use App\Domain\CardSubscriptions\Http\Resources\MinorCardRequestMobileResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class MinorCardRequestController extends Controller
{
    public function __construct(
        private readonly MinorCardRequestService $minorCardRequestService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'minor_account_uuid' => ['nullable', 'uuid', 'exists:accounts,uuid'],
            'status'             => ['sometimes', 'string', Rule::in([MinorCardConstants::STATUS_PENDING_APPROVAL])],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $visibleMinorAccountUuids = $this->visibleMinorAccountUuidsForUser($user->uuid);

        if (isset($validated['minor_account_uuid'])) {
            $filter = $validated['minor_account_uuid'];
            if (! $visibleMinorAccountUuids->contains($filter)) {
                abort(403);
            }
            $visibleMinorAccountUuids = collect([$filter]);
        }

        if ($visibleMinorAccountUuids->isEmpty()) {
            return response()->json([
                'status' => 'success',
                'remark' => 'minor_card_requests',
                'data'   => ['requests' => []],
            ]);
        }

        $status = $validated['status'] ?? MinorCardConstants::STATUS_PENDING_APPROVAL;

        $rows = MinorCardRequest::query()
            ->with('minorAccount')
            ->whereIn('minor_account_uuid', $visibleMinorAccountUuids)
            ->where('status', $status)
            ->orderByDesc('created_at')
            ->get();

        $requests = $rows->map(static fn (MinorCardRequest $r) => MinorCardRequestMobileResource::toArray($r))->values()->all();

        return response()->json([
            'status' => 'success',
            'remark' => 'minor_card_requests',
            'data'   => ['requests' => $requests],
        ]);
    }

    /**
     * @return Collection<int, string>
     */
    private function visibleMinorAccountUuidsForUser(string $userUuid): Collection
    {
        $asGuardian = AccountMembership::query()
            ->forUser($userUuid)
            ->active()
            ->whereIn('role', ['guardian', 'co_guardian'])
            ->pluck('account_uuid');

        $asMinorSelf = Account::query()
            ->where('user_uuid', $userUuid)
            ->where('type', 'minor')
            ->pluck('uuid');

        return $asGuardian->merge($asMinorSelf)->unique()->values();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'minor_account_uuid'                  => ['required_without:self_request', 'uuid', 'exists:accounts,uuid'],
            'self_request'                        => ['sometimes', 'boolean'],
            'network'                             => ['sometimes', 'in:visa,mastercard'],
            'requested_limits'                    => ['nullable', 'array'],
            'requested_limits.daily'              => ['nullable', 'numeric', 'min:0'],
            'requested_limits.monthly'            => ['nullable', 'numeric', 'min:0'],
            'requested_limits.single_transaction' => ['nullable', 'numeric', 'min:0'],
            'intent'                              => ['nullable', 'array'],
            'intent.request_type'                 => ['required_with:intent', 'string', Rule::in(['subscribe', 'change_plan', 'create_card', 'replace_card', 'unfreeze_card'])],
            'intent.payload'                      => ['nullable', 'array'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $minorUuid = $validated['minor_account_uuid'] ?? null;
        $minor = $minorUuid
            ? Account::where('uuid', $minorUuid)->firstOrFail()
            : Account::where('user_uuid', $user->uuid)->where('type', 'minor')->firstOrFail();

        $this->authorize('request', [MinorCardRequest::class, $minor]);

        $limits = $validated['requested_limits'] ?? null;
        if (is_array($limits)) {
            $limits = array_filter([
                'daily'              => isset($limits['daily']) ? (string) $limits['daily'] : null,
                'monthly'            => isset($limits['monthly']) ? (string) $limits['monthly'] : null,
                'single_transaction' => isset($limits['single_transaction']) ? (string) $limits['single_transaction'] : null,
            ], static fn ($v) => $v !== null && $v !== '');
        } else {
            $limits = null;
        }

        $intentPayload = isset($validated['intent'])
            ? [
                'request_type' => $validated['intent']['request_type'],
                'payload'      => $validated['intent']['payload'] ?? [],
            ]
            : null;

        $created = $this->minorCardRequestService->createRequest(
            $user,
            $minor,
            $validated['network'] ?? 'visa',
            $limits === [] ? null : $limits,
            $intentPayload,
        );

        return response()->json([
            'status' => 'success',
            'remark' => 'minor_card_request',
            'data'   => ['request' => MinorCardRequestMobileResource::toArray($created->loadMissing('minorAccount'))],
        ], 201);
    }

    public function approve(Request $request, string $requestId): JsonResponse
    {
        /** @var \App\Models\User $guardian */
        $guardian = $request->user();

        $minorRequest = MinorCardRequest::where('id', $requestId)->with('minorAccount')->firstOrFail();

        $this->authorize('approve', $minorRequest);

        $request->validate([
            'approval_note' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->minorCardRequestService->approve($guardian, $minorRequest);

        return response()->json([
            'status' => 'success',
            'remark' => 'minor_card_request',
            'data'   => ['request' => MinorCardRequestMobileResource::toArray($updated->loadMissing('minorAccount'))],
        ]);
    }

    public function deny(MinorCardDenyRequest $request, string $requestId): JsonResponse
    {
        /** @var \App\Models\User $guardian */
        $guardian = $request->user();

        $minorRequest = MinorCardRequest::where('id', $requestId)->with('minorAccount')->firstOrFail();

        $this->authorize('deny', $minorRequest);

        $updated = $this->minorCardRequestService->deny(
            guardian: $guardian,
            request: $minorRequest,
            reason: $request->validated('denial_reason')
        );

        return response()->json([
            'status' => 'success',
            'remark' => 'minor_card_request',
            'data'   => ['request' => MinorCardRequestMobileResource::toArray($updated->loadMissing('minorAccount'))],
        ]);
    }
}
