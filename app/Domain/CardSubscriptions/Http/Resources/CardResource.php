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
            'mask' => $this->getMaskedNumber(),
            'last4' => $this->last4,
            'network' => $this->network,
            'status' => $this->status,
            'status_label' => ucfirst($this->status),
            'kind' => $this->kind,
            'label' => $this->label,
            'currency' => $this->currency,
            'controls' => [
                'limits' => [
                    'per_transaction' => $this->per_transaction_limit ?? '0.00',
                    'daily' => $this->daily_limit ?? '0.00',
                    'monthly' => $this->monthly_limit ?? '0.00',
                ],
                'online_enabled' => (bool) $this->online_enabled,
                'international_enabled' => (bool) $this->international_enabled,
                'atm_enabled' => (bool) $this->atm_enabled,
                'contactless_enabled' => (bool) $this->contactless_enabled,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
