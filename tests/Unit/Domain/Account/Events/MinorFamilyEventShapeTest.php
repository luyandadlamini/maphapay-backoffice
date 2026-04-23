<?php

declare(strict_types=1);

use App\Domain\Account\Events\MinorFamilyFundingAttemptFailed;
use App\Domain\Account\Events\MinorFamilyFundingAttemptInitiated;
use App\Domain\Account\Events\MinorFamilyFundingAttemptSucceeded;
use App\Domain\Account\Events\MinorFamilyFundingCredited;
use App\Domain\Account\Events\MinorFamilyFundingLinkCreated;
use App\Domain\Account\Events\MinorFamilyFundingLinkExpired;
use App\Domain\Account\Events\MinorFamilySupportTransferFailed;
use App\Domain\Account\Events\MinorFamilySupportTransferInitiated;
use App\Domain\Account\Events\MinorFamilySupportTransferRefunded;
use App\Domain\Account\Events\MinorFamilySupportTransferSucceeded;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;
use Tests\UnitTestCase;

uses(UnitTestCase::class);

it('uses persisted past tense event classes for phase 9a minor family lifecycle changes', function () {
    $events = [
        new MinorFamilyFundingLinkCreated('link-uuid', 'minor-uuid', 'actor-uuid'),
        new MinorFamilyFundingLinkExpired('link-uuid', 'minor-uuid', 'actor-uuid'),
        new MinorFamilyFundingAttemptInitiated('attempt-uuid', 'link-uuid', 'minor-uuid', 'mtn_momo', '100.00', 'SZL'),
        new MinorFamilyFundingAttemptSucceeded('attempt-uuid', 'link-uuid', 'minor-uuid', 'provider-ref'),
        new MinorFamilyFundingAttemptFailed('attempt-uuid', 'link-uuid', 'minor-uuid', 'provider rejected'),
        new MinorFamilyFundingCredited('attempt-uuid', 'link-uuid', 'minor-uuid', '100.00', 'SZL'),
        new MinorFamilySupportTransferInitiated('transfer-uuid', 'minor-uuid', 'actor-uuid', 'source-uuid', 'mtn_momo', '80.00', 'SZL'),
        new MinorFamilySupportTransferSucceeded('transfer-uuid', 'minor-uuid', 'provider-ref'),
        new MinorFamilySupportTransferFailed('transfer-uuid', 'minor-uuid', 'provider rejected'),
        new MinorFamilySupportTransferRefunded('transfer-uuid', 'minor-uuid', 'source-uuid', '80.00', 'SZL'),
    ];

    foreach ($events as $event) {
        expect($event)->toBeInstanceOf(ShouldBeStored::class);
        expect(class_basename($event))->not->toStartWith('Create');
        expect(class_basename($event))->not->toStartWith('Expire');
        expect(class_basename($event))->not->toStartWith('Initiate');
        expect(class_basename($event))->not->toStartWith('Succeed');
        expect(class_basename($event))->not->toStartWith('Fail');
        expect(class_basename($event))->not->toStartWith('Refund');
        expect(class_basename($event))->not->toStartWith('Credit');
    }
});

it('stores the expected business identifiers on phase 9a events', function () {
    $initiated = new MinorFamilyFundingAttemptInitiated(
        'attempt-uuid',
        'link-uuid',
        'minor-uuid',
        'mtn_momo',
        '100.00',
        'SZL',
    );

    $refunded = new MinorFamilySupportTransferRefunded(
        'transfer-uuid',
        'minor-uuid',
        'source-uuid',
        '80.00',
        'SZL',
    );

    expect($initiated->fundingAttemptUuid)->toBe('attempt-uuid')
        ->and($initiated->fundingLinkUuid)->toBe('link-uuid')
        ->and($initiated->minorAccountUuid)->toBe('minor-uuid')
        ->and($initiated->providerName)->toBe('mtn_momo')
        ->and($initiated->amount)->toBe('100.00')
        ->and($initiated->assetCode)->toBe('SZL');

    expect($refunded->familySupportTransferUuid)->toBe('transfer-uuid')
        ->and($refunded->minorAccountUuid)->toBe('minor-uuid')
        ->and($refunded->refundedToAccountUuid)->toBe('source-uuid')
        ->and($refunded->amount)->toBe('80.00')
        ->and($refunded->assetCode)->toBe('SZL');
});
