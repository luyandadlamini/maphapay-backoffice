<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $expiresAt = $this->expires_at;

        return [
            'id' => (string) $this->id,
            'user_id' => (string) $this->user_id,
            'cardholder_id' => (string) $this->cardholder_id,
            'card_type' => $this->kind ?? 'virtual',
            'card_brand' => $this->network,
            'last4' => $this->last4,
            'expiry_month' => $expiresAt?->month,
            'expiry_year' => $expiresAt?->year,
            'status' => $this->status,
            'nickname' => $this->label,
            'is_primary' => (bool) $this->is_default,
            'tier' => $this->tier ?? 'standard',
            'lifecycle' => $this->lifecycle ?? 'standard',
            'lifecycle_config' => $this->lifecycle_config,
            'minor_account_uuid' => $this->minor_account_uuid,
            'controls' => [
                'per_transaction_limit' => $this->asMoneyString($this->per_transaction_limit),
                'daily_limit' => $this->asMoneyString($this->daily_limit),
                'monthly_limit' => $this->asMoneyString($this->monthly_limit),
                'online_enabled' => (bool) $this->online_enabled,
                'international_enabled' => (bool) $this->international_enabled,
                'atm_enabled' => (bool) $this->atm_enabled,
                'contactless_enabled' => (bool) $this->contactless_enabled,
                'blocked_mcc_groups' => $this->blocked_mcc_groups ?? [],
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function asMoneyString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
