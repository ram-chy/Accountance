import { Badge, type BadgeVariant } from "@/components/ui/badge";
import { BUDGET_STATUS_LABELS, type BudgetStatusValue } from "@/lib/budgets/types";

/**
 * A budget's lifecycle status.
 *
 * A draft can still be edited; an approved budget is frozen and can only be
 * changed by raising a new version, so the two states are worth telling apart at
 * a glance.
 */

const STATUS_VARIANTS: Record<BudgetStatusValue, BadgeVariant> = {
  DRAFT: "warning",
  APPROVED: "success",
};

export function BudgetStatusBadge({ status }: { status: BudgetStatusValue }) {
  return <Badge variant={STATUS_VARIANTS[status]}>{BUDGET_STATUS_LABELS[status]}</Badge>;
}
