<?php

declare(strict_types=1);

namespace App\Domain\Fraud\Events;

use App\Domain\Account\Models\Transaction;
use App\Domain\Fraud\Models\FraudScore;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionBlocked
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public Transaction $transaction;

    public FraudScore $fraudScore;

    public function __construct(Transaction $transaction, FraudScore $fraudScore)
    {
        $this->transaction = $transaction;
        $this->fraudScore = $fraudScore;
    }

    /**
     * Get the tags that should be assigned to the event.
     */
    public function tags(): array
    {
        return [
            'fraud',
            'transaction_blocked',
            'transaction:' . $this->transaction->id,
            'fraud_score:' . $this->fraudScore->id,
            'risk_level:' . $this->fraudScore->risk_level,
        ];
    }
}
