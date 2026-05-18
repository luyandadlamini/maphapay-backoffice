<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CardDisputeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'card_transaction_id' => $this->card_transaction_id,
            'reason'              => $this->reason,
            'description'         => $this->description,
            'disputed_amount'     => $this->disputed_amount,
            'status'              => $this->status,
            'created_at'          => $this->created_at?->toIso8601String(),
            'resolved_at'         => $this->resolved_at?->toIso8601String(),
        ];
    }
}
