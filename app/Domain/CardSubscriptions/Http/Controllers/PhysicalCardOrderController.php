<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\CardSubscriptions\Http\Concerns\RespondsWithCardApiEnvelope;
use App\Domain\CardSubscriptions\Http\Requests\PhysicalCardActivationRequest;
use App\Domain\CardSubscriptions\Http\Requests\PhysicalCardRequest;
use App\Domain\CardSubscriptions\Http\Resources\PhysicalCardOrderResource;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardProductAuthorizationCoordinator;
use App\Domain\CardSubscriptions\Services\CardSubscriptionService;
use App\Domain\CardSubscriptions\ValueObjects\PhysicalCardDeliveryAddress;
use App\Domain\CardSubscriptions\ValueObjects\RequestPhysicalCardInput;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhysicalCardOrderController extends Controller
{
    use RespondsWithCardApiEnvelope;

    public function __construct(
        private readonly CardProductAuthorizationCoordinator $cardProductAuthorization,
        private readonly CardSubscriptionService $subscriptionService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $subscription = $this->subscriptionService->getCurrent($user);

        if (! $subscription) {
            abort(403, 'Active subscription required.');
        }

        $orders = $subscription->orders()->latest()->get();

        return $this->cardSuccess('physical_card_orders', [
            'orders' => PhysicalCardOrderResource::collection($orders)->resolve($request),
        ]);
    }

    public function store(PhysicalCardRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $subscription = $this->subscriptionService->getCurrent($user);

        if (! $subscription) {
            abort(403, 'Active subscription required.');
        }

        $deliveryMethod = $request->validated('delivery_method');
        $addressInput = null;

        if ($deliveryMethod === 'courier') {
            $address = $request->validated('delivery_address');
            $addressInput = new PhysicalCardDeliveryAddress(
                recipientName: $user->name,
                phone: $address['phone_number'],
                addressLine1: $address['line1'],
                addressLine2: $address['line2'] ?? null,
                city: $address['city'],
                region: $address['region'] ?? 'Unknown',
                countryCode: $address['country']
            );
        }

        $input = new RequestPhysicalCardInput(
            deliveryMethod: $deliveryMethod,
            deliveryAddress: $addressInput,
            collectionPointId: $request->validated('collection_point_id')
        );

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'physical_request', [
            'physical_request' => [
                'delivery_method'  => $input->deliveryMethod,
                'delivery_address' => $deliveryMethod === 'courier'
                    ? [
                        'phone_number' => $address['phone_number'],
                        'line1'        => $address['line1'],
                        'line2'        => $address['line2'] ?? null,
                        'city'         => $address['city'],
                        'region'       => $address['region'] ?? 'Unknown',
                        'country'      => $address['country'],
                    ]
                    : null,
                'collection_point_id' => $request->validated('collection_point_id'),
            ],
        ], $idempotencyKey);
    }

    public function show(Request $request, string $orderId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $subscription = $this->subscriptionService->getCurrent($user);

        if (! $subscription) {
            abort(403, 'Active subscription required.');
        }

        $order = $subscription->orders()->where('id', $orderId)->firstOrFail();

        return $this->cardSuccess('physical_card_order', [
            'order' => (new PhysicalCardOrderResource($order))->resolve($request),
        ]);
    }

    public function activate(PhysicalCardActivationRequest $request, string $orderId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $subscription = $this->subscriptionService->getCurrent($user);
        if (! $subscription instanceof CardSubscription) {
            abort(403, 'Active subscription required.');
        }

        $subscription->orders()->where('id', $orderId)->firstOrFail();

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'physical_activate', [
            'order_id' => $orderId,
            'activate' => [
                'activation_code' => $request->validated('activation_code'),
                'pin'             => $request->validated('pin'),
            ],
        ], $idempotencyKey);
    }

    public function cancel(Request $request, string $orderId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $subscription = $this->subscriptionService->getCurrent($user);
        if (! $subscription instanceof CardSubscription) {
            abort(403, 'Active subscription required.');
        }

        $subscription->orders()->where('id', $orderId)->firstOrFail();

        $validated = $request->validate([
            'reason' => ['sometimes', 'string', 'max:500'],
        ]);

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return $this->cardProductAuthorization->begin($user, 'physical_cancel', [
            'order_id'            => $orderId,
            'cancellation_reason' => (string) ($validated['reason'] ?? 'user_requested'),
        ], $idempotencyKey);
    }
}
