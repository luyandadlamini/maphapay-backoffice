<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CardSubscriptionResource extends JsonResource
{
    public function toArray($request): array
    {
        if (is_null($this->resource)) {
            return [];
        }

        return [
            'id' => $this->id,
            'subscriber_user_id' => $this->subscriber_user_id,
            'payer_user_id' => $this->payer_user_id,
            'plan' => new CardSubscriptionPlanResource($this->whenLoaded('plan')),
            'status' => $this->status,
            'current_period_start' => $this->current_period_start?->toIso8601String(),
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'next_billing_date' => $this->next_billing_date?->toIso8601String(),
            'failed_payment_count' => $this->failed_payment_count,
            'grace_period_ends_at' => $this->grace_period_ends_at?->toIso8601String(),
            'is_minor_subscription' => (bool) $this->is_minor_subscription,
            'guardian_user_id' => $this->guardian_user_id,
        ];
    }
}
