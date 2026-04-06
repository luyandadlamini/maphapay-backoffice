<?php

declare(strict_types=1);

use App\Domain\Account\DataObjects\Money;

it('can be created with integer amount', function () {
    $amount = 10000;
    $money = new Money($amount);

    expect($money->getAmount())->toBe($amount);
});

it('can be created with zero amount', function () {
    $money = new Money(0);

    expect($money->getAmount())->toBe(0);
});

it('can be created with negative amount', function () {
    $money = new Money(-5000);

    expect($money->getAmount())->toBe(-5000);
});

it('can be created with large amount', function () {
    $amount = 999999999;
    $money = new Money($amount);

    expect($money->getAmount())->toBe($amount);
});

it('returns amount as integer', function () {
    $money = new Money(12345);

    expect($money->getAmount())->toBeInt();
});

it('can be converted to array', function () {
    $amount = 7500;
    $money = new Money($amount);

    $array = $money->toArray();

    expect($array)->toHaveKey('amount', $amount);
});

it('implements data object contract', function () {
    $money = new Money(1000);

    expect($money)->toBeInstanceOf(JustSteveKing\DataObjects\Contracts\DataObjectContract::class);
});

it('handles float amounts by converting to int', function () {
    $money = new Money(123.45);

    expect($money->getAmount())->toBeInt();
    expect($money->getAmount())->toBe(123);
});
