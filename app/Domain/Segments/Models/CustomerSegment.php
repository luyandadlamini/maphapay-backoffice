<?php

declare(strict_types=1);

namespace App\Domain\Segments\Models;

use App\Domain\Segments\Enums\SegmentSource;
use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Customer segment for cohort-based targeting and analysis.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property SegmentSource $source
 * @property array<mixed>|null $rules
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $effective_from
 * @property \Illuminate\Support\Carbon|null $effective_to
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static Builder<static> active()
 */
class CustomerSegment extends Model
{
    use UsesTenantConnection;

    protected $table = 'customer_segments';

    protected $fillable = [
        'code',
        'name',
        'description',
        'source',
        'rules',
        'active',
        'effective_from',
        'effective_to',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'source'         => SegmentSource::class,
        'rules'          => 'array',
        'active'         => 'bool',
        'effective_from' => 'datetime',
        'effective_to'   => 'datetime',
    ];

    /**
     * @return HasMany<SegmentMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(SegmentMembership::class, 'segment_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            })
            ->where(function (Builder $q): void {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>', now());
            });
    }
}
