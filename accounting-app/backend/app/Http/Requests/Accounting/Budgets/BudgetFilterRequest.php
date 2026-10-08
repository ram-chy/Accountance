<?php

namespace App\Http\Requests\Accounting\Budgets;

use App\Enums\BudgetStatus;
use App\Models\Budget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter the budget listing.
 *
 * `financial_year_id` and `status` are optional company-scoped/controlled filters,
 * plus a free-text search over code and name. There is no date range: a budget is
 * identified by the year it plans, not by a transaction date it does not carry.
 */
class BudgetFilterRequest extends FormRequest
{
    use ValidatesBudgetInputs;

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Budget::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'financial_year_id' => [
                'nullable',
                'integer',
                Rule::exists('financial_years', 'id')->where('company_id', $this->activeCompanyId()),
            ],
            'status' => ['nullable', 'string', Rule::in(BudgetStatus::values())],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
