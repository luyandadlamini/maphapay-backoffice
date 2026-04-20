<?php
declare(strict_types=1);
namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\MerchantPartner;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $name
 * @property string|null $category
 * @property string $description
 * @property string|null $image_url
 * @property int    $points_cost
 * @property int|null $price_points
 * @property string $type
 * @property array<string, mixed>|null $metadata
 * @property int    $stock
 * @property bool   $is_active
 * @property bool   $is_featured
 * @property int|null $partner_id
 * @property string|null $expiry_date
 * @property string|null $age_restriction
 * @property int    $min_permission_level
 *
 * @method static Builder<self> active()
 * @method static Builder<self> featured()
 */
class MinorReward extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'minor_rewards';

    protected $fillable = [
        'name',
        'category',
        'description',
        'image_url',
        'points_cost',
        'price_points',
        'type',
        'metadata',
        'stock',
        'is_active',
        'is_featured',
        'partner_id',
        'expiry_date',
        'age_restriction',
        'min_permission_level',
    ];

    protected $casts = [
        'points_cost'          => 'integer',
        'price_points'         => 'integer',
        'stock'                => 'integer',
        'is_active'            => 'boolean',
        'is_featured'          => 'boolean',
        'min_permission_level' => 'integer',
        'expiry_date'          => 'datetime',
        'metadata'             => 'array',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(MerchantPartner::class, 'partner_id');
    }

    public function hasStock(): bool
    {
        return $this->stock === -1 || $this->stock > 0;
    }
}
