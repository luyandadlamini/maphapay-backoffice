<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Models\CardPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CardPlan>
 */
class CardPlanFactory extends Factory
{
    protected $model = CardPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code'                          => Str::upper(Str::random(12)),
            'name'                          => $this->faker->words(3, true),
            'monthly_fee'                   => '25.00',
            'max_virtual_cards'             => 1,
            'max_physical_cards'            => 0,
            'monthly_card_creation_limit'   => 1,
            'free_virtual_reissues_per_month' => 0,
            'virtual_card_replacement_fee'  => '15.00',
            'monthly_card_spend_limit'      => '3000.00',
            'daily_card_spend_limit'        => '1500.00',
            'single_transaction_limit'      => '1500.00',
            'atm_enabled'                   => false,
            'atm_daily_limit'               => '0.00',
            'atm_monthly_limit'             => '0.00',
            'atm_fixed_fee'                 => '0.00',
            'atm_percentage_fee_bps'        => 0,
            'fx_markup_bps'                 => 350,
            'physical_card_issuance_fee'    => '0.00',
            'physical_card_replacement_fee' => '0.00',
            'eligibility'                   => 'adult',
            'active'                        => true,
        ];
    }
}
