<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCardControlsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_transaction_limit' => ['nullable', 'numeric', 'min:0'],
            'daily_limit' => ['nullable', 'numeric', 'min:0'],
            'monthly_limit' => ['nullable', 'numeric', 'min:0'],
            'online_enabled' => ['nullable', 'boolean'],
            'international_enabled' => ['nullable', 'boolean'],
            'atm_enabled' => ['nullable', 'boolean'],
            'contactless_enabled' => ['nullable', 'boolean'],
            'blocked_mcc_groups' => ['nullable', 'array'],
            'blocked_mcc_groups.*' => ['string'],
        ];
    }
}
