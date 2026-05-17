<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fee event: an assessed fee against a transaction with breakdown and rule reference.
 *
 * @property int $id
 * @property string|null $transaction_uuid
 * @property int|null $pricing_rule_id
 * @property string $product_code
 * @property string $category
 * @property int|null $user_id
 * @property int|null $segment_id
 * @property int $amount_minor
 * @property string $currency
 * @property array<string, mixed> $breakdown
 * @property \Illuminate\Support\Carbon $assessed_at
 * @property string|null $source_domain
 * @property string $idempotency_key
 * @property string|null $experiment_arm
 */
class FeeEvent extends Model
{
    use UsesTenantConnection;

    protected $table = 'fee_events';

    protected $fillable = [
        'transaction_uuid',
        'pricing_rule_id',
        'product_code',
        'category',
        'user_id',
        'segment_id',
        'amount_minor',
        'currency',
        'breakdown',
        'assessed_at',
        'source_domain',
        'idempotency_key',
        'experiment_arm',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'breakdown'   => 'array',
        'assessed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<PricingRule, $this>
     */
    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PricingRule::class, 'pricing_rule_id');
    }
}
