<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserProfileInformation;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

it('implements UpdatesUserProfileInformation contract', function () {
    expect(UpdateUserProfileInformation::class)->toImplement(UpdatesUserProfileInformation::class);
});

it('has update method', function () {
    expect((new ReflectionClass(UpdateUserProfileInformation::class))->hasMethod('update'))->toBeTrue();
});

it('can be instantiated', function () {
    expect(new UpdateUserProfileInformation())->toBeInstanceOf(UpdateUserProfileInformation::class);
});

it('has correct method signature', function () {
    $reflection = new ReflectionMethod(UpdateUserProfileInformation::class, 'update');
    expect($reflection->isPublic())->toBeTrue();
    expect($reflection->getNumberOfParameters())->toBe(2);
});
