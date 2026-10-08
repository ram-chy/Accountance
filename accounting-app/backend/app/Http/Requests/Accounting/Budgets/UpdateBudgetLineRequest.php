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
        ];
    }
}
