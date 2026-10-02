<?php

namespace App\Http\Requests\Accounting\Tax;

use App\Enums\TaxCalculationBasis;
use App\Models\Tax;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Calculate a tax on an amount.
 *
 * A calculation operation, not an accounting write: nothing here is persisted,
 * and no journal, document or balance is touched. The brief is explicit that
 * calling the endpoint must not write anything, which is why the request carries
 * no tax-to-account side effects and why the controller calls only
 * TaxCalculationService.
 *
 * `amount` is read according to `basis` - the caller says whether the figure is
 * net or gross, so the request never has to guess from the tax's own configured
 * basis. `basis` defaults to the first named tax's configured value, which is what
 * makes the common case ("just tell me what VAT costs on this") a two-field
 * request.
 */
class CalculateTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('calculate', Tax::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric'],

            /*
             * Which taxes to apply. Optional - an empty list is a valid answer and
             * returns the amount untaxed, which is what a client wants when
             * checking whether a figure already includes tax.
             */
            'tax_ids' => ['sometimes', 'array'],

            /*
             * Not validated as existing accounts here. TaxCalculationService
             * reports an unusable tax by id and code, and the ids are resolved
             * company-scoped by TaxRuleResolver - so a cross-company id comes back
             * as "does not belong to the active company" rather than as a Laravel
             * exists() failure that would confirm nothing beyond the message.
             */
            'tax_ids.*' => ['integer'],

            /*
             * The transaction date, which selects each tax's effective rate. It is
             * required, not defaulted to today: a rate resolution that quietly used
             * "now" would return a correct answer for a quote dated last quarter
             * only if that quarter's rate happens to be current, and the caller of a
             * calculation endpoint cannot tell that it was guessed.
             */
            'date' => ['required', 'date_format:Y-m-d'],

            'basis' => ['sometimes', 'nullable', 'string', Rule::in(TaxCalculationBasis::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.numeric' => 'The amount must be a number.',
            'date.date_format' => 'The calculation date must be in YYYY-MM-DD format, as the effective rate depends on it.',
            'tax_ids.array' => 'The taxes must be given as a list of tax ids.',
            'basis.in' => 'The calculation basis must be EXCLUSIVE or INCLUSIVE.',
        ];
    }
}
