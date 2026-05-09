<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

use DateTimeImmutable;

final readonly class RevealUrlResult
{
    public function __construct(
        public string $revealUrl,
        public DateTimeImmutable $expiresAt,
        public int $ttlSeconds,
    ) {
    }
}
