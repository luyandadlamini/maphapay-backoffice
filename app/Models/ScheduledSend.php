<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scheduled P2P send (MaphaPay compatibility). Transfer runs after OTP/PIN verification.
 *
 * @property string                      $id
 * @property int                         $sender_user_id
 * @property int                         $recipient_user_id
 * @property string                      $amount
 * @property string                      $asset_code
 * @property string|null                 $note
 * @property \Carbon\Carbon              $scheduled_for
 * @property string                      $status
 * @property string|null                 $trx
 * @property \Carbon\Carbon              $created_at
 * @property \Carbon\Carbon              $updated_at
 */
class ScheduledSend extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'scheduled_sends';

    protected $fillable = [
        'id',
        'sender_user_id',
        'recipient_user_id',
        'amount',
        'asset_code',
        'note',
        'scheduled_for',
        'status',
        'trx',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
