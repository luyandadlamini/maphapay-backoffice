<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pricing scenario: a test configuration combining rules with custom overrides.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property array<string> $tags
 * @property array<string, mixed>|null $last_run_result
 * @property \Illuminate\Support\Carbon|null $last_run_at
 */
class PricingScenario extends Model
{
    use UsesTenantConnection;

    protected $table = 'pricing_scenarios';

    protected $fillable = [
        'name',
        'description',
        'tags',
        'last_run_result',
        'last_run_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tags'            => 'array',
        'last_run_result' => 'array',
        'last_run_at'     => 'datetime',
    ];

    /**
     * @return HasMany<PricingScenarioRule, $this>
     */
    public function scenarioRules(): HasMany
    {
        return $this->hasMany(PricingScenarioRule::class, 'scenario_id');
    }
}
