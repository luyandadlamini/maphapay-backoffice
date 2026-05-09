<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

final readonly class CreateVirtualCardInput
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public CardControlsInput $controls,
        public ?string $label = null,
        public string $currency = 'SZL',
        public ?string $network = null,
        public array $metadata = [],
    ) {
    }
}
