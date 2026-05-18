<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\CardSubscriptions\Models\CardPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CardPlanSeeder extends Seeder
{
    /**
     * Seed all card plans.
     *
     * Values are the source of truth from docs/cards/01-product-config.md §1.
     * CI compares seeder output to that doc on every PR — drift fails the build.
     */
    public function run(): void
    {
        $plans = [
            ['code' => 'FREE_WALLET',      'name' => 'Free Wallet',       'monthly_fee' => '0.00',   'max_virtual_cards' => 0, 'max_physical_cards' => 0, 'monthly_card_creation_limit' => 0, 'free_virtual_reissues_per_month' => 0, 'virtual_card_replacement_fee' => '0.00',  'monthly_card_spend_limit' => '0.00',     'daily_card_spend_limit' => '0.00',     'single_transaction_limit' => '0.00',     'atm_enabled' => false, 'atm_daily_limit' => '0.00',    'atm_monthly_limit' => '0.00',    'atm_fixed_fee' => '0.00',  'atm_percentage_fee_bps' => 0,   'fx_markup_bps' => 0,   'physical_card_issuance_fee' => '0.00',   'physical_card_replacement_fee' => '0.00',  'eligibility' => 'adult', 'active' => true],
            ['code' => 'VIRTUAL_LITE',     'name' => 'Virtual Card Lite', 'monthly_fee' => '25.00',  'max_virtual_cards' => 1, 'max_physical_cards' => 0, 'monthly_card_creation_limit' => 1, 'free_virtual_reissues_per_month' => 0, 'virtual_card_replacement_fee' => '15.00', 'monthly_card_spend_limit' => '3000.00',  'daily_card_spend_limit' => '1500.00',  'single_transaction_limit' => '1500.00',  'atm_enabled' => false, 'atm_daily_limit' => '0.00',    'atm_monthly_limit' => '0.00',    'atm_fixed_fee' => '0.00',  'atm_percentage_fee_bps' => 0,   'fx_markup_bps' => 350, 'physical_card_issuance_fee' => '0.00',   'physical_card_replacement_fee' => '0.00',  'eligibility' => 'adult', 'active' => true],
            ['code' => 'VIRTUAL_PLUS',     'name' => 'Virtual Card Plus', 'monthly_fee' => '50.00',  'max_virtual_cards' => 3, 'max_physical_cards' => 0, 'monthly_card_creation_limit' => 2, 'free_virtual_reissues_per_month' => 1, 'virtual_card_replacement_fee' => '20.00', 'monthly_card_spend_limit' => '15000.00', 'daily_card_spend_limit' => '7500.00',  'single_transaction_limit' => '5000.00',  'atm_enabled' => false, 'atm_daily_limit' => '0.00',    'atm_monthly_limit' => '0.00',    'atm_fixed_fee' => '0.00',  'atm_percentage_fee_bps' => 0,   'fx_markup_bps' => 300, 'physical_card_issuance_fee' => '0.00',   'physical_card_replacement_fee' => '0.00',  'eligibility' => 'adult', 'active' => true],
            ['code' => 'PHYSICAL_CARD',    'name' => 'Physical Card',     'monthly_fee' => '65.00',  'max_virtual_cards' => 3, 'max_physical_cards' => 1, 'monthly_card_creation_limit' => 2, 'free_virtual_reissues_per_month' => 1, 'virtual_card_replacement_fee' => '20.00', 'monthly_card_spend_limit' => '25000.00', 'daily_card_spend_limit' => '10000.00', 'single_transaction_limit' => '7500.00',  'atm_enabled' => true,  'atm_daily_limit' => '1500.00', 'atm_monthly_limit' => '5000.00', 'atm_fixed_fee' => '12.00', 'atm_percentage_fee_bps' => 150, 'fx_markup_bps' => 275, 'physical_card_issuance_fee' => '120.00', 'physical_card_replacement_fee' => '90.00', 'eligibility' => 'adult', 'active' => true],
            ['code' => 'PREMIUM_CARD',     'name' => 'Premium Card',      'monthly_fee' => '120.00', 'max_virtual_cards' => 5, 'max_physical_cards' => 1, 'monthly_card_creation_limit' => 4, 'free_virtual_reissues_per_month' => 2, 'virtual_card_replacement_fee' => '20.00', 'monthly_card_spend_limit' => '60000.00', 'daily_card_spend_limit' => '25000.00', 'single_transaction_limit' => '15000.00', 'atm_enabled' => true,  'atm_daily_limit' => '3000.00', 'atm_monthly_limit' => '10000.00', 'atm_fixed_fee' => '8.00',  'atm_percentage_fee_bps' => 100, 'fx_markup_bps' => 175, 'physical_card_issuance_fee' => '0.00',   'physical_card_replacement_fee' => '60.00', 'eligibility' => 'adult', 'active' => true],
            ['code' => 'MINOR_KHULA_CARD', 'name' => 'Khula',             'monthly_fee' => '20.00',  'max_virtual_cards' => 1, 'max_physical_cards' => 0, 'monthly_card_creation_limit' => 1, 'free_virtual_reissues_per_month' => 0, 'virtual_card_replacement_fee' => '15.00', 'monthly_card_spend_limit' => '2000.00',  'daily_card_spend_limit' => '500.00',   'single_transaction_limit' => '500.00',   'atm_enabled' => false, 'atm_daily_limit' => '0.00',    'atm_monthly_limit' => '0.00',    'atm_fixed_fee' => '0.00',  'atm_percentage_fee_bps' => 0,   'fx_markup_bps' => 350, 'physical_card_issuance_fee' => '0.00',   'physical_card_replacement_fee' => '0.00',  'eligibility' => 'minor', 'active' => true],
        ];

        foreach ($plans as $plan) {
            CardPlan::updateOrCreate(
                ['code' => $plan['code']],
                array_merge(['id' => (string) Str::uuid()], $plan),
            );
        }
    }
}
