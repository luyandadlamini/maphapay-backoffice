<?php

declare(strict_types=1);

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\Workflows\BalanceInquiryActivity;

it('extends Activity base class', function () {
    $reflection = new ReflectionClass(BalanceInquiryActivity::class);
    expect($reflection->getParentClass()->getName())->toBe('Workflow\Activity');
});

it('has execute method', function () {
    expect((new ReflectionClass(BalanceInquiryActivity::class))->hasMethod('execute'))->toBeTrue();
});

it('has logInquiry method', function () {
    $reflection = new ReflectionClass(BalanceInquiryActivity::class);
    $method = $reflection->getMethod('logInquiry');

    expect($method->isPrivate())->toBeTrue();
});

it('execute method has correct signature', function () {
    $reflection = new ReflectionClass(BalanceInquiryActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->getNumberOfParameters())->toBe(3);

    $parameters = $method->getParameters();
    expect($parameters[0]->getName())->toBe('uuid');
    expect($parameters[1]->getName())->toBe('requestedBy');
    expect($parameters[2]->getName())->toBe('transaction');
});

// Coverage tests - test method accessibility and parameter validation
it('can access execute method through reflection', function () {
    $reflection = new ReflectionClass(BalanceInquiryActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->isPublic())->toBeTrue();
    expect($method->getNumberOfParameters())->toBe(3);
    expect($method->getReturnType()?->getName())->toBe('array');
});

it('validates balance inquiry has private logInquiry method', function () {
    $reflection = new ReflectionClass(BalanceInquiryActivity::class);
    $logMethod = $reflection->getMethod('logInquiry');

    expect($logMethod->isPrivate())->toBeTrue();
    expect($logMethod->getNumberOfParameters())->toBe(2);
});

it('can create data object instances for balance inquiry testing', function () {
    $uuid = new AccountUuid('balance-test-uuid');

    expect($uuid->getUuid())->toBe('balance-test-uuid');
    expect((new ReflectionClass(BalanceInquiryActivity::class))->getName())->not->toBeEmpty();
});
