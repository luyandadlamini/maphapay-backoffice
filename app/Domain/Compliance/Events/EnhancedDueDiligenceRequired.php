<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Events;

use App\Domain\Compliance\Models\CustomerRiskProfile;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EnhancedDueDiligenceRequired
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly CustomerRiskProfile $profile
    ) {
    }
}
