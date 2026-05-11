<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\Account\Models\MinorCardRequest;
use App\Domain\CardSubscriptions\Http\Requests\MinorCardDenyRequest;
use App\Domain\CardSubscriptions\Services\MinorCardSubscriptionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MinorCardRequestController extends Controller
{
    public function __construct(
        private readonly MinorCardSubscriptionService $minorService
    ) {}

    public function approve(Request $request, string $requestId): JsonResponse
    {
        /** @var \App\Models\User $guardian */
        $guardian = $request->user();
        
        $minorRequest = MinorCardRequest::where('id', $requestId)->firstOrFail();

        $this->minorService->approve($guardian, $minorRequest);

        return response()->json(['message' => 'Minor card request approved.']);
    }

    public function deny(MinorCardDenyRequest $request, string $requestId): JsonResponse
    {
        /** @var \App\Models\User $guardian */
        $guardian = $request->user();
        
        $minorRequest = MinorCardRequest::where('id', $requestId)->firstOrFail();

        $this->minorService->deny(
            guardian: $guardian,
            request: $minorRequest,
            reason: $request->validated('denial_reason')
        );

        return response()->json(['message' => 'Minor card request denied.']);
    }
}
