<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use App\Domain\Account\DataObjects\Hash;
use App\Domain\Account\DataObjects\Money;

trait HashValidatorProvider
{
    public function __construct(
        public readonly Money $money,
        public readonly Hash $hash,
    ) {
    }

    public function getHash(): Hash
    {
        return $this->hash;
    }

    public function getMoney(): Money
    {
        return $this->money;
    }
}
