<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CardFeePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_type' => ['required', 'string', 'in:online_purchase,atm_withdrawal,physical_card_issuance,physical_card_replacement,virtual_card_replacement'],
            'amount'           => ['required', 'numeric', 'min:0'],
            'currency'         => ['required', 'string', 'size:3'],
            'billing_currency' => ['required', 'string', 'size:3'],
        ];
    }
}
