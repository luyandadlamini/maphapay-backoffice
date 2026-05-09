<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

use App\Domain\CardSubscriptions\Enums\CardErrorCode;

final readonly class EntitlementDecision
{
    public function __construct(
        public bool $allowed,
        public ?CardErrorCode $code = null,
        public ?string $message = null,
    ) {
    }

    public static function allow(?string $message = null): self
    {
        return new self(true, message: $message);
    }

    public static function deny(CardErrorCode $code, ?string $message = null): self
    {
        return new self(false, $code, $message);
    }
}
