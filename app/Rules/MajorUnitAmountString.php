<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that an amount field is a non-negative numeric string in major units.
 *
 * Rejects PHP floats and integers at the type level — all money-moving
 * endpoints must receive amounts as strings to prevent float serialization
 * drift and idempotency key mismatches.
 *
 * Usage in a FormRequest:
 *   'amount' => ['required', 'string', new MajorUnitAmountString],
 *
 * Optional: enforce exact decimal count for strict endpoints:
 *   'amount' => ['required', 'string', new MajorUnitAmountString(decimals: 2)],
 */
final class MajorUnitAmountString implements ValidationRule
{
    public function __construct(
        /** If set, amount must have exactly this many decimal places. */
        private readonly ?int $decimals = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string (e.g. "25.10"), not a number.');

            return;
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            $fail('The :attribute must be a valid non-negative amount string (e.g. "25.10").');

            return;
        }

        // Negative values are already excluded by the regex (no minus sign allowed).

        if ($this->decimals !== null) {
            $decimalCount = strlen(explode('.', $value)[1] ?? '');
            if ($decimalCount !== $this->decimals) {
                $fail("The :attribute must have exactly {$this->decimals} decimal place(s) (e.g. \"25.10\").");
            }
        }
    }
}
