<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\CardSubscriptions\Http\Requests\CreateCardSubscriptionRequest;
use App\Domain\CardSubscriptions\Http\Requests\UpdateCardSubscriptionRequest;
use App\Domain\CardSubscriptions\Http\Resources\CardSubscriptionPlanResource;
use App\Domain\CardSubscriptions\Http\Resources\CardSubscriptionResource;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardSubscriptionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CardSubscriptionController extends Controller
{
    public function __construct(
        private readonly CardSubscriptionService $subscriptionService
    ) {}

    public function plans(Request $request): AnonymousResourceCollection
    {
        $accountType = $request->attributes->get('account_type', 'personal');
        
        $plans = CardPlan::where('active', true)
            ->when($accountType === 'minor', function ($query) {
                $query->where('eligibility', 'minor');
            }, function ($query) {
                $query->where('eligibility', 'adult');
            })
            ->get();
            
        return CardSubscriptionPlanResource::collection($plans);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $subscription = $this->subscriptionService->getCurrent($user);
            
        return response()->json([
            'data' => $subscription ? new CardSubscriptionResource($subscription) : null
        ]);
    }

    public function store(CreateCardSubscriptionRequest $request): CardSubscriptionResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        
        $subscription = $this->subscriptionService->subscribe(
            subscriber: $user,
            planCode: $request->validated('plan_code')
        );

        return new CardSubscriptionResource($subscription->load('plan'));
    }

    public function show(Request $request, string $subscriptionId): CardSubscriptionResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $subscription = CardSubscription::where('id', $subscriptionId)
            ->where('subscriber_user_id', $user->id)
            ->with('plan')
            ->firstOrFail();

        return new CardSubscriptionResource($subscription);
    }

    public function update(UpdateCardSubscriptionRequest $request, string $subscriptionId): CardSubscriptionResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $newPlanCode = $request->validated('plan_code');
        
        CardSubscription::where('id', $subscriptionId)
            ->where('subscriber_user_id', $user->id)
            ->firstOrFail();
            
        $subscription = $this->subscriptionService->upgrade($user, $newPlanCode);
        
        return new CardSubscriptionResource($subscription->load('plan'));
    }

    public function cancel(Request $request, string $subscriptionId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        
        CardSubscription::where('id', $subscriptionId)
            ->where('subscriber_user_id', $user->id)
            ->firstOrFail();
            
        $this->subscriptionService->cancel($user);

        return response()->json(['message' => 'Subscription cancelled successfully']);
    }
}
