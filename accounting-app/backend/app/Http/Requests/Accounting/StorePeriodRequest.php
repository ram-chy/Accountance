<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create an accounting period in the active company.
 *
 * No status field: a period is always created OPEN. Allowing a request to
 * create a period already CLOSED would produce a period no journal could ever
 * post into, which is never what the user meant.
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
             * design: the rule gives a fast field-level error, the service is
             * the authority and also closes the concurrency window.
             */
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
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
        ];
    }
}
