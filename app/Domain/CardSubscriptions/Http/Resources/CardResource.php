<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CardResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'cardholder_id' => $this->cardholder_id,
            'card_type' => $this->card_type,
            'card_brand' => $this->card_brand,
            'last4' => $this->last4,
            'expiry_month' => $this->expiry_month,
            'expiry_year' => $this->expiry_year,
            'status' => $this->status,
            'nickname' => $this->nickname,
            'is_primary' => (bool) $this->is_primary,
            'tier' => $this->tier,
            'lifecycle' => $this->lifecycle,
            'lifecycle_config' => $this->lifecycle_config,
            'minor_account_uuid' => $this->minor_account_uuid,
            'controls' => $this->controls ?? [
                'per_transaction_limit' => $this->per_transaction_limit ?? '0.00',
                'daily_limit' => $this->daily_limit ?? '0.00',
                'monthly_limit' => $this->monthly_limit ?? '0.00',
                'online_enabled' => (bool) ($this->online_enabled ?? true),
                'international_enabled' => (bool) ($this->international_enabled ?? true),
                'atm_enabled' => (bool) ($this->atm_enabled ?? false),
                'contactless_enabled' => (bool) ($this->contactless_enabled ?? false),
                'blocked_mcc_groups' => $this->blocked_mcc_groups ?? [],
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
