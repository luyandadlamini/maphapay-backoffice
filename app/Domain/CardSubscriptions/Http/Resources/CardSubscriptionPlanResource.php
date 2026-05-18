<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CardSubscriptionPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'code'                            => $this->code,
            'name'                            => $this->name,
            'monthly_fee'                     => $this->monthly_fee,
            'currency'                        => $this->currency,
            'max_virtual_cards'               => $this->max_virtual_cards,
            'max_physical_cards'              => $this->max_physical_cards,
            'monthly_card_creation_limit'     => $this->monthly_card_creation_limit,
            'monthly_card_spend_limit'        => $this->monthly_card_spend_limit,
            'single_transaction_limit'        => $this->single_transaction_limit,
            'daily_card_spend_limit'          => $this->daily_card_spend_limit,
            'atm_enabled'                     => $this->atm_enabled,
            'atm_daily_limit'                 => $this->atm_daily_limit,
            'atm_monthly_limit'               => $this->atm_monthly_limit,
            'atm_fixed_fee'                   => $this->atm_fixed_fee,
            'atm_percentage_fee_bps'          => $this->atm_percentage_fee_bps,
            'fx_markup_bps'                   => $this->fx_markup_bps,
            'physical_card_issuance_fee'      => $this->physical_card_issuance_fee,
            'physical_card_replacement_fee'   => $this->physical_card_replacement_fee,
            'virtual_card_replacement_fee'    => $this->virtual_card_replacement_fee,
            'free_virtual_reissues_per_month' => $this->free_virtual_reissues_per_month,
            'eligibility'                     => $this->eligibility,
            'features'                        => $this->features ?? [],
        ];
    }
}
