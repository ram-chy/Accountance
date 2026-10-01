<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create an accounting period.
 *
 * financial_year_id is optional. When it is absent the period is attached to the
 * fiscal year derived from the configured start month, and that year is created
 * if the company has no calendar yet. Requiring it would force every client to
 * resolve a date into a year before it could record a period, and the derivation
 * is not a business decision - it follows from config.
 *
 * When it IS supplied it is validated against this company only, because a
 * foreign key from a request body is not a scope assertion. The service repeats
 * the check, because it is the layer that must hold for non-HTTP callers too.
 */
class StorePeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AccountingPeriod::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('accounting_periods', 'name')->where('company_id', $company->getKey()),
            ],
            'start_date' => ['required', 'date_format:Y-m-d'],
            /*
             * start/end ordering and cross-period overlap are both enforced in
             * AccountingPeriodService rather than only here, because the service
             * must hold for non-HTTP callers too. The two checks overlap by
             * design: the rule gives a fast field-level error, the service is the
             * authority and also closes the concurrency window.
             */
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'financial_year_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('financial_years', 'id')->where('company_id', $company->getKey()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The period end date must not be before its start date.',
            'name.unique' => 'A period with this name already exists for the selected company.',
            'financial_year_id.exists' => 'The selected financial year does not belong to this company.',
        ];
    }
}
