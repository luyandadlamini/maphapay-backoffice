<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Controllers;

use App\Domain\CardSubscriptions\Http\Requests\PhysicalCardActivationRequest;
use App\Domain\CardSubscriptions\Http\Requests\PhysicalCardRequest;
use App\Domain\CardSubscriptions\Http\Resources\PhysicalCardOrderResource;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardSubscriptionService;
use App\Domain\CardSubscriptions\Services\PhysicalCardOrderService;
use App\Domain\CardSubscriptions\ValueObjects\ActivateInput;
use App\Domain\CardSubscriptions\ValueObjects\PhysicalCardDeliveryAddress;
use App\Domain\CardSubscriptions\ValueObjects\RequestPhysicalCardInput;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PhysicalCardOrderController extends Controller
{
    public function __construct(
        private readonly PhysicalCardOrderService $orderService,
        private readonly CardSubscriptionService $subscriptionService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $subscription = $this->subscriptionService->getCurrent($user);

        if (!$subscription) {
            abort(403, 'Active subscription required.');
        }

        $orders = $subscription->orders()->latest()->get();

        return PhysicalCardOrderResource::collection($orders);
    }

    public function store(PhysicalCardRequest $request): PhysicalCardOrderResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $subscription = $this->subscriptionService->getCurrent($user);

        if (!$subscription) {
            abort(403, 'Active subscription required.');
        }

        $deliveryMethod = $request->validated('delivery_method');
        $addressInput = null;

        if ($deliveryMethod === 'courier') {
            $address = $request->validated('delivery_address');
            $addressInput = new PhysicalCardDeliveryAddress(
                recipientName: $user->name, // Using user name since it might not be in payload
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

        $order = $this->orderService->request($user, $subscription, $input);

        return new PhysicalCardOrderResource($order);
    }

    public function show(Request $request, string $orderId): PhysicalCardOrderResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $subscription = $this->subscriptionService->getCurrent($user);

        if (!$subscription) {
            abort(403, 'Active subscription required.');
        }

        $order = $subscription->orders()->where('id', $orderId)->firstOrFail();

        return new PhysicalCardOrderResource($order);
    }

    public function activate(PhysicalCardActivationRequest $request, string $orderId): PhysicalCardOrderResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        
        $subscription = $this->subscriptionService->getCurrent($user);
        if (!$subscription) {
            abort(403, 'Active subscription required.');
        }

        $order = $subscription->orders()->where('id', $orderId)->firstOrFail();

        $input = new ActivateInput(
            activationCode: $request->validated('activation_code'),
            metadata: ['pin' => $request->validated('pin')]
        );

        $this->orderService->activate(
            user: $user,
            order: $order,
            input: $input
        );
        
        $order->refresh();

        return new PhysicalCardOrderResource($order);
    }
}
