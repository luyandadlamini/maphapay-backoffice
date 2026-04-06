<?php

declare(strict_types=1);

use App\Domain\Account\Aggregates\TransactionAggregate;
use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Workflows\DepositAccountActivity;

it('extends Activity base class', function () {
    $reflection = new ReflectionClass(DepositAccountActivity::class);
    expect($reflection->getParentClass()->getName())->toBe('Workflow\Activity');
});

it('has execute method', function () {
    expect((new ReflectionClass(DepositAccountActivity::class))->hasMethod('execute'))->toBeTrue();
});

it('execute method has correct signature', function () {
    $reflection = new ReflectionClass(DepositAccountActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->getNumberOfParameters())->toBe(3);

    $parameters = $method->getParameters();
    expect($parameters[0]->getName())->toBe('uuid');
    expect($parameters[1]->getName())->toBe('money');
    expect($parameters[2]->getName())->toBe('transaction');
});

it('execute method returns boolean', function () {
    $reflection = new ReflectionClass(DepositAccountActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->getReturnType()?->getName())->toBe('bool');
});

it('has proper type hints for parameters', function () {
    $reflection = new ReflectionClass(DepositAccountActivity::class);
    $method = $reflection->getMethod('execute');
    $parameters = $method->getParameters();

    expect($parameters[0]->getType()?->getName())->toBe('App\Domain\Account\DataObjects\AccountUuid');
    expect($parameters[1]->getType()?->getName())->toBe('App\Domain\Account\DataObjects\Money');
    expect($parameters[2]->getType()?->getName())->toBe('App\Domain\Account\Aggregates\TransactionAggregate');
});

// Coverage tests - test method accessibility and parameter validation
it('can access execute method through reflection', function () {
    $reflection = new ReflectionClass(DepositAccountActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->isPublic())->toBeTrue();
    expect($method->getNumberOfParameters())->toBe(3);
    expect($method->getReturnType()?->getName())->toBe('bool');
});

it('validates all required data objects exist', function () {
    expect((new ReflectionClass(AccountUuid::class))->getName())->not->toBeEmpty();
    expect((new ReflectionClass(Money::class))->getName())->not->toBeEmpty();
    expect((new ReflectionClass(TransactionAggregate::class))->getName())->not->toBeEmpty();
});

it('can create data object instances for testing', function () {
    $uuid = new AccountUuid('test-uuid');
    $money = new Money(1000);

    expect($uuid->getUuid())->toBe('test-uuid');
    expect($money->getAmount())->toBe(1000);
});
