<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use App\Domain\Shared\Contracts\CarriesTenantContext;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class RedemptionApproved extends ShouldBeStored implements CarriesTenantContext
{
    public function __construct(
        public readonly string $redemptionId,
        public readonly string $minorAccountUuid,
        public readonly string $guardianAccountUuid,
        public readonly int $pointsCost,
    ) {
    }

    public function tenantAccountUuid(): string
    {
        return $this->minorAccountUuid;
    }
}
