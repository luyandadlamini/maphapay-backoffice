<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Pricing\Enums\PricingCategory;
use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pricing product: a service offering with fee rules and validity windows.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property PricingCategory $category
 * @property bool $active
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $effective_from
 * @property \Illuminate\Support\Carbon|null $effective_to
 */
class PricingProduct extends Model
{
    use UsesTenantConnection;

    protected $table = 'pricing_products';

    protected $fillable = [
        'code',
        'name',
        'category',
        'default_currency',
        'elasticity_bps_per_pct',
        'active',
        'effective_from',
        'effective_to',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'category'       => PricingCategory::class,
        'active'         => 'bool',
        'effective_from' => 'datetime',
        'effective_to'   => 'datetime',
    ];

    /**
     * @return HasMany<PricingRule, $this>
     */
    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class, 'pricing_product_id');
    }

    /**
     * Scope: returns only active products within their effective date range.
     *
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('active', true)
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $now);
            })
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $now);
            });
    }
}
