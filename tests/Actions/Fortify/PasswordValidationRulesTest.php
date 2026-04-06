<?php

declare(strict_types=1);

use App\Actions\Fortify\PasswordValidationRules;

it('is a trait', function () {
    $reflection = new ReflectionClass(PasswordValidationRules::class);
    expect($reflection->isTrait())->toBeTrue();
});

it('has passwordRules method', function () {
    $reflection = new ReflectionClass(PasswordValidationRules::class);
    expect($reflection->hasMethod('passwordRules'))->toBeTrue();
});

it('passwordRules method is protected', function () {
    $reflection = new ReflectionMethod(PasswordValidationRules::class, 'passwordRules');
    expect($reflection->isProtected())->toBeTrue();
});

it('passwordRules returns array', function () {
    $reflection = new ReflectionMethod(PasswordValidationRules::class, 'passwordRules');
    $returnType = $reflection->getReturnType();
    expect($returnType)->toBeInstanceOf(ReflectionType::class);
    expect((string) $returnType)->toBe('array');
});
