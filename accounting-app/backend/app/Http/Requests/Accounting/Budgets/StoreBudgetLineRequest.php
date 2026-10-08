<?php

namespace App\Http\Requests\Accounting\Budgets;

use App\Models\Budget;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a line to a draft budget.
 *
 * The account and period must exist in the active company. The further rules -
 * that the account is Revenue or Expense and active, and that the period is in
 * the budget's own financial year - are enforced by BudgetLineService, because
 * they need the budget row and the account's type, which a request rule cannot
 * see without duplicating the service's logic.
 *
 * A planned amount is non-negative: a plan is a magnitude on the account's normal
 * side, and "spend less" is a small positive number, not a negative one.
 */
class StoreBudgetLineRequest extends FormRequest
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
            'account_id' => $this->companyAccountRule(),
            'accounting_period_id' => $this->companyPeriodRule(),
            'amount' => array_merge(['required'], $this->nonNegativeMoneyRule()),
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
