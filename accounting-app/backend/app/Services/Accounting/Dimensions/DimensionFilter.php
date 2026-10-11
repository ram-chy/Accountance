<?php

namespace App\Services\Accounting\Dimensions;

use Illuminate\Database\Query\Builder;

/**
 * An optional analytical filter on a posted-lines query.
 *
 * Two identically-shaped filters serve both dimension-aware reports: the P&L and
 * the budget variance report ask "only lines labelled with this dimension (or
 * this dimension value)" and nothing else changes about how a report is built.
 *
 * `dimension_id` alone answers "this dimension, all its values"; a
 * `dimension_value_id` narrows it to one label. The two are validated together
 * at the request layer (a value from another dimension, or a foreign dimension,
 * never survives into this object), so the join needs no defensive re-check.
 *
 * The filter is a derivation over the SAME ledger lines the unfiltered report
 * reads - it joins the association table, it never sums, stores or pre-aggregates
 * anything. That is what keeps "company P&L" and "dimension P&L" reconcilable
 * against one ledger (Phase 17 §18).
 */
final readonly class DimensionFilter
{
    public function __construct(
        public ?int $dimensionId = null,
        public ?int $dimensionValueId = null,
    ) {}

    public function isActive(): bool
    {
        return $this->dimensionId !== null || $this->dimensionValueId !== null;
    }

    /**
     * Constrain a posted journal-lines query to lines carrying this label.
     *
     * The join cannot multiply a row: a line has at most one association per
     * dimension (the unique index on (journal_line_id, financial_dimension_id)),
     * and a value belongs to exactly one dimension, so at most one association
     * row matched the combination.
     */
    public function apply(Builder $query): Builder
    {
        if (! $this->isActive()) {
            return $query;
        }

        $query->join(
            'journal_line_dimensions',
            'journal_line_dimensions.journal_line_id',
            '=',
            'journal_lines.id'
        );

        if ($this->dimensionId !== null) {
            $query->where('journal_line_dimensions.financial_dimension_id', $this->dimensionId);
        }

        if ($this->dimensionValueId !== null) {
            $query->where('journal_line_dimensions.financial_dimension_value_id', $this->dimensionValueId);
        }

        return $query;
    }

    /**
     * Does a set of association rows carry this label?
     *
     * Used to pick the budget lines a dimension-scoped variance report should
     * show, so a budget-against-actual comparison is "the plan for this label
     * against the ledger for this label" - both sides scoped by the same filter.
     *
     * @param  iterable<array{financial_dimension_id: int, financial_dimension_value_id: int}>  $associations
     */
    public function matches(iterable $associations): bool
    {
        if (! $this->isActive()) {
            return true;
        }

        foreach ($associations as $association) {
            $dimensionOk = $this->dimensionId === null
                || (int) $association['financial_dimension_id'] === $this->dimensionId;

            $valueOk = $this->dimensionValueId === null
                || (int) $association['financial_dimension_value_id'] === $this->dimensionValueId;

            if ($dimensionOk && $valueOk) {
                return true;
            }
        }

        return false;
    }

    /**
     * The filter echoed back so a client can prove what it asked for.
     *
     * @return array{dimension_id: int|null, dimension_value_id: int|null}
     */
    public function echo(): array
    {
        return [
            'dimension_id' => $this->dimensionId,
            'dimension_value_id' => $this->dimensionValueId,
        ];
    }
}
