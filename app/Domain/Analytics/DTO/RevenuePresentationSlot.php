<?php

declare(strict_types=1);

namespace App\Domain\Analytics\DTO;

/**
 * Single labeled cell for deferred revenue panels (COR bridge, unit economics).
 * {@see $value} is null until a {@see \App\Domain\Analytics\Contracts\CorMarginBridgeDataPort}
 * (or unit-economics port) returns real numbers — never fabricated in admin v1.
 */
final readonly class RevenuePresentationSlot
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $value,
        public ?string $helper,
    ) {
    }
}
