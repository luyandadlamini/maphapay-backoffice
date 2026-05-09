<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

final readonly class CardControlsInput
{
    /**
     * @param list<string> $blockedMccGroups
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public CardLimitSet $limits,
        public bool $onlineEnabled = true,
        public bool $internationalEnabled = false,
        public bool $atmEnabled = false,
        public bool $contactlessEnabled = true,
        public array $blockedMccGroups = [],
        public array $metadata = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            limits: CardLimitSet::fromArray($data['limits'] ?? $data),
            onlineEnabled: (bool) ($data['online_enabled'] ?? true),
            internationalEnabled: (bool) ($data['international_enabled'] ?? false),
            atmEnabled: (bool) ($data['atm_enabled'] ?? false),
            contactlessEnabled: (bool) ($data['contactless_enabled'] ?? true),
            blockedMccGroups: array_values($data['blocked_mcc_groups'] ?? []),
            metadata: $data['metadata'] ?? [],
        );
    }
}
