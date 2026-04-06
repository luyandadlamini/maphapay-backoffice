<?php

declare(strict_types=1);

namespace App\Domain\Fraud\Events;

use App\Domain\Fraud\Models\FraudScore;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FraudDetected
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public FraudScore $fraudScore;

    public function __construct(FraudScore $fraudScore)
    {
        $this->fraudScore = $fraudScore;
    }

    /**
     * Get the tags that should be assigned to the event.
     */
    public function tags(): array
    {
        return [
            'fraud',
            'fraud_score:' . $this->fraudScore->id,
            'risk_level:' . $this->fraudScore->risk_level,
            'entity_type:' . class_basename($this->fraudScore->entity_type),
        ];
    }
}
