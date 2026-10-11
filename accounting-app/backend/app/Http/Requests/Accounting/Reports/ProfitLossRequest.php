<?php

namespace App\Http\Requests\Accounting\Reports;

use App\Http\Requests\Accounting\ValidatesDimensionFilter;
use Illuminate\Contracts\Validation\Validator;

/**
 * Filters for the profit and loss statement.
 *
 * The shared date window, plus an optional analytical filter (Phase 17): the
 * statement can be narrowed to posted lines carrying one financial dimension /
 * dimension value. The response then also reports the unassigned amounts, so a
 * dimension P&L reconciles against the company P&L by subtraction.
 */
class ProfitLossRequest extends ReportRequest
{
    use ValidatesDimensionFilter;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->dateRangeRules(), $this->dimensionFilterRules());
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $this->validateDimensionFilter($validator);
    }
}
