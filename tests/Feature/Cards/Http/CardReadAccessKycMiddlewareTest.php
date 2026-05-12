<?php

declare(strict_types=1);

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed(Database\Seeders\CardPlanSeeder::class);

    $this->business_user->update([
        'kyc_status'      => 'pending',
        'kyc_approved_at' => null,
    ]);

    $this->app->instance(App\Http\Middleware\ResolveAccountContext::class, new class ($this->account) {
        public function __construct(private $acc)
        {
        }

        public function handle($request, $next)
        {
            $request->attributes->set('account_uuid', $this->acc->uuid);
            $request->attributes->set('account_type', 'business');

            return $next($request);
        }
    });
});

it('lets unverified users discover card plans so the mobile app can show subscription UI', function (): void {
    $this->actingAsWithScopes($this->business_user);

    $response = $this->withHeader('X-Account-Id', $this->account->uuid)
        ->getJson('/api/v1/card-subscriptions/plans');

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.plans.0.eligibility', 'adult');
});

it('returns null current subscription for unverified users instead of blocking the card home screen', function (): void {
    $this->actingAsWithScopes($this->business_user);

    $response = $this->withHeader('X-Account-Id', $this->account->uuid)
        ->getJson('/api/v1/card-subscriptions/me');

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.subscription', null);
});

it('lets unverified users list their cards read-only so mobile can render the card hub', function (): void {
    $plan = CardPlan::where('code', 'VIRTUAL_LITE')->firstOrFail();
    $cardholder = App\Domain\CardIssuance\Models\Cardholder::create([
        'user_id'    => $this->business_user->id,
        'first_name' => $this->business_user->name,
        'last_name'  => 'Test',
        'kyc_status' => 'pending',
    ]);
    $subscription = CardSubscription::create([
        'subscriber_user_id'   => $this->business_user->id,
        'payer_user_id'        => $this->business_user->id,
        'card_plan_id'         => $plan->id,
        'status'               => CardSubscriptionStatus::Active,
        'current_period_start' => now(),
    ]);
    Card::create([
        'user_id'              => $this->business_user->id,
        'cardholder_id'        => $cardholder->id,
        'card_subscription_id' => $subscription->id,
        'kind'                 => 'virtual',
        'status'               => 'active',
        'issuer'               => 'mock',
        'issuer_card_token'    => 'unverified_read_card',
        'last4'                => '4242',
        'network'              => 'mastercard',
        'currency'             => 'SZL',
    ]);

    $this->actingAsWithScopes($this->business_user);

    $response = $this->withHeader('X-Account-Id', $this->account->uuid)
        ->getJson('/api/v1/cards');

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonCount(1, 'data.cards');
});

it('still requires KYC before starting a card subscription', function (): void {
    $this->actingAsWithScopes($this->business_user);

    $response = $this->withHeaders([
        'X-Account-Id'    => $this->account->uuid,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/card-subscriptions', [
        'plan_code' => 'VIRTUAL_LITE',
    ]);

    $response->assertForbidden()
        ->assertJsonPath('error', 'kyc_required');
});
