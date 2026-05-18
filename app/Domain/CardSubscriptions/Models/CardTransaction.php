<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Models;

use App\Domain\CardIssuance\Models\Card;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Persisted card transaction record.
 *
 * Written at authorisation time; updated to `settled` on clearing webhook,
 * `reversed` on reversal webhook, and `refunded` on refund webhook.
 *
 * @property int|string       $id
 * @property string|null      $card_id
 * @property int              $user_id
 * @property string|null      $processor_transaction_id   Maps to card_transactions.external_id
 * @property string|null      $authorization_id
 * @property string           $merchant_name
 * @property string           $merchant_category
 * @property int              $amount                     Amount in minor units (cents)
 * @property string           $currency
 * @property string           $status                     authorised|settled|reversed|refunded|declined
 * @property string|null      $billing_amount             Actual settled amount (may differ from auth)
 * @property string|null      $refunded_amount
 * @property Carbon|null      $settled_at
 * @property Carbon|null      $reversed_at
 * @property Carbon|null      $refunded_at
 * @property Carbon|null      $created_at
 * @property Carbon|null      $updated_at
 */
class CardTransaction extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\CardSubscriptions\Models\CardTransactionFactory> */
    use HasFactory;

    protected $table = 'card_transactions';

    protected $fillable = [
        'card_id',
        'user_id',
        'external_id',
        'processor_transaction_id',
        'authorization_id',
        'merchant_name',
        'merchant_category',
        'amount_cents',
        'currency',
        'status',
        'billing_amount',
        'refunded_amount',
        'settled_at',
        'reversed_at',
        'refunded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settled_at'   => 'datetime',
            'reversed_at'  => 'datetime',
            'refunded_at'  => 'datetime',
            'amount_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<Card, self> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /** @return BelongsTo<User, self> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
