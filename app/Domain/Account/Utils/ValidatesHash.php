<?php

declare(strict_types=1);

namespace App\Domain\Account\Utils;

use App\Domain\Account\DataObjects\Hash;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Exceptions\InvalidHashException;

trait ValidatesHash
{
    private const string HASH_ALGORITHM = 'sha3-512';

    private const int    HASH_LENGTH = 128;         // SHA3-512 produces a 128-character hexadecimal string

    public string $currentHash = '';

    protected function generateHash(?Money $money = null): Hash
    {
        return hydrate(
            Hash::class,
            [
                'hash' => hash(
                    self::HASH_ALGORITHM,
                    $this->currentHash . ($money ? $money->getAmount() : 0)
                ),
            ]
        );
    }

    protected function validateHash(Hash $hash, ?Money $money = null): void
    {
        if (! $this->generateHash(money: $money)->equals($hash)) {
            throw new InvalidHashException();
        }
    }

    protected function storeHash(Hash $hash): void
    {
        $this->currentHash = $hash->getHash();
    }

    protected function resetHash(?string $hash = null): void
    {
        $this->currentHash = $hash ?? '';
    }

    /**
     * Get the expected length of the hash.
     */
    public static function getHashLength(): int
    {
        return self::HASH_LENGTH;
    }
}
