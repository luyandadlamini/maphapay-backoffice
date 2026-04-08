<?php

declare(strict_types=1);

namespace App\Domain\Mobile\DataObjects;

readonly class AppAttestVerificationResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $verified,
        public string $reason,
        public array $metadata = [],
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function success(array $metadata = [], string $reason = 'verified'): self
    {
        return new self(true, $reason, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failure(string $reason, array $metadata = []): self
    {
        return new self(false, $reason, $metadata);
    }
}
