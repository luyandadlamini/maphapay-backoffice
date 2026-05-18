<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\CardSubscriptions\Http\Concerns\RespondsWithCardApiEnvelope;
use App\Domain\CardSubscriptions\Http\Requests\CreateCardSubscriptionRequest;
use App\Domain\CardSubscriptions\Http\Requests\UpdateCardSubscriptionRequest;
use App\Domain\CardSubscriptions\Http\Resources\CardSubscriptionPlanResource;
use App\Domain\CardSubscriptions\Http\Resources\CardSubscriptionResource;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardBillingService;
use App\Domain\CardSubscriptions\Services\CardProductAuthorizationCoordinator;
use App\Domain\CardSubscriptions\Services\CardSubscriptionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardSubscriptionController extends Controller
{
    use RespondsWithCardApiEnvelope;

    public function __construct(
        private readonly CardSubscriptionService $subscriptionService,
        private readonly CardBillingService $billingService,
        private readonly CardProductAuthorizationCoordinator $cardProductAuthorization,
    ) {
    }

    public function plans(Request $request): JsonResponse
    {
        $accountType = $request->attributes->get('account_type', 'personal');

        $plans = CardPlan::where('active', true)
            ->when($accountType === 'minor', function ($query) {
                $query->where('eligibility', 'minor');
            }, function ($query) {
                $query->where('eligibility', 'adult');
            })
            ->get();

        return $this->cardSuccess('card_subscription_plans', [
            'plans' => CardSubscriptionPlanResource::collection($plans)->resolve($request),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $subscription = $this->subscriptionService->getCurrent($user);

        return $this->cardSuccess('card_subscription', [
            'subscription' => $subscription
                ? (new CardSubscriptionResource($subscription->loadMissing('plan')))->resolve($request)
                : null,
        ]);
    }

    public function store(CreateCardSubscriptionRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        $payload = [
            'plan_code' => $request->validated('plan_code'),
        ];
        $subscriberUuid = $request->validated('subscriber_user_id');
        if (is_string($subscriberUuid) && $subscriberUuid !== '') {
            $payload['subscriber_user_id'] = $subscriberUuid;
        }

        return $this->cardProductAuthorization->begin($user, 'subscribe', $payload, $idempotencyKey);
    }

    public function show(Request $request, string $subscriptionId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $subscription = CardSubscription::where('id', $subscriptionId)
            ->where('subscriber_user_id', $user->id)
            ->with('plan')
            ->firstOrFail();

        return $this->cardSuccess('card_subscription', [
            'subscription' => (new CardSubscriptionResource($subscription))->resolve($request),
        ]);
    }

    public function update(UpdateCardSubscriptionRequest $request, string $subscriptionId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $newPlanCode = $request->validated('plan_code');

        CardSubscription::where('id', $subscriptionId)
            ->where('subscriber_user_id', $user->id)
            ->firstOrFail();

        $subscription = $this->subscriptionService->upgrade($user, $newPlanCode);

        return $this->cardSuccess('card_subscription', [
            'subscription' => (new CardSubscriptionResource($subscription->load('plan')))->resolve($request),
        ]);
    }

    public function upgrade(UpdateCardSubscriptionRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'upgrade', [
            'plan_code' => $request->validated('plan_code'),
        ], $idempotencyKey);
    }

    public function downgrade(UpdateCardSubscriptionRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'downgrade', [
            'plan_code' => $request->validated('plan_code'),
            'force'     => (bool) ($request->validated('force') ?? false),
        ], $idempotencyKey);
    }

    public function cancel(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'cancel_subscription', [], $idempotencyKey);
    }

    public function retryPayment(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $subscription = $this->subscriptionService->getCurrent($user);

        if ($subscription === null) {
            return response()->json([
                'status'  => 'error',
                'remark'  => 'card_subscription_retry_unavailable',
                'message' => ['No subscription found to retry billing for.'],
                'data'    => ['code' => 'NO_ACTIVE_SUBSCRIPTION'],
            ], 422);
        }

        $this->billingService->retryFailedPayment($subscription);

        return $this->cardSuccess('card_subscription_retry', [
            'subscription' => (new CardSubscriptionResource($subscription->refresh()->load('plan')))->resolve($request),
        ]);
    }
}
