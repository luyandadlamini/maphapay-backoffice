<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

final readonly class RequestPhysicalCardInput
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public PhysicalCardDeliveryAddress $deliveryAddress,
        public string $deliveryMethod,
        public ?CardControlsInput $controls = null,
        public ?string $collectionPointId = null,
        public array $metadata = [],
    ) {
    }
}
