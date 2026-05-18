<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Enums\CardSubscriptionBillingResult;
use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string                       $id
 * @property string|null                  $tenant_id
 * @property string                       $card_subscription_id
 * @property CardSubscriptionBillingResult $result
 * @property string|null                  $failure_reason
 * @property string                       $amount
 * @property string                       $currency
 * @property string|null                  $idempotency_key
 * @property string|null                  $ledger_posting_id
 * @property Carbon                       $attempted_at
 * @property Carbon                       $created_at
 * @property Carbon                       $updated_at
 */
class CardSubscriptionBillingAttempt extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\CardSubscriptions\Models\CardSubscriptionBillingAttemptFactory> */
    use HasFactory;
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'card_subscription_billing_attempts';

    protected $fillable = [
        'tenant_id',
        'card_subscription_id',
        'result',
        'failure_reason',
        'amount',
        'currency',
        'idempotency_key',
        'ledger_posting_id',
        'attempted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result'       => CardSubscriptionBillingResult::class,
            'amount'       => 'decimal:2',
            'attempted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CardSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CardSubscription::class, 'card_subscription_id');
    }
}
