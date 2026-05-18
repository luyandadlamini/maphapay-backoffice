<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CardDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'card_id'         => ['required', 'string'],
            'reason'          => ['required', 'string', 'in:unrecognised,duplicate,wrong_amount,service_not_received,other'],
            'description'     => ['required', 'string', 'max:500'],
            'disputed_amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
