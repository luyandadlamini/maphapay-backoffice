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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'recipient_name' => $this->recipientName,
            'phone'          => $this->phone,
            'address_line1'  => $this->addressLine1,
            'address_line2'  => $this->addressLine2,
            'city'           => $this->city,
            'region'         => $this->region,
            'country_code'   => $this->countryCode,
            'postal_code'    => $this->postalCode,
            'metadata'       => $this->metadata,
        ];
    }
}
