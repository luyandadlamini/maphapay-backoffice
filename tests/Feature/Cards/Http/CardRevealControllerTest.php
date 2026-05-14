<?php

declare(strict_types=1);

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Models\User;

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

    $this->cardholder = \App\Domain\CardIssuance\Models\Cardholder::create([
        'user_id' => $this->user->id,
        'first_name' => $this->user->name,
        'last_name' => 'Test',
        'kyc_status' => 'verified',
    ]);

    $this->subscription = CardSubscription::create([
        'subscriber_user_id' => $this->user->id,
        'payer_user_id' => $this->user->id,
        'card_plan_id' => $plan->id,
        'status' => CardSubscriptionStatus::Active,
        'current_period_start' => now(),
    ]);

    $this->card = Card::create([
        'user_id' => $this->user->id,
        'cardholder_id' => $this->cardholder->id,
        'card_subscription_id' => $this->subscription->id,
        'kind' => 'virtual',
        'status' => 'active',
        'issuer' => 'mock',
        'issuer_card_token' => 'test_token',
        'last4' => '1234',
        'network' => 'mastercard',
        'currency' => 'ZAR',
    ]);
});

describe('GET /api/v1/cards/{id}/reveal', function () {
    it('mandates step-up authentication via X-Mobile-Trust header', function () {
        $response = $this->actingAsWithScopes($this->user)
            ->withHeader('X-Account-Id', $this->account->uuid)
            ->getJson("/api/v1/cards/{$this->card->id}/reveal");

        $response->assertStatus(403);
    });

    it('returns reveal URL and ensures audit log is written when authorized', function () {
        // Assert audit log was recorded
        // In the test database, we should check if the card_audit_logs table has an entry
        $this->actingAsWithScopes($this->user);

        $response = $this->withHeaders([
            'X-Account-Id' => $this->account->uuid,
            'X-Mobile-Trust' => 'valid-trust-token',
        ])->getJson("/api/v1/cards/{$this->card->id}/reveal");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'reveal_url',
                'expires_at',
                'ttl_seconds',
            ],
        ]);

        $this->assertDatabaseHas('card_audit_logs', [
            'entity_type' => Card::class,
            'entity_id' => $this->card->id,
            'action' => 'reveal_requested',
        ]);
    });

    it('returns 404 when another user requests reveal for a card they do not own', function () {
        $other = User::factory()->create([
            'kyc_status'      => 'approved',
            'kyc_approved_at' => now(),
        ]);

        $response = $this->actingAsWithScopes($other)
            ->withHeaders([
                'X-Account-Id'   => $this->account->uuid,
                'X-Mobile-Trust' => 'valid-trust-token',
            ])
            ->getJson("/api/v1/cards/{$this->card->id}/reveal");

        $response->assertNotFound();
    });
});
