<?php
declare(strict_types=1);
namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $minor_account_uuid
 * @property string $minor_reward_id
 * @property int    $points_cost
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $fulfilled_at
 */
class MinorRewardRedemption extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $guarded = [];

    protected $casts = [
        'points_cost'  => 'integer',
        'fulfilled_at' => 'datetime',
    ];

    public function reward(): BelongsTo
    {
        return $this->belongsTo(MinorReward::class, 'minor_reward_id', 'id');
    }

    public function minorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_uuid', 'uuid');
    }
}
