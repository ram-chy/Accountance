<?php

namespace App\Http\Requests\Accounting\Budgets;

use App\Http\Requests\Accounting\ValidatesDimensionFilter;
use App\Models\Budget;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for the budget variance report.
 *
 * The variance endpoint accepts the same optional analytical filter as the P&L
 * (Phase 17 §21-23): a dimension or dimension value narrows both sides of the
 * comparison - the plan lines carrying the label and the posted actuals carrying
 * it - while the computation and the sign conventions stay exactly Phase 16's.
 *
 * Authorization matches the report's read access: anyone who can view the budget
 * may read its variance, including a filtered one.
 */
class BudgetVarianceRequest extends FormRequest
{
    use ValidatesDimensionFilter;

    public function authorize(): bool
    {
        /** @var Budget $budget */
        $budget = $this->route('budget');

        return (bool) $this->user()?->can('view', $budget);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->dimensionFilterRules();
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateDimensionFilter($validator);
    }

    protected function activeCompany(): Company
    {
        return app(CompanyContext::class)->getOrFail();
    }
}
