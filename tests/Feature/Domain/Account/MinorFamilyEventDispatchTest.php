<?php

declare(strict_types=1);

use App\Domain\Account\Events\MinorFamilyFundingAttemptInitiated;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Services\MinorFamilyIntegrationService;
use App\Domain\MtnMomo\Services\MtnMomoFamilyFundingAdapter;
use App\Models\MtnMomoTransaction;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
    $adapter->shouldReceive('initiateInboundCollection')
        ->andReturn([
            'provider_name'         => 'mtn_momo',
            'provider_reference_id' => 'prov-ref-' . uniqid(),
            'provider_status'       => 'pending',
            'transaction_type'      => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
        ])
        ->byDefault();
    $this->app->instance(MtnMomoFamilyFundingAdapter::class, $adapter);
});

beforeEach(function (): void {
    $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
    $adapter->shouldReceive('initiateInboundCollection')
        ->andReturn([
            'provider_name'         => 'mtn_momo',
            'provider_reference_id' => 'prov-ref-' . uniqid(),
            'provider_status'       => 'pending',
            'transaction_type'      => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
        ])
        ->byDefault();
    $this->app->instance(MtnMomoFamilyFundingAdapter::class, $adapter);
});

it('dispatches MinorFamilyFundingAttemptInitiated when a funding attempt is created', function (): void {
    Event::fake([MinorFamilyFundingAttemptInitiated::class]);

    $parent = Account::factory()->create(['type' => 'personal']);

    $link = MinorFamilyFundingLink::query()->create([
        'tenant_id'               => 'test-tenant',
        'minor_account_uuid'      => $parent->uuid,
        'created_by_user_uuid'    => $parent->user_uuid,
        'created_by_account_uuid' => $parent->uuid,
        'title'                   => 'Test Link',
        'token'                   => 'test-token-' . uniqid(),
        'status'                  => 'active',
        'amount_mode'             => 'fixed',
        'fixed_amount'            => '50.00',
        'collected_amount'        => '0',
        'asset_code'              => 'SZL',
    ]);

    $service = app(MinorFamilyIntegrationService::class);
    $service->createPublicFundingAttempt($link, [
        'sponsor_name'   => 'Uncle Bob',
        'sponsor_msisdn' => '+26876000001',
        'amount'         => '50.00',
        'asset_code'     => 'SZL',
        'provider'       => 'mtn_momo',
    ]);

    Event::assertDispatched(MinorFamilyFundingAttemptInitiated::class);
});
