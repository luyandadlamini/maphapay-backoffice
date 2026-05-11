<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\ValueObjects;

use DateTimeImmutable;

final readonly class RevealUrlResult
{
    public function __construct(
        public string $url,
        public DateTimeImmutable $expiresAt,
        public int $ttlSeconds,
    ) {}
}
