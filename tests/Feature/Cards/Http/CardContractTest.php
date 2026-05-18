<?php

declare(strict_types=1);

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Models\Cardholder;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardBillingService;
use App\Domain\CardSubscriptions\ValueObjects\BillingAttemptResult;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(Database\Seeders\CardPlanSeeder::class);

    $this->user->update([
        'kyc_status'      => 'approved',
        'kyc_approved_at' => now(),
        'transaction_pin' => '1234',
    ]);

    $this->app->instance(App\Http\Middleware\CheckKycApproved::class, new class () {
        public function handle($request, $next)
        {
        return $next($request);
        }
    });

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

    $plan = CardPlan::where('code', 'VIRTUAL_LITE')->firstOrFail();

    $this->cardholder = Cardholder::create([
        'user_id'    => $this->user->id,
        'first_name' => $this->user->name,
        'last_name'  => 'Contract',
        'kyc_status' => 'verified',
    ]);

    $this->subscription = CardSubscription::create([
        'subscriber_user_id'   => $this->user->id,
        'payer_user_id'        => $this->user->id,
        'card_plan_id'         => $plan->id,
        'status'               => CardSubscriptionStatus::Active,
        'current_period_start' => now(),
        'current_period_end'   => now()->addMonth(),
        'next_billing_date'    => now()->addMonth(),
    ]);
});

it('mobile config exposes card feature flags in the standard envelope', function (): void {
    config()->set('mobile.features.cards.monetisation_enabled', true);

    $this->getJson('/api/mobile/config')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonStructure([
            'data' => [
                'features' => [
                    'cards' => [
                        'monetisation_enabled',
                        'subscriptions_enabled',
                        'virtual_card_lite_enabled',
                        'virtual_card_plus_enabled',
                        'physical_card_enabled',
                    ],
                ],
            ],
        ])
        ->assertJsonPath('data.features.cards.monetisation_enabled', true);
});

it('returns cards in the documented mobile envelope and field vocabulary', function (): void {
    $card = Card::create([
        'user_id'               => $this->user->id,
        'cardholder_id'         => $this->cardholder->id,
        'card_subscription_id'  => $this->subscription->id,
        'kind'                  => 'virtual',
        'status'                => 'active',
        'issuer'                => 'mock',
        'issuer_card_token'     => 'contract_card_token',
        'last4'                 => '4242',
        'network'               => 'visa',
        'currency'              => 'SZL',
        'label'                 => 'Online shopping',
        'per_transaction_limit' => '1000.00',
        'daily_limit'           => '2000.00',
        'monthly_limit'         => '5000.00',
        'online_enabled'        => true,
        'international_enabled' => false,
        'atm_enabled'           => false,
        'contactless_enabled'   => false,
        'blocked_mcc_groups'    => ['gambling'],
    ]);

    $response = $this->actingAsWithScopes($this->user)
        ->withHeader('X-Account-Id', $this->account->uuid)
        ->getJson('/api/v1/cards');

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.cards.0.id', $card->id)
        ->assertJsonPath('data.cards.0.card_type', 'virtual')
        ->assertJsonPath('data.cards.0.card_brand', 'visa')
        ->assertJsonPath('data.cards.0.nickname', 'Online shopping')
        ->assertJsonPath('data.cards.0.controls.blocked_mcc_groups.0', 'gambling')
        ->assertJsonMissingPath('data.cards.0.network')
        ->assertJsonMissingPath('data.cards.0.label')
        ->assertJsonMissingPath('data.cards.0.mask');
});

it('returns current subscription in the documented envelope', function (): void {
    $response = $this->actingAsWithScopes($this->user)
        ->withHeader('X-Account-Id', $this->account->uuid)
        ->getJson('/api/v1/card-subscriptions/me');

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.subscription.id', $this->subscription->id)
        ->assertJsonPath('data.subscription.plan_code', 'VIRTUAL_LITE');
});

it('has one mobile-facing route owner per cards method and uri', function (): void {
    $duplicates = collect(app('router')->getRoutes())
        ->map(fn ($route) => [
            'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
            'uri'     => $route->uri(),
        ])
        ->flatMap(fn (array $route) => collect($route['methods'])->map(
            fn (string $method) => "{$method} " . preg_replace('/\{[^}]+\}/', '{param}', $route['uri'])
        ))
        ->filter(fn (string $key) => str_starts_with($key, 'GET api/v1/cards')
            || str_starts_with($key, 'POST api/v1/cards')
            || str_starts_with($key, 'PATCH api/v1/cards'))
        ->countBy()
        ->filter(fn (int $count) => $count > 1);

    expect($duplicates->all())->toBe([]);
});

it('requires idempotency keys for card product mutations', function (): void {
    $this->actingAsWithScopes($this->user)
        ->withHeader('X-Account-Id', $this->account->uuid)
        ->postJson('/api/v1/cards/virtual', [
            'nickname'  => 'No key',
            'lifecycle' => 'standard',
            'controls'  => [
                'per_transaction_limit' => 1000,
                'daily_limit'           => 2000,
                'monthly_limit'         => 5000,
                'online_enabled'        => true,
                'international_enabled' => false,
            ],
        ])
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('data.code', 'IDEMPOTENCY_KEY_REQUIRED');
});

it('rejects same idempotency key reused for a different card operation', function (): void {
    $key = (string) Str::uuid();

    $this->actingAsWithScopes($this->user)
        ->withHeaders([
            'X-Account-Id'    => $this->account->uuid,
            'Idempotency-Key' => $key,
        ])
        ->postJson('/api/v1/card-subscriptions/upgrade', [
            'plan_code' => 'VIRTUAL_PLUS',
        ])
        ->assertOk()
        ->assertJsonPath('data.next_step', 'pin');

    $this->actingAsWithScopes($this->user)
        ->withHeaders([
            'X-Account-Id'    => $this->account->uuid,
            'Idempotency-Key' => $key,
        ])
        ->postJson('/api/v1/cards/virtual', [
            'nickname'  => 'Different operation',
            'lifecycle' => 'standard',
            'controls'  => [
                'per_transaction_limit' => 1000,
                'daily_limit'           => 2000,
                'monthly_limit'         => 5000,
                'online_enabled'        => true,
                'international_enabled' => false,
            ],
        ])
        ->assertStatus(409)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('data.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
});

it('keeps the wallet account usable when card billing marks subscription past due', function (): void {
    $this->account->forceFill([
        'frozen' => false,
    ])->save();

    app(CardBillingService::class)->handleFailedPayment(
        $this->subscription,
        BillingAttemptResult::failed('INSUFFICIENT_FUNDS'),
    );

    $this->subscription->refresh();
    $this->account->refresh();

    expect($this->subscription->status)->toBe(CardSubscriptionStatus::PastDue);
    expect($this->account->frozen)->toBeFalse();
});
