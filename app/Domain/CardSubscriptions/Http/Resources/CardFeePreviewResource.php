<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CardFeePreviewResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'amount' => $this->amount ?? $this['amount'],
            'currency' => $this->currency ?? $this['currency'],
            'estimated_billing_amount' => $this->estimated_billing_amount ?? $this['estimated_billing_amount'],
            'billing_currency' => $this->billing_currency ?? $this['billing_currency'],
            'fx_fee' => $this->fx_fee ?? $this['fx_fee'],
            'atm_fee' => $this->atm_fee ?? $this['atm_fee'],
            'issuance_fee' => $this->issuance_fee ?? $this['issuance_fee'],
            'replacement_fee' => $this->replacement_fee ?? $this['replacement_fee'],
            'total_debit' => $this->total_debit ?? $this['total_debit'],
        ];
    }
}
