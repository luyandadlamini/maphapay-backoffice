<?php

declare(strict_types=1);

namespace App\Domain\Segments\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership record for a user in a customer segment.
 *
 * @property int $id
 * @property int $user_id
 * @property int $segment_id
 * @property \Illuminate\Support\Carbon $joined_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon $materialised_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static Builder<static> active()
 */
class SegmentMembership extends Model
{
    use UsesTenantConnection;

    protected $table = 'segment_memberships';

    protected $fillable = [
        'user_id',
        'segment_id',
        'joined_at',
        'expires_at',
        'materialised_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'joined_at'       => 'datetime',
        'expires_at'      => 'datetime',
        'materialised_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<CustomerSegment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(CustomerSegment::class, 'segment_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }
}
