<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two-step money-movement gate.
 *
 * Step 1 — initiation endpoint stores the operation here and sends OTP/prompts PIN.
 * Step 2 — verification endpoint validates OTP/PIN, dispatches the correct handler,
 *           and marks status = completed atomically to prevent double-execution.
 *
 * @property string                      $id
 * @property int                         $user_id
 * @property string                      $remark
 * @property string                      $trx
 * @property array<string, mixed>        $payload
 * @property string                      $status
 * @property array<string, mixed>|null   $result
 * @property string|null                 $verification_type
 * @property string|null                 $otp_hash
 * @property \Carbon\Carbon|null         $otp_sent_at
 * @property \Carbon\Carbon|null         $otp_expires_at
 * @property string|null                 $failure_reason
 * @property \Carbon\Carbon|null         $expires_at
 * @property \Carbon\Carbon              $created_at
 * @property \Carbon\Carbon              $updated_at
 */
class AuthorizedTransaction extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const REMARK_SEND_MONEY = 'send_money';

    public const REMARK_SCHEDULED_SEND = 'scheduled_send';

    public const REMARK_REQUEST_MONEY = 'request_money';

    public const REMARK_REQUEST_MONEY_RECEIVED = 'request_money_received';

    public const VERIFICATION_OTP = 'otp';

    public const VERIFICATION_PIN = 'pin';

    public const VERIFICATION_NONE = 'none';

    protected $table = 'authorized_transactions';

    protected $fillable = [
        'user_id',
        'remark',
        'trx',
        'payload',
        'status',
        'result',
        'verification_type',
        'otp_hash',
        'otp_sent_at',
        'otp_expires_at',
        'failure_reason',
        'expires_at',
    ];

    protected $casts = [
        'payload'        => 'array',
        'result'         => 'array',
        'otp_sent_at'    => 'datetime',
        'otp_expires_at' => 'datetime',
        'expires_at'     => 'datetime',
    ];

    /** @return BelongsTo<\App\Models\User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    public function isOtpExpired(): bool
    {
        return $this->otp_expires_at !== null && $this->otp_expires_at->isPast();
    }
}
