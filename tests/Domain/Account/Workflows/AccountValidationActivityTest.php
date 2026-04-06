<?php

declare(strict_types=1);

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\Workflows\AccountValidationActivity;

it('extends Activity base class', function () {
    $reflection = new ReflectionClass(AccountValidationActivity::class);
    expect($reflection->getParentClass()->getName())->toBe('Workflow\Activity');
});

it('has execute method', function () {
    expect((new ReflectionClass(AccountValidationActivity::class))->hasMethod('execute'))->toBeTrue();
});

it('execute method has correct signature', function () {
    $reflection = new ReflectionClass(AccountValidationActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->getNumberOfParameters())->toBe(3);

    $parameters = $method->getParameters();
    expect($parameters[0]->getName())->toBe('uuid');
    expect($parameters[1]->getName())->toBe('validationChecks');
    expect($parameters[2]->getName())->toBe('validatedBy');
});

it('execute method returns array', function () {
    $reflection = new ReflectionClass(AccountValidationActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->getReturnType()?->getName())->toBe('array');
});

it('has proper type hints for parameters', function () {
    $reflection = new ReflectionClass(AccountValidationActivity::class);
    $method = $reflection->getMethod('execute');
    $parameters = $method->getParameters();

    expect($parameters[0]->getType()?->getName())->toBe('App\Domain\Account\DataObjects\AccountUuid');
    expect($parameters[1]->getType()?->getName())->toBe('array');
    expect($parameters[2]->getType()?->getName())->toBe('string');
    expect($parameters[2]->allowsNull())->toBeTrue();
});

it('has validation check methods', function () {
    $methods = [
        'performValidationCheck',
        'validateKycDocuments',
        'validateAddress',
        'validateIdentity',
        'performComplianceScreening',
        'logValidation',
    ];

    foreach ($methods as $method) {
        expect((new ReflectionClass(AccountValidationActivity::class))->hasMethod($method))->toBeTrue();
    }
});

it('validation methods have proper visibility', function () {
    $reflection = new ReflectionClass(AccountValidationActivity::class);

    $privateMethodNames = [
        'performValidationCheck',
        'validateKycDocuments',
        'validateAddress',
        'validateIdentity',
        'performComplianceScreening',
        'logValidation',
    ];

    foreach ($privateMethodNames as $methodName) {
        $method = $reflection->getMethod($methodName);
        expect($method->isPrivate())->toBeTrue();
    }
});

it('validation methods return arrays', function () {
    $reflection = new ReflectionClass(AccountValidationActivity::class);

    $methods = [
        'performValidationCheck',
        'validateKycDocuments',
        'validateAddress',
        'validateIdentity',
        'performComplianceScreening',
    ];

    foreach ($methods as $methodName) {
        $method = $reflection->getMethod($methodName);
        expect($method->getReturnType()?->getName())->toBe('array');
    }
});

// Coverage tests - test method accessibility and parameter validation
it('can access execute method through reflection', function () {
    $reflection = new ReflectionClass(AccountValidationActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->isPublic())->toBeTrue();
    expect($method->getNumberOfParameters())->toBe(3);
    expect($method->getReturnType()?->getName())->toBe('array');
});

it('validates all required validation methods exist', function () {
    $reflection = new ReflectionClass(AccountValidationActivity::class);

    $expectedMethods = [
        'execute',
        'performValidationCheck',
        'validateKycDocuments',
        'validateAddress',
        'validateIdentity',
        'performComplianceScreening',
        'logValidation',
    ];

    foreach ($expectedMethods as $methodName) {
        expect($reflection->hasMethod($methodName))->toBeTrue();
    }
});

it('can create data object instances for validation testing', function () {
    $uuid = new AccountUuid('validation-test-uuid');

    expect($uuid->getUuid())->toBe('validation-test-uuid');
    expect((new ReflectionClass(AccountValidationActivity::class))->getName())->not->toBeEmpty();
});
