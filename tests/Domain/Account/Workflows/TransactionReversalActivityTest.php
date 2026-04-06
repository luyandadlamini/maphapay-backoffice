<?php

declare(strict_types=1);

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Workflows\TransactionReversalActivity;

it('extends Activity base class', function () {
    $reflection = new ReflectionClass(TransactionReversalActivity::class);
    expect($reflection->getParentClass()->getName())->toBe('Workflow\Activity');
});

it('has execute method', function () {
    expect((new ReflectionClass(TransactionReversalActivity::class))->hasMethod('execute'))->toBeTrue();
});

it('execute method has correct signature', function () {
    $reflection = new ReflectionClass(TransactionReversalActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->getNumberOfParameters())->toBe(6);

    $parameters = $method->getParameters();
    expect($parameters[0]->getName())->toBe('accountUuid');
    expect($parameters[1]->getName())->toBe('originalAmount');
    expect($parameters[2]->getName())->toBe('transactionType');
    expect($parameters[3]->getName())->toBe('reversalReason');
    expect($parameters[4]->getName())->toBe('authorizedBy');
    expect($parameters[5]->getName())->toBe('transaction');
});

it('execute method returns array', function () {
    $reflection = new ReflectionClass(TransactionReversalActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->getReturnType()?->getName())->toBe('array');
});

it('has proper type hints for parameters', function () {
    $reflection = new ReflectionClass(TransactionReversalActivity::class);
    $method = $reflection->getMethod('execute');
    $parameters = $method->getParameters();

    expect($parameters[0]->getType()?->getName())->toBe('App\Domain\Account\DataObjects\AccountUuid');
    expect($parameters[1]->getType()?->getName())->toBe('App\Domain\Account\DataObjects\Money');
    expect($parameters[2]->getType()?->getName())->toBe('string');
    expect($parameters[3]->getType()?->getName())->toBe('string');
    expect($parameters[4]->getType()?->getName())->toBe('string');
    expect($parameters[4]->allowsNull())->toBeTrue();
    expect($parameters[5]->getType()?->getName())->toBe('App\Domain\Account\Aggregates\TransactionAggregate');
});

it('has logReversal method', function () {
    $reflection = new ReflectionClass(TransactionReversalActivity::class);
    $method = $reflection->getMethod('logReversal');

    expect($method->isPrivate())->toBeTrue();
});

// Coverage tests - test method accessibility and parameter validation
it('can access execute method through reflection', function () {
    $reflection = new ReflectionClass(TransactionReversalActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->isPublic())->toBeTrue();
    expect($method->getNumberOfParameters())->toBe(6);
    expect($method->getReturnType()?->getName())->toBe('array');
});

it('validates transaction reversal has private logReversal method', function () {
    $reflection = new ReflectionClass(TransactionReversalActivity::class);
    $logMethod = $reflection->getMethod('logReversal');

    expect($logMethod->isPrivate())->toBeTrue();
    expect($logMethod->getNumberOfParameters())->toBe(5);
});

it('can create data object instances for reversal testing', function () {
    $uuid = new AccountUuid('reversal-test-uuid');
    $money = new Money(3000);

    expect($uuid->getUuid())->toBe('reversal-test-uuid');
    expect($money->getAmount())->toBe(3000);
    expect((new ReflectionClass(TransactionReversalActivity::class))->getName())->not->toBeEmpty();
});
