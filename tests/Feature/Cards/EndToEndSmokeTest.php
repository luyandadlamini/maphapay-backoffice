<?php

declare(strict_types=1);

use App\Domain\Account\Models\AccountBalance;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\CardTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 10 — condensed lifecycle smoke (HTTP + webhooks) on the default test DB.
 *
 * Full processor wallet hold/settlement wiring is still evolving; this test proves
 * the primary API surface and webhook ingress stay coherent end-to-end.
 */
beforeEach(function (): void {
    config(['maphapay_migration.enable_verification' => true]);

    $this->seed(\Database\Seeders\CardPlanSeeder::class);

    $this->business_user->update([
        'kyc_status'      => 'approved',
        'kyc_approved_at' => now(),
        'transaction_pin' => '1234',
    ]);

    $this->app->instance(\App\Http\Middleware\CheckKycApproved::class, new class {
        public function handle($request, $next)
        {
            return $next($request);
        }
    });

    $this->app->instance(\App\Http\Middleware\ResolveAccountContext::class, new class($this->account) {
        public function __construct(private $acc) {}

        public function handle($request, $next)
        {
            $request->attributes->set('account_uuid', $this->acc->uuid);
            $request->attributes->set('account_type', 'business');

            return $next($request);
        }
    });

    DB::table('assets')->insertOrIgnore([
        'code'           => 'SZL',
        'name'           => 'Swazi Lilangeni',
        'type'           => 'fiat',
        'precision'      => 2,
        'is_active'      => true,
        'is_basket'      => false,
        'is_tradeable'   => false,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    AccountBalance::updateOrCreate(
        ['account_uuid' => $this->account->uuid, 'asset_code' => 'SZL'],
        ['balance' => 500_000],
    );
});

it('adult subscribe → virtual card → webhooks → transactions list → reveal → cancel', function (): void {
    $user = $this->business_user;

    $subscribeInit = $this->actingAsWithScopes($user)
        ->withHeaders([
            'X-Account-Id'    => $this->account->uuid,
            'Idempotency-Key' => (string) Str::uuid(),
        ])
        ->postJson('/api/v1/card-subscriptions', [
            'plan_code' => 'VIRTUAL_LITE',
        ]);

    $subscribeInit->assertStatus(200)->assertJsonPath('data.next_step', 'pin');

    $this->actingAsWithScopes($user)
        ->postJson('/api/verification-process/verify/pin', [
            'trx'    => $subscribeInit->json('data.trx'),
            'pin'    => '1234',
            'remark' => 'card_product',
        ])->assertOk();

    $me = $this->actingAsWithScopes($user)
        ->withHeader('X-Account-Id', $this->account->uuid)
        ->getJson('/api/v1/card-subscriptions/me');

    $me->assertOk();
    expect($me->json('data.plan.code'))->toBe('VIRTUAL_LITE');

    $virtualInit = $this->actingAsWithScopes($user)
        ->withHeaders([
            'X-Account-Id'    => $this->account->uuid,
            'Idempotency-Key' => (string) Str::uuid(),
        ])
        ->postJson('/api/v1/cards/virtual', [
            'nickname'  => 'Smoke wallet',
            'lifecycle' => 'standard',
            'controls'  => [
                'per_transaction_limit' => 1000,
                'daily_limit'           => 5000,
                'monthly_limit'         => 20000,
                'online_enabled'        => true,
                'international_enabled' => false,
            ],
        ]);

    $virtualInit->assertStatus(200)->assertJsonPath('data.next_step', 'pin');

    $cardVerified = $this->actingAsWithScopes($user)
        ->postJson('/api/verification-process/verify/pin', [
            'trx'    => $virtualInit->json('data.trx'),
            'pin'    => '1234',
            'remark' => 'card_product',
        ]);

    $cardVerified->assertOk();
    $cardId = $cardVerified->json('data.card.id');

    $card = Card::query()->whereKey($cardId)->firstOrFail();
    $issuerToken = (string) $card->issuer_card_token;

    $authPayload = [
        'event_id'          => 'evt_smoke_' . uniqid(),
        'type'              => 'authorisation',
        'card_token'        => $issuerToken,
        'authorization_id'  => 'auth_smoke_' . uniqid(),
        'amount'            => 5000,
        'currency'          => 'ZAR',
        'merchant_name'     => 'Smoke Merchant',
        'merchant_category' => 'Retail',
    ];
    $authBody = json_encode($authPayload);
    $authSig  = hash_hmac('sha256', $authBody, 'demo_webhook_secret');

    $this->call(
        'POST',
        '/api/webhooks/cards/demo/authorisation',
        [],
        [],
        [],
        ['HTTP_X_WEBHOOK_SIGNATURE' => $authSig, 'CONTENT_TYPE' => 'application/json'],
        $authBody,
    )->assertStatus(200);

    $processorTxnId = 'txn_smoke_' . uniqid();

    CardTransaction::query()->create([
        'card_id'                  => $card->id,
        'external_id'              => $processorTxnId,
        'processor_transaction_id' => $processorTxnId,
        'user_id'                  => $user->id,
        'status'                   => 'authorised',
        'amount_cents'             => 5000,
        'currency'                 => 'ZAR',
        'merchant_name'            => 'Smoke Merchant',
        'merchant_category'        => 'Retail',
        'authorization_id'         => $authPayload['authorization_id'],
    ]);

    $clearPayload = [
        'event_id'       => 'evt_clear_smoke_' . uniqid(),
        'type'           => 'clearing',
        'transaction_id' => $processorTxnId,
        'settled_amount' => 5000,
        'currency'       => 'ZAR',
    ];
    $clearBody = json_encode($clearPayload);
    $clearSig  = hash_hmac('sha256', $clearBody, 'demo_webhook_secret');

    $this->call(
        'POST',
        '/api/webhooks/cards/demo/clearing',
        [],
        [],
        [],
        ['HTTP_X_WEBHOOK_SIGNATURE' => $clearSig, 'CONTENT_TYPE' => 'application/json'],
        $clearBody,
    )->assertStatus(200);

    $txList = $this->actingAsWithScopes($user)
        ->withHeader('X-Account-Id', $this->account->uuid)
        ->getJson("/api/v1/cards/{$cardId}/transactions");

    $txList->assertOk();
    $txList->assertJsonPath('data.0.status', 'settled');

    $reveal = $this->actingAsWithScopes($user)
        ->withHeaders([
            'X-Account-Id'     => $this->account->uuid,
            'X-Mobile-Trust'   => 'smoke-trust',
        ])
        ->getJson("/api/v1/cards/{$cardId}/reveal");

    $reveal->assertOk();
    $reveal->assertJsonStructure(['url', 'expires_at']);

    $this->assertDatabaseHas('card_audit_logs', [
        'entity_type' => Card::class,
        'entity_id'   => $cardId,
        'action'      => 'reveal_requested',
    ]);

    $cancelInit = $this->actingAsWithScopes($user)
        ->withHeaders([
            'X-Account-Id'    => $this->account->uuid,
            'Idempotency-Key' => (string) Str::uuid(),
        ])
        ->postJson('/api/v1/card-subscriptions/cancel');

    $cancelInit->assertStatus(200)->assertJsonPath('data.next_step', 'pin');

    $this->actingAsWithScopes($user)
        ->postJson('/api/verification-process/verify/pin', [
            'trx'    => $cancelInit->json('data.trx'),
            'pin'    => '1234',
            'remark' => 'card_product',
        ])->assertOk();

    $sub = CardSubscription::query()
        ->where('subscriber_user_id', $user->id)
        ->latest()
        ->first();

    expect($sub)->not->toBeNull();
    expect($sub->status)->toBe(CardSubscriptionStatus::Cancelled);
});

// Guardian minor-card approve/deny + list coverage lives in
// tests/Feature/Cards/Http/MinorCardRequestControllerTest.php (isolated fixtures; avoids
// cross-connection lock contention with this file's heavier card lifecycle setup).
