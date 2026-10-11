<?php

namespace App\Services\Accounting\Dimensions;

use App\Models\Company;
use App\Models\FinancialDimension;
use App\Models\FinancialDimensionValue;

/**
 * The one place a dimension assignment is checked before it is persisted.
 *
 * A journal line and a budget line both carry the same analytical metadata - a
 * list of {"dimension_id", "value_id"} pairs - and the rules are identical, so
 * the validation lives once here rather than being re-derived at each call site
 * where it could drift. When the same rule is stated twice, the two copies stop
 * agreeing eventually; this class exists so there is only one copy.
 *
 * The checks mirror Phase 17 §15 exactly:
 *
 *   - the dimension must belong to the ACTIVE company (dimensions carry a
 *     company_id, so a value from another company's dimension is refused here,
 *     not at the database - the junction table has no company column);
 *   - the dimension must be active;
 *   - the value must belong to that dimension (values carry no company of their
 *     own; their company IS their parent dimension's company);
 *   - the value must be active;
 *   - a line may reference each dimension at most once. That is also the
 *     (line, dimension) unique index, chosen because "cost center of this line"
 *     is a single fact - see Phase 17 §12.
 *
 * Collected, not thrown: the caller collects errors from every line and raises
 * them together, the way journal line validation already does, so a user fixing
 * an entry does not discover one problem per submission.
 */
class DimensionAssignmentValidator
{
    /**
     * Validate and normalise a list of dimension assignments.
     *
     * @param  array<int, array<string, mixed>>  $assignments  the raw `dimensions` payload
     * @param  string  $label  error-key prefix, e.g. "lines.0.dimensions"
     * @param  array<string, array<int, string>>  $errors  collected in place by key
     * @return list<array{financial_dimension_id: int, financial_dimension_value_id: int}>
     */
    public function resolve(Company $company, array $assignments, string $label, array &$errors): array
    {
        $resolved = [];

        if ($assignments === []) {
            return $resolved;
        }

        $dimensions = FinancialDimension::query()
            ->where('company_id', $company->getKey())
            ->whereIn('id', array_map(fn ($a) => (int) ($a['dimension_id'] ?? 0), $assignments))
            ->get()
            ->keyBy(fn (FinancialDimension $dimension) => (int) $dimension->getKey());

        $values = FinancialDimensionValue::query()
            ->whereIn('id', array_map(fn ($a) => (int) ($a['value_id'] ?? 0), $assignments))
            ->get()
            ->keyBy(fn (FinancialDimensionValue $value) => (int) $value->getKey());

        $seen = [];

        foreach (array_values($assignments) as $index => $assignment) {
            $key = "{$label}.{$index}";
            $dimensionId = (int) ($assignment['dimension_id'] ?? 0);
            $valueId = (int) ($assignment['value_id'] ?? 0);

            if ($dimensionId === 0 || $valueId === 0) {
                $errors["{$key}.value_id"][] = 'Each dimension assignment must carry a dimension_id and a value_id.';

                continue;
            }

            $dimension = $dimensions[$dimensionId] ?? null;

            if ($dimension === null || ! $dimension->is_active) {
                $errors["{$key}.dimension_id"][] = 'The selected financial dimension does not belong to the active company or is inactive.';

                continue;
            }

            $value = $values[$valueId] ?? null;

            if ($value === null || ! $value->is_active) {
                $errors["{$key}.value_id"][] = 'The selected financial dimension value does not exist or is inactive.';

                continue;
            }

            if ((int) $value->financial_dimension_id !== $dimensionId) {
                $errors["{$key}.value_id"][] = 'The selected value does not belong to the selected financial dimension.';

                continue;
            }

            if (in_array($dimensionId, $seen, true)) {
                $errors["{$key}.dimension_id"][] = 'A line may be assigned to the same financial dimension only once.';

                continue;
            }

            $seen[] = $dimensionId;

            $resolved[] = [
                'financial_dimension_id' => $dimensionId,
                'financial_dimension_value_id' => $valueId,
            ];
        }

        return $resolved;
    }
}
