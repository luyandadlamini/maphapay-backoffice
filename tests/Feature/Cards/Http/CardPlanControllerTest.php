<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\CardPlanSeeder::class);
    $this->user->update([
        'kyc_status' => 'approved',
        'kyc_approved_at' => now(),
    ]);
    if (isset($this->business_user)) {
        $this->business_user->update([
            'kyc_status' => 'approved',
            'kyc_approved_at' => now(),
        ]);
    }

    // Bypass KYC middleware — tested separately.
    $this->app->instance(\App\Http\Middleware\CheckKycApproved::class, new class {
        public function handle($request, $next) { return $next($request); }
    });
});

describe('Card Plans API', function () {
    it('returns available plans for adults', function () {
        $this->app->instance(\App\Http\Middleware\ResolveAccountContext::class, new class($this->account) {
            public function __construct(private $acc) {}
            public function handle($request, $next) {
                $request->attributes->set('account_uuid', $this->acc->uuid);
                $request->attributes->set('account_type', 'business');
                return $next($request);
            }
        });

        $this->actingAsWithScopes($this->business_user);
        $response = $this->withHeader('X-Account-Id', $this->account->uuid)
            ->getJson('/api/v1/card-subscriptions/plans');
            
        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => ['code', 'name', 'monthly_fee', 'currency', 'eligibility']
            ]
        ]);
        
        $planCodes = collect($response->json('data'))->pluck('code');
        expect($planCodes)->not->toContain('MINOR_KHULA_CARD');
        expect($planCodes)->toContain('VIRTUAL_LITE');
        expect($planCodes)->toContain('PHYSICAL_CARD');
    });

    it('returns only MINOR_KHULA_CARD for minors', function () {
        $minor = User::factory()->create([
            'kyc_status' => 'approved',
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
        $response = $this->withHeader('X-Account-Id', $minorAccount->uuid)
            ->getJson('/api/v1/card-subscriptions/plans');
            
        $response->assertOk();
        $planCodes = collect($response->json('data'))->pluck('code');
        
        expect($planCodes->toArray())->toContain('MINOR_KHULA_CARD');
        expect($planCodes->count())->toBe(1);
    });
});
