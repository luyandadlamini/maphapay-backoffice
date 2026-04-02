<?php

declare(strict_types=1);

namespace App\Domain\Account\DataObjects;

use JustSteveKing\DataObjects\Contracts\DataObjectContract;

final readonly class AccountUuid extends DataObject implements DataObjectContract
{
    public function __construct(
        private string $uuid
    ) {
    }

    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    public function withUuid(string $uuid): self
    {
        return new self(
            uuid: $uuid,
        );
    }

    /**
     * Create from string UUID.
     */
    public static function fromString(string $uuid): self
    {
        return new self($uuid);
    }

    /**
     * Convert to string.
     */
    public function __toString(): string
    {
        return $this->uuid;
    }

    /**
     * Get the explicit string representation used throughout domain services.
     */
    public function toString(): string
    {
        return $this->uuid;
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
        ];
    }
}
