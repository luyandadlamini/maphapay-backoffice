<?php

declare(strict_types=1);

namespace App\Domain\Account\DataObjects;

use JustSteveKing\DataObjects\Contracts\DataObjectContract;

abstract readonly class DataObject implements DataObjectContract
{
    abstract public function toArray(): array;

    public static function fromArray(array $params): self
    {
        return hydrate(
            class: static::class,
            properties: $params
        );
    }
}
