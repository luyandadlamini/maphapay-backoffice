<?php

declare(strict_types=1);

use App\Models\User;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;

use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardIssuance\Models\Card;

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
            $request->attributes->set('account_type', 'business');
            return $next($request);
        }
    });

    $plan = CardPlan::where('code', 'VIRTUAL_LITE')->first();
    if (!$plan) {
        throw new \Exception("VIRTUAL_LITE plan not found after seeding.");
    }

    // Create a cardholder
    $this->cardholder = \App\Domain\CardIssuance\Models\Cardholder::create([
        'user_id' => $this->user->id,
        'first_name' => $this->user->name,
        'last_name' => 'Test',
        'kyc_status' => 'verified',
    ]);

    // Create an active subscription for the user
    $this->subscription = CardSubscription::create([
        'subscriber_user_id' => $this->user->id,
        'payer_user_id' => $this->user->id,
        'card_plan_id' => $plan->id,
        'status' => CardSubscriptionStatus::Active,
        'current_period_start' => now(),
    ]);
});

describe('POST /api/v1/cards/virtual', function () {
    it('creates a virtual card successfully', function () {
        $response = $this->actingAsWithScopes($this->user)
            ->postJson('/api/v1/cards/virtual', [
                'nickname' => 'Personal Spending',
                'lifecycle' => 'standard',
                'controls' => [
                    'per_transaction_limit' => 1000,
                    'daily_limit' => 5000,
                    'monthly_limit' => 20000,
                    'online_enabled' => true,
                    'international_enabled' => false,
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'mask',
                'last4',
                'network',
                'status',
                'label',
                'currency',
            ],
        ]);
    });

    it('rejects virtual card creation if plan limit is reached', function () {
        // VIRTUAL_LITE has a limit of 1 virtual card
        \App\Domain\CardIssuance\Models\Card::create([
            'user_id' => $this->user->id,
            'cardholder_id' => $this->cardholder->id,
            'card_subscription_id' => $this->subscription->id,
            'kind' => 'virtual',
            'status' => 'active',
            'issuer' => 'mock',
            'issuer_card_token' => 'test_token_limit',
            'last4' => '8888',
            'network' => 'mastercard',
            'currency' => 'ZAR',
        ]);

        $response = $this->actingAsWithScopes($this->user)
            ->postJson('/api/v1/cards/virtual', [
                'nickname' => 'Second Card',
                'lifecycle' => 'standard',
                'controls' => [
                    'per_transaction_limit' => 1000,
                    'daily_limit' => 5000,
                    'monthly_limit' => 20000,
                    'online_enabled' => true,
                    'international_enabled' => false,
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'VIRTUAL_CARD_LIMIT_REACHED');
    });
});

describe('GET /api/v1/cards', function () {
    it('lists user cards', function () {
        $card = \App\Domain\CardIssuance\Models\Card::create([
            'user_id' => $this->user->id,
            'cardholder_id' => $this->cardholder->id,
            'card_subscription_id' => $this->subscription->id,
            'kind' => 'virtual',
            'status' => 'active',
            'issuer' => 'mock',
            'issuer_card_token' => 'test_token_list',
            'last4' => '9999',
            'network' => 'mastercard',
            'currency' => 'ZAR',
            'label' => 'List Test',
        ]);

        $this->actingAsWithScopes($this->user);

        $response = $this->withHeader('X-Account-Id', $this->account->uuid)
            ->getJson('/api/v1/cards');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        expect($response->json('data.0.id'))->toBe($card->id);
    });
});
