<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    config(['maphapay_migration.enable_verification' => true]);

    $this->business_user->update([
        'kyc_status'        => 'approved',
        'kyc_approved_at'   => now(),
        'transaction_pin'   => '1234',
    ]);

    $this->app->instance(\App\Http\Middleware\CheckKycApproved::class, new class {
        public function handle($request, $next)
        {
            return $next($request);
        }
    });
});

it('validates intent.request_type on POST /api/v1/minor-card-requests', function (): void {
    $minor = User::factory()->create([
        'kyc_status'        => 'approved',
        'kyc_approved_at'   => now(),
        'transaction_pin'   => '1234',
    ]);
    $minorAccount = $this->createAccount($minor);
    $minorAccount->update(['type' => 'minor', 'tier' => 'rise']);

    $this->app->instance(\App\Http\Middleware\ResolveAccountContext::class, new class($minorAccount) {
        public function __construct(private $minorAccount) {}

        public function handle($request, $next)
        {
            $request->attributes->set('account_uuid', $this->minorAccount->uuid);
            $request->attributes->set('account_type', 'minor');

            return $next($request);
        }
    });

    $this->actingAsWithScopes($minor);

    $response = $this->withHeader('X-Account-Id', $minorAccount->uuid)
        ->postJson('/api/v1/minor-card-requests', [
            'self_request' => true,
            'intent'       => [
                'request_type' => 'not_a_real_intent',
                'payload'      => [],
            ],
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['intent.request_type']);
});
