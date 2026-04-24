<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Services\MinorFamilyIntegrationService;
use App\Domain\MtnMomo\Services\MtnMomoFamilyFundingAdapter;
use App\Models\MtnMomoTransaction;

beforeEach(function (): void {
    $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
    $adapter->shouldReceive('initiateInboundCollection')
        ->andReturn([
            'provider_name' => 'mtn_momo',
            'provider_reference_id' => 'prov-ref-' . uniqid(),
            'provider_status' => 'pending',
            'transaction_type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
        ])
        ->byDefault();
    $this->app->instance(MtnMomoFamilyFundingAdapter::class, $adapter);
});

beforeEach(function (): void {
    $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
    $adapter->shouldReceive('initiateInboundCollection')
        ->andReturn([
            'provider_name' => 'mtn_momo',
            'provider_reference_id' => 'prov-ref-' . uniqid(),
            'provider_status' => 'pending',
            'transaction_type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
        ])
        ->byDefault();
    $this->app->instance(MtnMomoFamilyFundingAdapter::class, $adapter);
});

it('returns the existing funding attempt on deduplicated concurrent requests', function (): void {
    $parent = Account::factory()->create(['type' => 'personal']);

    $link = MinorFamilyFundingLink::query()->create([
        'tenant_id' => 'test-tenant',
        'minor_account_uuid' => $parent->uuid,
        'created_by_user_uuid' => $parent->user_uuid,
        'created_by_account_uuid' => $parent->uuid,
        'title' => 'Test Link',
        'token' => 'test-token-' . uniqid(),
        'status' => 'active',
        'amount_mode' => 'fixed',
        'fixed_amount' => '100.00',
        'collected_amount' => '0',
        'asset_code' => 'SZL',
    ]);

    $attributes = [
        'sponsor_name'   => 'Aunt Rose',
        'sponsor_msisdn' => '+26876543210',
        'amount'         => '100.00',
        'asset_code'     => 'SZL',
        'provider'       => 'mtn_momo',
    ];

    $service = app(MinorFamilyIntegrationService::class);

    $attempt1 = $service->createPublicFundingAttempt($link, $attributes);
    $attempt2 = $service->createPublicFundingAttempt($link, $attributes);

    expect($attempt1->id)->toBe($attempt2->id);
    expect(MinorFamilyFundingAttempt::query()
        ->where('dedupe_hash', $attempt1->dedupe_hash)
        ->count()
    )->toBe(1);
});