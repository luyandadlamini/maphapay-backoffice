<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

final readonly class DisputeInput
{
    /**
     * @param list<string> $evidenceUrls
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $reason,
        public string $description,
        public ?int $amountCents = null,
        public array $evidenceUrls = [],
        public array $metadata = [],
    ) {
    }
}
