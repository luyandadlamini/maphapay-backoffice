<?php

declare(strict_types=1);

namespace App\Http\Requests\MobilePayment;

use App\Domain\MobilePayment\Enums\PaymentAsset;
use App\Domain\MobilePayment\Enums\PaymentNetwork;
use App\Rules\MajorUnitAmountString;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'merchantId' => ['required', 'string', 'max:64'],
            // Major-unit string only — MajorUnitAmountString rejects JSON number types (float/int).
            'amount' => [
                'required',
                'string',
                'max:64',
                new MajorUnitAmountString(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! is_numeric($value)) {
                        return;
                    }
                    $numericAmount = $value;
                    if (extension_loaded('bcmath')) {
                        if (bccomp($numericAmount, '0', 18) !== 1) {
                            $fail('Payment amount must be greater than zero.');
                        }
                    } elseif ((float) $numericAmount <= 0) {
                        $fail('Payment amount must be greater than zero.');
                    }
                },
            ],
            'asset'            => ['required', 'string', Rule::in(PaymentAsset::values())],
            'preferredNetwork' => ['required', 'string', Rule::in(PaymentNetwork::values())],
            'shield'           => ['sometimes', 'boolean'],
            'idempotencyKey'   => ['sometimes', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'asset.in'            => 'Only USDC is supported for v1.',
            'preferredNetwork.in' => 'Only SOLANA and TRON networks are supported for v1.',
        ];
    }
}
