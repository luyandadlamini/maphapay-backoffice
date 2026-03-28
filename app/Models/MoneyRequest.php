<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2P money request (MaphaPay compatibility). Funds move only on accept flow.
 *
 * @property string                      $id
 * @property int                         $requester_user_id
 * @property int                         $recipient_user_id
 * @property string                      $amount
 * @property string                      $asset_code
 * @property string|null                 $note
 * @property string                      $status
 * @property string|null                 $trx
 * @property \Carbon\Carbon              $created_at
 * @property \Carbon\Carbon              $updated_at
 */
class MoneyRequest extends Model
{
    public const STATUS_AWAITING_OTP = 'awaiting_otp';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FULFILLED = 'fulfilled';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'money_requests';

    protected $fillable = [
        'id',
        'requester_user_id',
        'recipient_user_id',
        'amount',
        'asset_code',
        'note',
        'status',
        'trx',
    ];

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
