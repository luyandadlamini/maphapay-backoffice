<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCardSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_code' => ['required', 'string'],
            'subscriber_user_id' => ['nullable', 'uuid'],
        ];
    }
}
