<?php

declare(strict_types=1);

use App\Models\User;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use App\Domain\CardSubscriptions\Enums\PhysicalCardOrderStatus;
use App\Domain\CardSubscriptions\Enums\PhysicalCardDeliveryMethod;

beforeEach(function () {
    $this->seed(\Database\Seeders\CardPlanSeeder::class);

    $this->user->update([
        'kyc_status' => 'approved',
        'kyc_approved_at' => now(),
    ]);

    // Bypass KYC middleware
    $this->app->instance(\App\Http\Middleware\CheckKycApproved::class, new class {
        public function handle($request, $next) { return $next($request); }
    });

    // Mock account context
    $this->app->instance(\App\Http\Middleware\ResolveAccountContext::class, new class($this->account) {
        public function __construct(private $acc) {}
        public function handle($request, $next) {
            $request->attributes->set('account_uuid', $this->acc->uuid);
            return $next($request);
        }
    });

    $plan = CardPlan::where('code', 'KHULA_VIRTUAL')->first(); 
    if (!$plan) {
        $plan = CardPlan::first();
    }

    // Create a card
    $this->card = \App\Domain\CardIssuance\Models\Card::factory()->create([
        'user_id' => $this->user->id,
        'minor_account_uuid' => $this->account->uuid,
    ]);

    // Create an active subscription
    $this->subscription = CardSubscription::create([
        'subscriber_user_id' => $this->user->id,
        'payer_user_id' => $this->user->id,
        'card_plan_id' => $plan->id,
        'card_id' => $this->card->id,
        'status' => CardSubscriptionStatus::Active,
        'current_period_start' => now(),
    ]);
});

describe('GET /api/v1/cards/physical/orders', function () {
    it('lists user physical card orders', function () {
        PhysicalCardOrder::create([
            'user_id' => $this->user->id,
            'card_subscription_id' => $this->subscription->id,
            'card_id' => $this->card->id,
            'order_status' => PhysicalCardOrderStatus::Requested,
            'delivery_method' => PhysicalCardDeliveryMethod::Courier,
            'delivery_address' => ['line1' => '123 Test St'],
            'requested_at' => now(),
        ]);

        $response = $this->actingAsWithScopes($this->user)
            ->getJson('/api/v1/cards/physical/orders');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    });
});

describe('POST /api/v1/cards/physical/request', function () {
    it('requests a physical card successfully', function () {
        $response = $this->actingAsWithScopes($this->user)
            ->postJson('/api/v1/cards/physical/request', [
                'delivery_method' => 'courier',
                'delivery_address' => [
                    'line1' => '123 Test St',
                    'city' => 'Mbabane',
                    'country' => 'SZ',
                    'phone_number' => '+26876000000',
                ],
            ]);

        // Expected to fail until PhysicalCardOrderService is implemented
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'status',
                'delivery_method',
                'requested_at',
            ],
        ]);
    });
});

describe('POST /api/v1/cards/physical/orders/{id}/activate', function () {
    it('activates a physical card successfully', function () {
        $order = PhysicalCardOrder::create([
            'user_id' => $this->user->id,
            'card_subscription_id' => $this->subscription->id,
            'card_id' => $this->card->id,
            'order_status' => PhysicalCardOrderStatus::Dispatched,
            'delivery_method' => PhysicalCardDeliveryMethod::Courier,
            'delivery_address' => ['line1' => '123 Test St'],
            'requested_at' => now(),
        ]);

        $response = $this->actingAsWithScopes($this->user)
            ->withHeader('X-Mobile-Trust', 'true') // Assume this is the step-up header
            ->postJson("/api/v1/cards/physical/orders/{$order->id}/activate", [
                'activation_code' => '123456',
                'pin' => '1234',
            ]);

        // Expected to fail until PhysicalCardOrderService is implemented
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'activated');
    });
});
