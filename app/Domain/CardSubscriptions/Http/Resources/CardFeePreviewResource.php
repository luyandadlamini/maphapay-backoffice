<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Resources;

use App\Domain\CardSubscriptions\ValueObjects\CardFeePreview;
use Illuminate\Http\Resources\Json\JsonResource;

class CardFeePreviewResource extends JsonResource
{
    /** @var CardFeePreview */
    public $resource;

    public function toArray($request): array
    {
        return [
            'subtotal_cents'      => $this->resource->subtotalCents,
            'total_fee_cents'     => $this->resource->totalFeeCents,
            'total_cents'         => $this->resource->totalCents,
            'currency'            => $this->resource->currency,
            'fee_breakdown_cents' => $this->resource->feeBreakdownCents,
        ];
    }
}
