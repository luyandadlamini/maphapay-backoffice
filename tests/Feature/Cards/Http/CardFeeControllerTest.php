<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user->update([
        'kyc_status'      => 'approved',
        'kyc_approved_at' => now(),
    ]);

    // Bypass KYC middleware — tested separately in CheckKycApprovedTest.
    $this->app->instance(\App\Http\Middleware\CheckKycApproved::class, new class {
        public function handle($request, $next) { return $next($request); }
    });

    // Stub account context so we don't need a real account record.
    $this->app->instance(\App\Http\Middleware\ResolveAccountContext::class, new class($this->account) {
        public function __construct(private $acc) {}
        public function handle($request, $next) {
            $request->attributes->set('account_uuid', $this->acc->uuid);
            return $next($request);
        }
    });
});

it('previews card fees for a transaction', function () {
    $payload = [
        'amount'           => 100.50,
        'currency'         => 'ZAR',
        'billing_currency' => 'ZAR',
        'transaction_type' => 'online_purchase',
    ];

    $response = $this->actingAsWithScopes($this->user)
        ->withHeader('X-Account-Id', $this->account->uuid)
        ->postJson('/api/v1/card-fees/preview', $payload);

    $response->assertOk();
    $response->assertJsonStructure(['data']);
});

it('rejects preview when required fields are missing', function () {
    $payload = [
        'currency' => 'ZAR',
        // missing amount, billing_currency, transaction_type
    ];

    $response = $this->actingAsWithScopes($this->user)
        ->withHeader('X-Account-Id', $this->account->uuid)
        ->postJson('/api/v1/card-fees/preview', $payload);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['amount', 'transaction_type', 'billing_currency']);
});
