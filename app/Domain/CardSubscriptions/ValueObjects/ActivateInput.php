<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

final readonly class ActivateInput
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $activationCode,
        public ?string $last4 = null,
        public array $metadata = [],
    ) {
    }
}
