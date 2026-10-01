<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update an open accounting period's name and dates.
 *
 * A closed period is refused here AND in AccountingPeriodService. The request
 * check produces the field-level error a user sees; the service check is the one
 * that actually holds for every caller.
 */
class UpdatePeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AccountingPeriod $period */
        $period = $this->route('period');

        return $this->user()->can('update', $period);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();
        /** @var AccountingPeriod $period */
        $period = $this->route('period');

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('accounting_periods', 'name')
                    ->where('company_id', $company->getKey())
                    ->ignore($period->getKey()),
            ],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A period with this name already exists for the selected company.',
        ];
    }
}
