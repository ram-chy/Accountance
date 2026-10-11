<?php

namespace App\Http\Requests\Accounting\Budgets;

use App\Models\Budget;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a line on a draft budget. Every field is optional; an omitted field keeps
 * its stored value. The account, period and amount, when present, are subject to
 * the same rules as on creation.
 */
class UpdateBudgetLineRequest extends FormRequest
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
            'account_id' => $this->sometimesCompanyAccountRule(),
            'accounting_period_id' => $this->sometimesCompanyPeriodRule(),
            'amount' => $this->nonNegativeMoneyRule(),
            'description' => ['nullable', 'string', 'max:500'],

            /*
             * Phase 17: when present, even as an empty array, `dimensions`
             * replaces the line's whole analytical metadata - clearing is an
             * explicit "remove the label", not an omission.
             */
            'dimensions' => ['sometimes', 'array'],
            'dimensions.*' => ['required', 'array'],
            'dimensions.*.dimension_id' => ['required', 'integer'],
            'dimensions.*.value_id' => ['required', 'integer'],
        ];
    }
}
