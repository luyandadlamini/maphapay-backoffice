<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MinorCardDenyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'denial_reason' => ['required', 'string', 'max:255'],
        ];
    }
}
