<?php

namespace App\Http\Requests\Accounting\Currency;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correct an exchange rate that has not yet priced a document.
 *
 * The currency pair is deliberately absent from the rules: a rate's direction is
 * what the row means, and changing it in place would silently repoint documents.
 * A correction re-records the rate or its date; a pair that was entered backwards is
 * a new row.
 *
 * Whether the rate may be edited at all is decided by ExchangeRateService, which
 * refuses once the rate has priced a document - a rule that needs the documents, so
 * it cannot live in a request.
 */
class UpdateExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('exchangeRate'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rate' => ['sometimes', 'numeric', 'gt:0'],
            'effective_date' => ['sometimes', 'date_format:Y-m-d'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rate.gt' => 'An exchange rate must be greater than zero.',
            'effective_date.date_format' => 'The effective date must be in YYYY-MM-DD format.',
        ];
    }
}
