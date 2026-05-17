<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pricing scenario rule: binds a scenario to a pricing rule with optional config overrides.
 *
 * @property int $id
 * @property int $scenario_id
 * @property int|null $pricing_rule_id
 * @property array<string, mixed> $config_override
 */
class PricingScenarioRule extends Model
{
    use UsesTenantConnection;

    protected $table = 'pricing_scenario_rules';

    protected $fillable = [
        'scenario_id',
        'pricing_rule_id',
        'config_override',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'config_override' => 'array',
    ];

    /**
     * @return BelongsTo<PricingScenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(PricingScenario::class, 'scenario_id');
    }

    /**
     * @return BelongsTo<PricingRule, $this>
     */
    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PricingRule::class, 'pricing_rule_id');
    }
}
