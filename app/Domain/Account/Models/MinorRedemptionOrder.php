<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $minor_account_id
 * @property int $reward_id
 * @property string $status
 * @property int $points_redeemed
 * @property int $quantity
 * @property int|null $shipping_address_id
 * @property string|null $delivery_method
 * @property string|null $merchant_reference
 * @property string|null $tracking_number
 * @property string|null $child_phone_number
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class MinorRedemptionOrder extends Model
{
    use UsesTenantConnection;

    protected $table = 'minor_reward_redemptions';

    protected $fillable = [
        'minor_account_id',
        'reward_id',
        'status',
        'points_redeemed',
        'quantity',
        'shipping_address_id',
        'delivery_method',
        'merchant_reference',
        'tracking_number',
        'child_phone_number',
        'expires_at',
        'completed_at',
    ];

    protected $casts = [
        'points_redeemed' => 'integer',
        'quantity'        => 'integer',
        'expires_at'      => 'datetime',
        'completed_at'    => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function minorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_id');
    }

    /**
     * @return BelongsTo<MinorReward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(MinorReward::class, 'reward_id');
    }

    /**
     * @return HasOne<MinorRedemptionApproval, $this>
     */
    public function approval(): HasOne
    {
        return $this->hasOne(MinorRedemptionApproval::class, 'redemption_id');
    }
}
