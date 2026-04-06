<?php

declare(strict_types=1);

use App\Domain\Account\Events\AccountFrozen;

it('can be instantiated with reason', function () {
    $event = new AccountFrozen('Administrative action');

    expect($event->reason)->toBe('Administrative action');
    expect($event->authorizedBy)->toBeNull();
});

it('can be instantiated with reason and authorized by', function () {
    $event = new AccountFrozen('Suspicious activity', 'admin@example.com');

    expect($event->reason)->toBe('Suspicious activity');
    expect($event->authorizedBy)->toBe('admin@example.com');
});

it('extends ShouldBeStored', function () {
    $reflection = new ReflectionClass(AccountFrozen::class);
    expect($reflection->getParentClass()->getName())->toBe('Spatie\EventSourcing\StoredEvents\ShouldBeStored');
});

it('has correct queue', function () {
    $event = new AccountFrozen('Test');
    expect($event->queue)->toBe('ledger');
});

it('has readonly properties', function () {
    $reflection = new ReflectionClass(AccountFrozen::class);
    $reasonProperty = $reflection->getProperty('reason');
    $authorizedByProperty = $reflection->getProperty('authorizedBy');

    expect($reasonProperty->isReadOnly())->toBeTrue();
    expect($authorizedByProperty->isReadOnly())->toBeTrue();
});
