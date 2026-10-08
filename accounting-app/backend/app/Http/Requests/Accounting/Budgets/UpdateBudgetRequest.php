<?php

namespace App\Http\Requests\Accounting\Budgets;

use App\Models\Budget;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a draft budget's descriptive fields.
 *
 * `financial_year_id` is deliberately not a rule: a budget's year is fixed at
 * creation because its lines reference that year's periods. Moving a budget to
 * another year is not an edit, it is a different plan, and the service refuses a
 * non-draft anyway.
 */
class UpdateBudgetRequest extends FormRequest
{
    use ValidatesBudgetInputs;

    public function authorize(): bool
    {
        /** @var Budget $budget */
        $budget = $this->route('budget');

        return $this->user()->can('update', $budget);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:50'],
            'name' => ['sometimes', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
