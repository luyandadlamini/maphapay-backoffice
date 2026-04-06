<?php

declare(strict_types=1);

use App\Domain\Account\Events\AccountUnfrozen;

it('can be instantiated with reason', function () {
    $event = new AccountUnfrozen('Administrative action');

    expect($event->reason)->toBe('Administrative action');
    expect($event->authorizedBy)->toBeNull();
});

it('can be instantiated with reason and authorized by', function () {
    $event = new AccountUnfrozen('Account cleared', 'admin@example.com');

    expect($event->reason)->toBe('Account cleared');
    expect($event->authorizedBy)->toBe('admin@example.com');
});

it('extends ShouldBeStored', function () {
    $reflection = new ReflectionClass(AccountUnfrozen::class);
    expect($reflection->getParentClass()->getName())->toBe('Spatie\EventSourcing\StoredEvents\ShouldBeStored');
});

it('has correct queue', function () {
    $event = new AccountUnfrozen('Test');
    expect($event->queue)->toBe('ledger');
});

it('has readonly properties', function () {
    $reflection = new ReflectionClass(AccountUnfrozen::class);
    $reasonProperty = $reflection->getProperty('reason');
    $authorizedByProperty = $reflection->getProperty('authorizedBy');

    expect($reasonProperty->isReadOnly())->toBeTrue();
    expect($authorizedByProperty->isReadOnly())->toBeTrue();
});
