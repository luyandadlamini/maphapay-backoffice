<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Pricing\Enums\FeeFormula;
use App\Domain\Pricing\Enums\PricingRuleStatus;
use App\Domain\Segments\Models\CustomerSegment;
use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pricing rule: defines how fees are calculated for a product, optionally scoped to a customer segment.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $segment_id
 * @property FeeFormula $formula
 * @property PricingRuleStatus $status
 * @property array<string, mixed> $config
 * @property array<string, mixed>|null $geo_scope
 * @property array<string, mixed>|null $experiment_split
 * @property \Illuminate\Support\Carbon|null $effective_from
 * @property \Illuminate\Support\Carbon|null $effective_to
 * @property PricingProduct|null $product
 */
class PricingRule extends Model
{
    use UsesTenantConnection;

    protected $table = 'pricing_rules';

    protected $fillable = [
        'product_id',
        'segment_id',
        'formula',
        'status',
        'config',
        'geo_scope',
        'experiment_split',
        'effective_from',
        'effective_to',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'formula'          => FeeFormula::class,
        'status'           => PricingRuleStatus::class,
        'config'           => 'array',
        'geo_scope'        => 'array',
        'experiment_split' => 'array',
        'effective_from'   => 'datetime',
        'effective_to'     => 'datetime',
    ];

    /**
     * @return BelongsTo<PricingProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(PricingProduct::class, 'product_id');
    }

    /**
     * @return BelongsTo<CustomerSegment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(CustomerSegment::class, 'segment_id');
    }

    /**
     * @return HasMany<PricingRuleVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PricingRuleVersion::class, 'pricing_rule_id');
    }

    /**
     * Scope: returns only active rules within their effective date range.
     *
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('status', PricingRuleStatus::Active)
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
