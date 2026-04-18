<?php
declare(strict_types=1);
namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property string $id
 * @property string $name
 * @property string $description
 * @property int    $points_cost
 * @property string $type
 * @property array<string, mixed>|null $metadata
 * @property int    $stock
 * @property bool   $is_active
 * @property int    $min_permission_level
 *
 * @method static Builder<self> active()
 */
class MinorReward extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $guarded = [];

    protected $casts = [
        'points_cost'          => 'integer',
        'stock'                => 'integer',
        'is_active'            => 'boolean',
        'min_permission_level' => 'integer',
        'metadata'             => 'array',
    ];

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasStock(): bool
    {
        return $this->stock === -1 || $this->stock > 0;
    }
}
