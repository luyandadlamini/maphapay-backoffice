<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

use App\Domain\CardSubscriptions\Enums\CardErrorCode;

final readonly class RiskDecision
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $allowed,
        public CardErrorCode|string|null $code = null,
        public ?string $message = null,
        public ?string $severity = null,
        public array $metadata = [],
    ) {
    }

    public static function allow(?string $message = null): self
    {
        return new self(true, message: $message);
    }

    public static function deny(CardErrorCode|string $code, ?string $message = null, ?string $severity = null): self
    {
        return new self(false, $code, $message, $severity);
    }
}
