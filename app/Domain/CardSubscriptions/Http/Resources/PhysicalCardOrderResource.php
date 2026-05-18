<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PhysicalCardOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'card_id'             => $this->card_id,
            'status'              => $this->order_status,
            'delivery_method'     => $this->delivery_method,
            'delivery_address'    => $this->delivery_address,
            'collection_point_id' => $this->collection_point_id,
            'issuance_fee'        => $this->issuance_fee,
            'delivery_fee'        => $this->delivery_fee,
            'tracking_reference'  => $this->tracking_reference,
            'requested_at'        => $this->requested_at?->toIso8601String(),
            'approved_at'         => $this->approved_at?->toIso8601String(),
            'dispatched_at'       => $this->dispatched_at?->toIso8601String(),
            'delivered_at'        => $this->delivered_at?->toIso8601String(),
            'activated_at'        => $this->activated_at?->toIso8601String(),
        ];
    }
}
