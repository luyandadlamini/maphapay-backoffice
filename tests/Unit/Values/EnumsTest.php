<?php

declare(strict_types=1);

use App\Domain\User\Values\UserRoles;
use App\Values\EventQueues;
use Tests\UnitTestCase;

uses(UnitTestCase::class);

it('event queues enum has correct values', function () {
    expect(EventQueues::EVENTS->value)->toBe('events');
    expect(EventQueues::LEDGER->value)->toBe('ledger');
    expect(EventQueues::TRANSACTIONS->value)->toBe('transactions');
    expect(EventQueues::TRANSFERS->value)->toBe('transfers');
    expect(EventQueues::LIQUIDITY_POOLS->value)->toBe('liquidity_pools');
});

it('user roles enum has correct values', function () {
    expect(UserRoles::BUSINESS->value)->toBe('business');
    expect(UserRoles::PRIVATE->value)->toBe('private');
    expect(UserRoles::ADMIN->value)->toBe('admin');
});

it('can get all event queue values', function () {
    $cases = EventQueues::cases();

    expect($cases)->toHaveCount(5);
    expect(collect($cases)->pluck('value')->toArray())
        ->toBe(['events', 'ledger', 'transactions', 'transfers', 'liquidity_pools']);
});

it('can get all user role values', function () {
    $values = collect(UserRoles::cases())->pluck('value')->sort()->values()->all();

    expect($values)->toBe([
        'admin',
        'business',
        'compliance-manager',
        'finance-lead',
        'operations-l2',
        'private',
        'super-admin',
        'support-l1',
    ]);
});

it('enums are backed by strings', function () {
    expect(EventQueues::EVENTS)->toBeInstanceOf(BackedEnum::class);
    expect(UserRoles::BUSINESS)->toBeInstanceOf(BackedEnum::class);
});

it('enums have proper type', function () {
    expect(EventQueues::EVENTS->value)->toBeString();
    expect(UserRoles::BUSINESS->value)->toBeString();
});
