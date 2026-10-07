<?php

namespace App\Http\Requests\Accounting\Currency;

use App\Models\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a currency.
 *
 * PUT semantics: every field is optional and an absent field means "leave it alone".
 * `code` is validated for uniqueness ignoring the currency being updated, so renaming
 * a currency to its own code is not mistaken for a collision.
 *
 * `is_active` is deliberately absent. Activation and deactivation are separate,
 * separately-audited lifecycle acts. Letting them ride in on an update would go
 * through CurrencyService::update(), which does not check or record them.
 */
class UpdateCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('currency'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Currency $currency */
        $currency = $this->route('currency');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:3',
                'regex:/^[A-Za-z]{3}$/',
                Rule::unique('currencies', 'code')->ignore($currency->getKey()),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'symbol' => ['sometimes', 'nullable', 'string', 'max:10'],
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
