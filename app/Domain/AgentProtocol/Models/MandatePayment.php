<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Polymorphic join table linking mandates to payment records.
 *
 * Can reference either MppPayment or X402Payment via payment_type/payment_id.
 *
 * @property int         $id
 * @property string      $mandate_id
 * @property string      $payment_type
 * @property string      $payment_id
 * @property int         $amount_cents
 * @property string      $currency
 * @property string      $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class MandatePayment extends Model
{
    protected $table = 'mandate_payments';

    protected $fillable = [
        'mandate_id',
        'payment_type',
        'payment_id',
        'amount_cents',
        'currency',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AgentMandate, $this>
     */
    public function mandate(): BelongsTo
    {
        return $this->belongsTo(AgentMandate::class, 'mandate_id', 'uuid');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function payment(): MorphTo
    {
        return $this->morphTo('payment', 'payment_type', 'payment_id');
    }
}
