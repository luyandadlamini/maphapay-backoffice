<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NoControlCharacters implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // Check for control characters
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            $fail('The :attribute field contains invalid control characters.');

            return;
        }

        // Check for zero-width characters
        if (preg_match('/[\x{200B}-\x{200D}\x{FEFF}]/u', $value)) {
            $fail('The :attribute field contains invalid zero-width characters.');

            return;
        }

        // Check for direction override characters
        if (preg_match('/[\x{202A}-\x{202E}]/u', $value)) {
            $fail('The :attribute field contains invalid direction override characters.');

            return;
        }
    }
}
