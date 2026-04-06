<?php

declare(strict_types=1);

use App\Domain\Payment\Activities\PublishDepositCompletedActivity;
use App\Domain\Payment\DataObjects\StripeDeposit;

it('extends Activity base class', function () {
    $reflection = new ReflectionClass(PublishDepositCompletedActivity::class);
    expect($reflection->getParentClass()->getName())->toBe('Workflow\Activity');
});

it('has execute method', function () {
    expect((new ReflectionClass(PublishDepositCompletedActivity::class))->hasMethod('execute'))->toBeTrue();
});

it('execute method has correct signature', function () {
    $reflection = new ReflectionClass(PublishDepositCompletedActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->getNumberOfParameters())->toBe(2);

    $parameters = $method->getParameters();
    expect($parameters[0]->getName())->toBe('transactionId');
    expect($parameters[0]->getType()?->getName())->toBe('string');

    expect($parameters[1]->getName())->toBe('deposit');
    expect($parameters[1]->getType()?->getName())->toBe(StripeDeposit::class);
});

it('execute method returns void', function () {
    $reflection = new ReflectionClass(PublishDepositCompletedActivity::class);
    $method = $reflection->getMethod('execute');

    expect($method->getReturnType()?->getName())->toBe('void');
});
