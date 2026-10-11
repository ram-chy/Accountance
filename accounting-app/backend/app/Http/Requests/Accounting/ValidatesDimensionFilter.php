<?php

namespace App\Http\Requests\Accounting;

use App\Models\Company;
use App\Models\FinancialDimension;
use App\Models\FinancialDimensionValue;
use App\Services\Accounting\Dimensions\DimensionFilter;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared rules for the dimension filter a report endpoint may accept.
 *
 * Both the P&L and the budget variance report answer "the same report, but only
 * for lines labelled with this dimension", so the filter is defined once here.
 *
 * The rules accept ids, but the real checks are cross-row and belong in the
 * after-hook: an id must resolve to a dimension of the ACTIVE company, a value
 * must resolve through a dimension of the active company, and a value sent
 * alongside a dimension_id must belong to that very dimension. `exists` alone
 * could not express any of those without a subquery, and re-stating the company
 * scoping in two request classes would be two places for it to drift.
 *
 * The value on its own is a valid filter even without its dimension_id: it
 * uniquely identifies a label, and the filter's join does not need to name the
 * dimension to constrain on the value. The two are only required to be
 * consistent when both are supplied.
 */
trait ValidatesDimensionFilter
{
    /**
     * @return array<string, mixed>
     */
    protected function dimensionFilterRules(): array
    {
        return [
            'dimension_id' => ['sometimes', 'nullable', 'integer'],
            'dimension_value_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function validateDimensionFilter(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $dimensionId = $this->nullableInt('dimension_id');
            $valueId = $this->nullableInt('dimension_value_id');

            if ($dimensionId === null && $valueId === null) {
                return;
            }

            $company = $this->activeCompany();

            if ($dimensionId !== null) {
                $dimension = FinancialDimension::query()
                    ->where('company_id', $company->getKey())
                    ->whereKey($dimensionId)
                    ->first();

                if ($dimension === null) {
                    $validator->errors()->add('dimension_id', 'The selected financial dimension does not belong to the active company.');

                    return;
                }

                if (! $dimension->is_active) {
                    $validator->errors()->add('dimension_id', 'The selected financial dimension is inactive.');
                }
            }

            if ($valueId !== null) {
                $value = FinancialDimensionValue::query()
                    ->with('dimension')
                    ->whereKey($valueId)
                    ->first();

                if ($value === null) {
                    $validator->errors()->add('dimension_value_id', 'The selected financial dimension value does not exist.');

                    return;
                }

                if (! $value->is_active) {
                    $validator->errors()->add('dimension_value_id', 'The selected financial dimension value is inactive.');
                }

                if ((int) $value->dimension->company_id !== $company->getKey()) {
                    $validator->errors()->add('dimension_value_id', 'The selected value belongs to a dimension of another company.');

                    return;
                }

                if ($dimensionId !== null && (int) $value->financial_dimension_id !== $dimensionId) {
                    $validator->errors()->add('dimension_value_id', 'The selected value does not belong to the selected financial dimension.');
                }
            }
        });
    }

    /**
     * The filter resolved from the request, or null when no dimension was asked for.
     */
    public function dimensionFilter(): ?DimensionFilter
    {
        $dimensionId = $this->nullableInt('dimension_id');
        $valueId = $this->nullableInt('dimension_value_id');

        if ($dimensionId === null && $valueId === null) {
            return null;
        }

        return new DimensionFilter($dimensionId, $valueId);
    }

    private function nullableInt(string $key): ?int
    {
        if (! $this->filled($key)) {
            return null;
        }

        return $this->integer($key);
    }

    /**
     * The active company, for the cross-company checks above.
     */
    abstract protected function activeCompany(): Company;
}
