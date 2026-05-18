<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Enums\CardPlanEligibility;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string               $id
 * @property string               $code
 * @property string               $name
 * @property numeric-string       $monthly_fee
 * @property int                  $max_virtual_cards
 * @property int                  $max_physical_cards
 * @property int                  $monthly_card_creation_limit
 * @property int                  $free_virtual_reissues_per_month
 * @property string               $virtual_card_replacement_fee
 * @property string               $monthly_card_spend_limit
 * @property string               $daily_card_spend_limit
 * @property string               $single_transaction_limit
 * @property bool                 $atm_enabled
 * @property string               $atm_daily_limit
 * @property string               $atm_monthly_limit
 * @property string               $atm_fixed_fee
 * @property int                  $atm_percentage_fee_bps
 * @property int                  $fx_markup_bps
 * @property string               $physical_card_issuance_fee
 * @property string               $physical_card_replacement_fee
 * @property CardPlanEligibility  $eligibility
 * @property bool                 $active
 * @property Carbon               $created_at
 * @property Carbon               $updated_at
 */
class CardPlan extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\CardSubscriptions\Models\CardPlanFactory> */
    use HasFactory;
    use HasUuids;

    protected $connection = 'central';

    protected $table = 'card_plans';

    protected $fillable = [
        'code',
        'name',
        'monthly_fee',
        'max_virtual_cards',
        'max_physical_cards',
        'monthly_card_creation_limit',
        'free_virtual_reissues_per_month',
        'virtual_card_replacement_fee',
        'monthly_card_spend_limit',
        'daily_card_spend_limit',
        'single_transaction_limit',
        'atm_enabled',
        'atm_daily_limit',
        'atm_monthly_limit',
        'atm_fixed_fee',
        'atm_percentage_fee_bps',
        'fx_markup_bps',
        'physical_card_issuance_fee',
        'physical_card_replacement_fee',
        'eligibility',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_fee'                     => 'decimal:2',
            'max_virtual_cards'               => 'integer',
            'max_physical_cards'              => 'integer',
            'monthly_card_creation_limit'     => 'integer',
            'free_virtual_reissues_per_month' => 'integer',
            'virtual_card_replacement_fee'    => 'decimal:2',
            'monthly_card_spend_limit'        => 'decimal:2',
            'daily_card_spend_limit'          => 'decimal:2',
            'single_transaction_limit'        => 'decimal:2',
            'atm_enabled'                     => 'boolean',
            'atm_daily_limit'                 => 'decimal:2',
            'atm_monthly_limit'               => 'decimal:2',
            'atm_fixed_fee'                   => 'decimal:2',
            'atm_percentage_fee_bps'          => 'integer',
            'fx_markup_bps'                   => 'integer',
            'physical_card_issuance_fee'      => 'decimal:2',
            'physical_card_replacement_fee'   => 'decimal:2',
            'eligibility'                     => CardPlanEligibility::class,
            'active'                          => 'boolean',
        ];
    }

    /**
     * @return HasMany<CardSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(CardSubscription::class, 'card_plan_id');
    }
}
