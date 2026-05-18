<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['maphapay_migration.enable_verification' => true]);

    $this->seed(Database\Seeders\CardPlanSeeder::class);

    if (isset($this->business_user)) {
        $this->business_user->update([
            'kyc_status'      => 'approved',
            'kyc_approved_at' => now(),
            'transaction_pin' => '1234',
        ]);
    }

    // Bypass KYC middleware — tested separately in CheckKycApprovedTest.
    $this->app->instance(App\Http\Middleware\CheckKycApproved::class, new class () {
        public function handle($request, $next)
        {
        return $next($request);
        }
    });

    // Use business_user + account via a stubbed account context.
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

describe('GET /me', function () {
    it('returns null data when user has no active subscription', function () {
        $this->actingAsWithScopes($this->business_user);

        $response = $this->withHeader('X-Account-Id', $this->account->uuid)
            ->getJson('/api/v1/card-subscriptions/me');

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.subscription', null);
    });
});

describe('POST /card-subscriptions/cancel', function () {
    it('cancels the current subscription', function () {
        $this->actingAsWithScopes($this->business_user);

        $subscribeInit = $this->withHeaders([
            'X-Account-Id'    => $this->account->uuid,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/card-subscriptions', [
            'plan_code' => 'VIRTUAL_LITE',
        ]);

        $subscribeInit->assertStatus(200)->assertJsonPath('data.next_step', 'pin');

        $this->actingAsWithScopes($this->business_user)
            ->postJson('/api/verification-process/verify/pin', [
                'trx'    => $subscribeInit->json('data.trx'),
                'pin'    => '1234',
                'remark' => 'card_product',
            ])->assertOk();

        $cancelInit = $this->withHeaders([
            'X-Account-Id'    => $this->account->uuid,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/card-subscriptions/cancel');

        $cancelInit->assertStatus(200)->assertJsonPath('data.next_step', 'pin');

        $this->actingAsWithScopes($this->business_user)
            ->postJson('/api/verification-process/verify/pin', [
                'trx'    => $cancelInit->json('data.trx'),
                'pin'    => '1234',
                'remark' => 'card_product',
            ])->assertOk();

        $me = $this->withHeader('X-Account-Id', $this->account->uuid)
            ->getJson('/api/v1/card-subscriptions/me');

        $me->assertOk();
        $me->assertJsonPath('data.subscription', null);
    });
});

describe('POST / (create subscription)', function () {
    it('creates a subscription and the me endpoint reflects it', function () {
        $this->actingAsWithScopes($this->business_user);

        $init = $this->withHeaders([
            'X-Account-Id'    => $this->account->uuid,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/card-subscriptions', [
            'plan_code' => 'VIRTUAL_LITE',
        ]);

        $init->assertStatus(200)->assertJsonPath('data.next_step', 'pin');

        $this->actingAsWithScopes($this->business_user)
            ->postJson('/api/verification-process/verify/pin', [
                'trx'    => $init->json('data.trx'),
                'pin'    => '1234',
                'remark' => 'card_product',
            ])->assertOk()->assertJsonStructure([
                'data' => ['subscription' => ['id', 'status', 'plan']],
            ]);

        $meResponse = $this->withHeader('X-Account-Id', $this->account->uuid)
            ->getJson('/api/v1/card-subscriptions/me');

        $meResponse->assertOk();
        expect($meResponse->json('data.subscription.plan_code'))->toBe('VIRTUAL_LITE');
    });

    it('returns 422 when a minor account tries an adult plan', function () {
        $minor = User::factory()->create([
            'kyc_status'      => 'approved',
            'kyc_approved_at' => now(),
            'transaction_pin' => '1234',
        ]);
        $minorAccount = $this->createAccount($minor);
        $minorAccount->update(['type' => 'minor']);

        $this->app->instance(App\Http\Middleware\ResolveAccountContext::class, new class ($minorAccount) {
            public function __construct(private $minorAccount)
            {
            }

            public function handle($request, $next)
            {
                $request->attributes->set('account_uuid', $this->minorAccount->uuid);
                $request->attributes->set('account_type', 'minor');

                return $next($request);
            }
        });

        $this->actingAsWithScopes($minor);

        $response = $this->withHeaders([
            'X-Account-Id'    => $minorAccount->uuid,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/card-subscriptions', [
            'plan_code' => 'VIRTUAL_LITE',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.next_step', 'pin');

        $verify = $this->actingAsWithScopes($minor)
            ->postJson('/api/verification-process/verify/pin', [
                'trx'    => $response->json('data.trx'),
                'pin'    => '1234',
                'remark' => 'card_product',
            ]);

        $verify->assertStatus(422);
        $verify->assertJsonPath('error', 'PLAN_NOT_ELIGIBLE_FOR_USER');
    });
});
