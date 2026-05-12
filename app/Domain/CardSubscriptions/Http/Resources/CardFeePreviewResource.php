<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Resources;

use App\Domain\CardSubscriptions\ValueObjects\CardFeePreview;
use Illuminate\Http\Resources\Json\JsonResource;

class CardFeePreviewResource extends JsonResource
{
    /** @var CardFeePreview */
    public $resource;

    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $amount = $this->asMoneyString($this->resource->subtotalCents);
        $fxFee = $this->asMoneyString((int) ($this->resource->feeBreakdownCents['fx_markup'] ?? 0));
        $atmFee = $this->asMoneyString((int) ($this->resource->feeBreakdownCents['atm'] ?? 0));
        $issuanceFee = $this->asMoneyString((int) ($this->resource->feeBreakdownCents['issuance'] ?? 0));
        $replacementFee = $this->asMoneyString((int) ($this->resource->feeBreakdownCents['replacement'] ?? 0));

        return [
            'amount'                   => $amount,
            'currency'                 => $this->resource->currency,
            'estimated_billing_amount' => $amount,
            'billing_currency'         => 'SZL',
            'fx_fee'                   => $fxFee,
            'atm_fee'                  => $atmFee,
            'issuance_fee'             => $issuanceFee,
            'replacement_fee'          => $replacementFee,
            'total_debit'              => $this->asMoneyString($this->resource->totalCents),
        ];
    }

    private function asMoneyString(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
