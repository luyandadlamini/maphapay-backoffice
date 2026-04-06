<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Events;

use App\Domain\Compliance\Models\CustomerRiskProfile;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RiskLevelChanged
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly CustomerRiskProfile $profile,
        public readonly string $oldLevel,
        public readonly string $newLevel
    ) {
    }
}
