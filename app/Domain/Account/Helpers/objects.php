<?php

declare(strict_types=1);

use App\Domain\Account\DataObjects\Account;
use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\Account as AccountModel;
use JustSteveKing\DataObjects\Contracts\DataObjectContract;
use JustSteveKing\DataObjects\Facades\Hydrator;

if (! function_exists('hydrate')) {
    /**
     * Hydrate and return a specific Data Object class instance.
     *
     * @template T of DataObjectContract
     *
     * @param  class-string<T>  $class
     * @return T
     */
    function hydrate(string $class, array $properties): DataObjectContract
    {
        return Hydrator::fill(
            class: $class,
            properties: collect($properties)->map(
                function ($value) {
                    return $value instanceof BackedEnum ? $value->value : $value;
                }
            )->toArray()
        );
    }
}

if (! function_exists('__account')) {
    function __account(Account|array $account): Account
    {
        if ($account instanceof Account) {
            return $account;
        }

        return hydrate(
            class: Account::class,
            properties: $account
        );
    }
}

if (! function_exists('__money')) {
    function __money(Money|int $amount): Money
    {
        if ($amount instanceof Money) {
            return $amount;
        }

        return hydrate(
            class: Money::class,
            properties: [
                'amount' => $amount,
            ]
        );
    }
}

if (! function_exists('__account_uuid')) {
    function __account_uuid(Account|AccountModel|AccountUuid|string $uuid): AccountUuid
    {
        if ($uuid instanceof AccountUuid) {
            return $uuid;
        }

        if ($uuid instanceof Account) {
            $uuid = $uuid->getUuid();
        }

        if ($uuid instanceof AccountModel) {
            $uuid = $uuid->uuid;
        }

        return hydrate(
            class: AccountUuid::class,
            properties: [
                'uuid' => $uuid,
            ]
        );
    }
}

if (! function_exists('__account__uuid')) {
    function __account__uuid(Account|AccountModel|AccountUuid|string $uuid): string
    {
        if ($uuid instanceof AccountUuid) {
            return $uuid->getUuid();
        }

        if ($uuid instanceof Account) {
            return $uuid->getUuid();
        }

        if ($uuid instanceof AccountModel) {
            return $uuid->uuid;
        }

        return $uuid;
    }
}
