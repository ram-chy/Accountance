<?php

namespace App\Http\Requests\Accounting\Budgets;

use App\Models\Budget;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Raise a new draft version from an approved budget.
 *
 * Only the descriptive fields may be overridden; the code, year and lines are
 * copied from the source by BudgetService. A revision is not an edit - it is a
 * new draft that supersedes the approved one while leaving that approved record
 * untouched.
 */
class ReviseBudgetRequest extends FormRequest
{
    use ValidatesBudgetInputs;

    public function authorize(): bool
    {
        /** @var Budget $budget */
        $budget = $this->route('budget');

        return $this->user()->can('revise', $budget);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
