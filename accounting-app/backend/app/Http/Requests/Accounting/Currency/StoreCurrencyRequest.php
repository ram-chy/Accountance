<?php

namespace App\Http\Requests\Accounting\Currency;

use App\Models\Currency;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a currency.
 *
 * Currencies are global reference data, so there is no company context in the rules
 * and no company id to tamper with - the tenant boundary simply does not apply. The
 * capability check is the whole guard.
 *
 * Authorises with `can('create', Currency::class)` rather than a raw permission
 * string, so the decision reaches CurrencyPolicy rather than being answered inline.
 */
class StoreCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Currency::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:3', 'regex:/^[A-Za-z]{3}$/'],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:10'],
            /*
             * The permissive schema bound is 0-4; the range here is left slightly
             * wider so CurrencyService's own validation owns the rule and produces
             * its message, rather than two bounds that must be kept in step.
             */
            'decimal_precision' => ['sometimes', 'integer', 'min:0', 'max:9'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'A currency code must be exactly three letters, such as USD.',
        ];
    }
}
