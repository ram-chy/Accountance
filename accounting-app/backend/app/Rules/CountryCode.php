<?php

namespace App\Rules;

use App\Config\CountryCodes;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an ISO 3166-1 alpha-2 country code.
 *
 * The value must be two letters and a real assigned code. The list lives in
 * App\Config\CountryCodes so the same set is used for validation and for any
 * future select input.
 */
class CountryCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z]{2}$/', $value)) {
            $fail('The :attribute must be a two-letter ISO 3166-1 country code.');

            return;
        }

        if (! CountryCodes::isValid($value)) {
            $fail('The :attribute must be a valid ISO 3166-1 country code.');
        }
    }

    public function message(): string
    {
        return 'The :attribute must be a valid ISO 3166-1 country code.';
    }
}
