<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PhysicalCardActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'activation_code' => ['required', 'string', 'size:6'],
            'pin' => ['required', 'string', 'size:4'],
        ];
    }
}
