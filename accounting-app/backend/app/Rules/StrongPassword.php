<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Minimum password policy for the application.
 *
 * Requirements (configurable via config/security.php):
 *  - minimum length
 *  - at least one lowercase letter
 *  - at least one uppercase letter
 *  - at least one digit
 *  - at least one symbol
 */
class StrongPassword implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $min = (int) config('security.password_min_length', 8);

        if (mb_strlen($value) < $min) {
            $fail("The :attribute must be at least {$min} characters.");

            return;
        }

        $rules = [
            'a lowercase letter' => '/\p{Ll}/u',
            'an uppercase letter' => '/\p{Lu}/u',
            'a number' => '/\d/',
            'a symbol' => '/[^\p{L}\d]/u',
        ];

        foreach ($rules as $label => $pattern) {
            if (! preg_match($pattern, $value)) {
                $fail("The :attribute must contain {$label}.");
            }
        }
    }

    /**
     * Human-readable summary of the policy, used in validation messages.
     */
    public static function describe(): string
    {
        $min = (int) config('security.password_min_length', 8);

        return "The password must be at least {$min} characters and contain a lowercase letter, an uppercase letter, a number and a symbol.";
    }
}
