<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateVirtualCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nickname' => ['required', 'string', 'max:255'],
            'lifecycle' => ['required', 'string'],
            'lifecycle_config' => ['nullable', 'array'],
            'controls' => ['required', 'array'],
            'controls.per_transaction_limit' => ['required', 'numeric', 'min:0'],
            'controls.daily_limit' => ['required', 'numeric', 'min:0'],
            'controls.monthly_limit' => ['required', 'numeric', 'min:0'],
            'controls.online_enabled' => ['required', 'boolean'],
            'controls.international_enabled' => ['required', 'boolean'],
            'controls.blocked_mcc_groups' => ['nullable', 'array'],
            'controls.blocked_mcc_groups.*' => ['string'],
        ];
    }
}
