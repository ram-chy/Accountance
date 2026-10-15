"use client";

import { useEffect } from "react";

import { FormField } from "@/components/ui/field";
import type { BudgetLineDimensionAssignment } from "@/lib/budgets/types";
import type { FinancialDimensionOption } from "@/lib/dimensions/use-dimension-options";
import { useFinancialDimensionValueOptions } from "@/lib/dimensions/use-dimension-value-options";

/**
 * Analytical labels for a budget line: one picker per active dimension.
 *
 * A line may carry at most one value per dimension, so the field renders one
 * `<select>` for each active dimension rather than a free-form list. The backend
 * assignment rule (dimension active, value active, value belongs to the
 * dimension, one value per dimension) is mirrored here by offering only the
 * active dimensions and their active values.
 *
 * Each dimension's picker is its own child component so the value-options hook is
 * called once per component instance — never in a loop inside one component,
 * which would break the rules of hooks. An assignment whose dimension has since
 * been retired cannot be represented by a picker; that stale label is dropped the
 * next time the line is saved (the backend refuses an inactive label), which the
 * detail view calls out.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function selectedValueFor(
  assignments: BudgetLineDimensionAssignment[],
  dimensionId: number,
): number | null {
  return assignments.find((assignment) => assignment.dimension_id === dimensionId)?.value_id ?? null;
}

type BudgetLineDimensionSelectProps = {
  dimension: FinancialDimensionOption;
  selectedValueId: number | null;
  disabled: boolean;
  onSelect: (valueId: number | null) => void;
};

function BudgetLineDimensionSelect({
  dimension,
  selectedValueId,
  disabled,
  onSelect,
}: BudgetLineDimensionSelectProps) {
  const state = useFinancialDimensionValueOptions(dimension.id, !disabled);
  const values = state.status === "ready" ? state.items.filter((value) => value.is_active) : [];

  /*
   * Normalise a stored value that has since been retired: an inactive value
   * cannot be represented by the (active-only) picker and the backend would
   * refuse it, so clearing it here keeps the form savable rather than failing on
   * submit. This runs only once the options have loaded.
   */
  useEffect(() => {
    if (state.status !== "ready" || selectedValueId === null) return;
    const stillActive = state.items.some((value) => value.is_active && value.id === selectedValueId);
    if (!stillActive) onSelect(null);
  }, [state, selectedValueId, onSelect]);

  return (
    <FormField
      label={dimension.name}
      htmlFor={`budget-line-dim-${dimension.id}`}
      hint={dimension.type_label}
    >
      <select
        id={`budget-line-dim-${dimension.id}`}
        className={selectClasses}
        value={selectedValueId === null ? "" : String(selectedValueId)}
        onChange={(event) => onSelect(event.target.value === "" ? null : Number(event.target.value))}
        disabled={disabled || state.status !== "ready"}
      >
        <option value="">— None —</option>
        {values.map((value) => (
          <option key={value.id} value={value.id}>
            {value.code} — {value.name}
          </option>
        ))}
      </select>
      {state.status === "error" ? <p className="text-xs text-danger">{state.message}</p> : null}
    </FormField>
  );
}

export type BudgetLineDimensionsFieldProps = {
  /** The active dimensions to offer; inactive dimensions cannot be assigned. */
  dimensions: FinancialDimensionOption[];
  /** The line's current assignments (one per dimension at most). */
  value: BudgetLineDimensionAssignment[];
  onChange: (next: BudgetLineDimensionAssignment[]) => void;
  disabled?: boolean;
};

export function BudgetLineDimensionsField({
  dimensions,
  value,
  onChange,
  disabled = false,
}: BudgetLineDimensionsFieldProps) {
  if (dimensions.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        No active dimensions are available to label this line.
      </p>
    );
  }

  const setDimension = (dimensionId: number, valueId: number | null) => {
    const without = value.filter((assignment) => assignment.dimension_id !== dimensionId);
    const next =
      valueId === null
        ? without
        : [...without, { dimension_id: dimensionId, value_id: valueId }].sort(
            (a, b) => a.dimension_id - b.dimension_id,
          );
    onChange(next);
  };

  return (
    <div className="flex flex-col gap-3">
      {dimensions.map((dimension) => (
        <BudgetLineDimensionSelect
          key={dimension.id}
          dimension={dimension}
          selectedValueId={selectedValueFor(value, dimension.id)}
          disabled={disabled}
          onSelect={(valueId) => setDimension(dimension.id, valueId)}
        />
      ))}
    </div>
  );
}
