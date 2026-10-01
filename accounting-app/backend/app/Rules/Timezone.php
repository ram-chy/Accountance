<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an IANA timezone identifier.
 *
 * Checked against PHP's own timezone database rather than a bundled list, so
 * a valid identifier cannot be rejected because the application copy is out of
 * date. `date_default_timezone_get` style offsets and abbreviations are
 * deliberately not accepted: they are not IANA zone names and are ambiguous.
 */
class Timezone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! in_array($value, \DateTimeZone::listIdentifiers(), true)) {
            $fail('The :attribute must be a valid IANA timezone identifier, for example Europe/London.');
        }
    }

    public function message(): string
    {
        return 'The :attribute must be a valid IANA timezone identifier.';
    }
}
