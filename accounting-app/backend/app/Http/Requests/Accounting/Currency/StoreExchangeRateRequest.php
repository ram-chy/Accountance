<?php

namespace App\Http\Requests\Accounting\Currency;

use App\Models\ExchangeRate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Record a dated exchange rate for the active company.
 *
 * The company comes from CompanyContext, never the payload, so the rate can only be
 * created for the company the caller is working in. The one-row-per-pair-per-day
 * rule and the self-pair-at-1 rule are enforced by ExchangeRateService, not here,
 * because both need the resolved currencies and must hold inside the same
 * transaction that inserts the row.
 */
class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ExchangeRate::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_currency_id' => ['required', 'integer'],
            'to_currency_id' => ['required', 'integer'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'source' => ['nullable', 'string', 'max:100'],
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
