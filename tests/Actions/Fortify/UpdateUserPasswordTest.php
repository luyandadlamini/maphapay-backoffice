<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserPassword;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

it('implements UpdatesUserPasswords contract', function () {
    expect(UpdateUserPassword::class)->toImplement(UpdatesUserPasswords::class);
});

it('has update method', function () {
    expect((new ReflectionClass(UpdateUserPassword::class))->hasMethod('update'))->toBeTrue();
});

it('can be instantiated', function () {
    expect(new UpdateUserPassword())->toBeInstanceOf(UpdateUserPassword::class);
});

it('has correct method signature', function () {
    $reflection = new ReflectionMethod(UpdateUserPassword::class, 'update');
    expect($reflection->isPublic())->toBeTrue();
    expect($reflection->getNumberOfParameters())->toBe(2);
});
