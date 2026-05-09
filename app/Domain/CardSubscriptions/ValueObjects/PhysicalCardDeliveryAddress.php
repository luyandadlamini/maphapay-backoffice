<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

final readonly class PhysicalCardDeliveryAddress
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $recipientName,
        public string $phone,
        public string $addressLine1,
        public ?string $addressLine2,
        public string $city,
        public string $region,
        public string $countryCode = 'SZ',
        public ?string $postalCode = null,
        public array $metadata = [],
    ) {
    }
}
