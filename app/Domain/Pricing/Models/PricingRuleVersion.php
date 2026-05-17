<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pricing rule version: audit trail of rule configuration changes.
 *
 * @property string $id
 * @property string $pricing_rule_id
 * @property int $version_number
 * @property array<string, mixed> $config_snapshot
 */
class PricingRuleVersion extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'pricing_rule_versions';

    public $timestamps = true;

    protected $fillable = [
        'pricing_rule_id',
        'version_number',
        'config_snapshot',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'config_snapshot' => 'array',
    ];

    /**
     * @return BelongsTo<PricingRule, $this>
     */
    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PricingRule::class, 'pricing_rule_id');
    }
}
