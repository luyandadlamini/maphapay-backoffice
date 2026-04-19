<?php
declare(strict_types=1);
namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $minor_account_uuid
 * @property string $guardian_account_uuid
 * @property string $title
 * @property string|null $description
 * @property string $payout_type
 * @property int $payout_points
 * @property float|null $payout_amount
 * @property \Illuminate\Support\Carbon|null $due_at
 * @property string|null $recurrence
 * @property string $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static Builder<self> active()
 */
class MinorChore extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $guarded = [];

    protected $casts = [
        'payout_points' => 'integer',
        'due_at'        => 'datetime',
    ];

    /**
     * @return BelongsTo<Account, self>
     */
    public function minorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<Account, self>
     */
    public function guardianAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'guardian_account_uuid', 'uuid');
    }

    /**
     * @return HasMany<MinorChoreCompletion>
     */
    public function completions(): HasMany
    {
        return $this->hasMany(MinorChoreCompletion::class, 'chore_id', 'id');
    }

    /**
     * @return HasOne<MinorChoreCompletion>
     */
    public function latestPendingCompletion(): HasOne
    {
        return $this->hasOne(MinorChoreCompletion::class, 'chore_id', 'id')
            ->where('status', 'pending_review')
            ->latestOfMany();
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
