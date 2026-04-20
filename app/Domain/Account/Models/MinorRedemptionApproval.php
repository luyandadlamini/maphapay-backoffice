<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $redemption_id
 * @property int $parent_account_id
 * @property string $status
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class MinorRedemptionApproval extends Model
{
    use UsesTenantConnection;

    protected $table = 'minor_redemption_approvals';

    protected $fillable = [
        'redemption_id',
        'parent_account_id',
        'status',
        'reason',
        'approved_at',
        'expires_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'expires_at'  => 'datetime',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    /**
     * @return BelongsTo<MinorRedemptionOrder, $this>
     */
    public function redemption(): BelongsTo
    {
        return $this->belongsTo(MinorRedemptionOrder::class, 'redemption_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_account_id');
    }
}
