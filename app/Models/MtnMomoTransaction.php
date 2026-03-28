<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property string $idempotency_key
 * @property string $type
 * @property string $amount
 * @property string $currency
 * @property string $status
 * @property string $party_msisdn
 * @property string|null $mtn_reference_id
 * @property string|null $mtn_financial_transaction_id
 * @property string|null $note
 * @property string|null $last_mtn_status
 * @property \Illuminate\Support\Carbon|null $wallet_credited_at
 * @property \Illuminate\Support\Carbon|null $wallet_debited_at
 * @property \Illuminate\Support\Carbon|null $wallet_refunded_at
 */
class MtnMomoTransaction extends Model
{
    public const TYPE_REQUEST_TO_PAY = 'request_to_pay';

    public const TYPE_DISBURSEMENT = 'disbursement';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_FAILED = 'failed';

    public static function normaliseRemoteStatus(?string $remote): string
    {
        if ($remote === null || $remote === '') {
            return self::STATUS_PENDING;
        }

        $u = strtoupper(trim($remote));

        if (in_array($u, ['SUCCESSFUL', 'SUCCESS', 'COMPLETED'], true)) {
            return self::STATUS_SUCCESSFUL;
        }

        if (in_array($u, ['FAILED', 'REJECTED', 'CANCELLED'], true)) {
            return self::STATUS_FAILED;
        }

        return self::STATUS_PENDING;
    }

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'idempotency_key',
        'type',
        'amount',
        'currency',
        'status',
        'party_msisdn',
        'mtn_reference_id',
        'mtn_financial_transaction_id',
        'note',
        'last_mtn_status',
        'wallet_credited_at',
        'wallet_debited_at',
        'wallet_refunded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wallet_credited_at' => 'datetime',
            'wallet_debited_at'  => 'datetime',
            'wallet_refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
