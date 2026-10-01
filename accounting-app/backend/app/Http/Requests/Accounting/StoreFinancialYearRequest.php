<?php

namespace App\Http\Requests\Accounting;

use App\Models\FinancialYear;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a financial year.
 *
 * company_id is deliberately absent from the rules. The year belongs to the
 * authenticated company and nothing else; accepting the column would let a
 * request create a year inside another tenant, and no amount of validation makes
 * that safe - it is a scope question, and the scope comes from CompanyContext.
 */
class StoreFinancialYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createYear', FinancialYear::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Uniqueness is checked in FinancialYearService, which knows the
             * authenticated company and can scope the lookup to it. This request
             * has no company to scope a unique rule by, and a tenancy decision
             * does not belong in shape validation.
             */
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The financial year end date must not be before its start date.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'start_date' => 'start date',
            'end_date' => 'end date',
        ];
    }
}
