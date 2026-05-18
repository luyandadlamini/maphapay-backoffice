<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use App\Domain\Shared\Contracts\CarriesTenantContext;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PointsAwarded extends ShouldBeStored implements CarriesTenantContext
{
    public function __construct(
        public readonly string $minorAccountUuid,
        public readonly int $points,
        public readonly string $source,
        public readonly string $description,
        public readonly ?string $referenceId,
        public readonly ?string $actorUserUuid = null,
    ) {
    }

    public function tenantAccountUuid(): string
    {
        return $this->minorAccountUuid;
    }
}
