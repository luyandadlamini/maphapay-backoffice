<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CardTransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'card_id' => $this->card_id,
            'transaction_type' => $this->transaction_type,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'billing_amount' => $this->billing_amount,
            'billing_currency' => $this->billing_currency,
            'merchant_name' => $this->merchant_name,
            'merchant_country' => $this->merchant_country,
            'merchant_category_code' => $this->merchant_category_code,
            'fx_rate' => $this->fx_rate,
            'fx_fee' => $this->fx_fee,
            'mapha_fee' => $this->mapha_fee,
            'scheme_fee' => $this->scheme_fee,
            'decline_reason' => $this->decline_reason,
            'authorised_at' => $this->authorised_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
        ];
    }
}
