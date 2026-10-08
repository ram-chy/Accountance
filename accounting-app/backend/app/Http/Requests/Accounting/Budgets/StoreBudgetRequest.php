<?php

namespace App\Http\Requests\Accounting\Budgets;

use App\Models\Budget;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Raise a new draft budget.
 *
 * Only four fields are accepted, and none of them is company, version, status or
 * parent - those are established by BudgetService. A client cannot create an
 * approved budget through this request, because `status` is not a rule here; it
 * is assigned as DRAFT in the service.
 */
class StoreBudgetRequest extends FormRequest
{
    use ValidatesBudgetInputs;

    public function authorize(): bool
    {
        return $this->user()->can('create', Budget::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'financial_year_id' => $this->companyFinancialYearRule(),
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
