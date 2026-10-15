import { Badge, type BadgeVariant } from "@/components/ui/badge";
import {
  FINANCIAL_DIMENSION_TYPE_LABELS,
  type FinancialDimensionTypeValue,
} from "@/lib/dimensions/types";

/**
 * A dimension's type, shown as a coloured chip.
 *
 * The colour is purely a visual cue for the axis (so a long list can be scanned
 * by cost centre vs project vs department vs location); the label itself is the
 * backend's own `type_label` when available, falling back to the client-side
 * copy only for a row rendered before the resource arrived.
 */

const TYPE_VARIANTS: Record<FinancialDimensionTypeValue, BadgeVariant> = {
  COST_CENTER: "info",
  PROJECT: "primary",
  DEPARTMENT: "warning",
  LOCATION: "neutral",
};

type DimensionTypeBadgeProps = {
  type: FinancialDimensionTypeValue;
  /** The backend's own label; defaults to the client-side copy for that type. */
  label?: string;
};

export function DimensionTypeBadge({ type, label }: DimensionTypeBadgeProps) {
  return <Badge variant={TYPE_VARIANTS[type]}>{label?.trim() || FINANCIAL_DIMENSION_TYPE_LABELS[type]}</Badge>;
}
