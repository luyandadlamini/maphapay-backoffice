<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PhysicalCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_method' => ['required', 'string', 'in:branch_collection,courier'],
            'delivery_address' => ['required_if:delivery_method,courier', 'array'],
            'delivery_address.line1' => ['required_with:delivery_address', 'string'],
            'delivery_address.line2' => ['nullable', 'string'],
            'delivery_address.city' => ['required_with:delivery_address', 'string'],
            'delivery_address.country' => ['required_with:delivery_address', 'string'],
            'delivery_address.phone_number' => ['required_with:delivery_address', 'string'],
            'collection_point_id' => ['required_if:delivery_method,branch_collection', 'uuid', 'nullable'],
        ];
    }
}
