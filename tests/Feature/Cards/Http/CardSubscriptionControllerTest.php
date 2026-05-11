<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\CardPlanSeeder::class);

    if (isset($this->business_user)) {
        $this->business_user->update([
            'kyc_status'      => 'approved',
            'kyc_approved_at' => now(),
        ]);
    }

    // Bypass KYC middleware — tested separately in CheckKycApprovedTest.
    $this->app->instance(\App\Http\Middleware\CheckKycApproved::class, new class {
        public function handle($request, $next) { return $next($request); }
    });

    // Use business_user + account via a stubbed account context.
    $this->app->instance(\App\Http\Middleware\ResolveAccountContext::class, new class($this->account) {
        public function __construct(private $acc) {}
        public function handle($request, $next) {
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
        $response->assertJson(['data' => null]);
    });
});

describe('POST /card-subscriptions/cancel', function () {
    it('cancels the current subscription', function () {
        $this->actingAsWithScopes($this->business_user);

        $this->withHeaders([
            'X-Account-Id'    => $this->account->uuid,
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
        ])->postJson('/api/v1/card-subscriptions', [
            'plan_code' => 'VIRTUAL_LITE',
        ])->assertStatus(201);

        $cancel = $this->withHeaders([
            'X-Account-Id'    => $this->account->uuid,
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
        ])->postJson('/api/v1/card-subscriptions/cancel');

        $cancel->assertOk();

        $me = $this->withHeader('X-Account-Id', $this->account->uuid)
            ->getJson('/api/v1/card-subscriptions/me');

        $me->assertOk();
        $me->assertJson(['data' => null]);
    });
});

describe('POST / (create subscription)', function () {
    it('creates a subscription and the me endpoint reflects it', function () {
        $this->actingAsWithScopes($this->business_user);

        $response = $this->withHeaders([
            'X-Account-Id'    => $this->account->uuid,
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
        ])->postJson('/api/v1/card-subscriptions', [
            'plan_code' => 'VIRTUAL_LITE',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'status', 'plan'],
        ]);

        $meResponse = $this->withHeader('X-Account-Id', $this->account->uuid)
            ->getJson('/api/v1/card-subscriptions/me');

        $meResponse->assertOk();
        expect($meResponse->json('data.plan.code'))->toBe('VIRTUAL_LITE');
    });

    it('returns 422 when a minor account tries an adult plan', function () {
        $minor = User::factory()->create([
            'kyc_status'      => 'approved',
            'kyc_approved_at' => now(),
        ]);
        $minorAccount = $this->createAccount($minor);
        $minorAccount->update(['type' => 'minor']);

        $this->app->instance(\App\Http\Middleware\ResolveAccountContext::class, new class($minorAccount) {
            public function __construct(private $minorAccount) {}
            public function handle($request, $next) {
                $request->attributes->set('account_uuid', $this->minorAccount->uuid);
                $request->attributes->set('account_type', 'minor');
                return $next($request);
            }
        });

        $this->actingAsWithScopes($minor);

        $response = $this->withHeaders([
            'X-Account-Id'    => $minorAccount->uuid,
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
        ])->postJson('/api/v1/card-subscriptions', [
            'plan_code' => 'VIRTUAL_LITE',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'PLAN_NOT_ELIGIBLE_FOR_USER');
    });
});
