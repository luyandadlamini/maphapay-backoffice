<?php

declare(strict_types=1);

use App\Domain\Account\DataObjects\AccountUuid;

it('can be created with valid uuid string', function () {
    $uuid = 'test-account-uuid-123';
    $accountUuid = new AccountUuid($uuid);

    expect($accountUuid->getUuid())->toBe($uuid);
});

it('can be created with generated uuid', function () {
    $uuid = '550e8400-e29b-41d4-a716-446655440000';
    $accountUuid = new AccountUuid($uuid);

    expect($accountUuid->getUuid())->toBe($uuid);
    expect(strlen($accountUuid->getUuid()))->toBe(36);
});

it('returns uuid as string', function () {
    $uuid = 'test-uuid';
    $accountUuid = new AccountUuid($uuid);

    expect($accountUuid->getUuid())->toBeString();
});

it('can be converted to array', function () {
    $uuid = 'test-account-uuid';
    $accountUuid = new AccountUuid($uuid);

    $array = $accountUuid->toArray();

    expect($array)->toHaveKey('uuid', $uuid);
});

it('implements data object contract', function () {
    $accountUuid = new AccountUuid('test-uuid');

    expect($accountUuid)->toBeInstanceOf(JustSteveKing\DataObjects\Contracts\DataObjectContract::class);
});

it('handles empty uuid', function () {
    $accountUuid = new AccountUuid('');

    expect($accountUuid->getUuid())->toBe('');
});

it('can create new uuid with withUuid method', function () {
    $originalUuid = 'original-uuid';
    $newUuid = 'new-uuid';

    $accountUuid = new AccountUuid($originalUuid);
    $newAccountUuid = $accountUuid->withUuid($newUuid);

    expect($newAccountUuid->getUuid())->toBe($newUuid);
    expect($accountUuid->getUuid())->toBe($originalUuid); // Original unchanged
});
