import { Badge, type BadgeVariant } from "@/components/ui/badge";
import type { FixedAssetStatus } from "@/lib/fixed-assets/types";

/**
 * Semantic badge for the verified lifecycle states.
 *
 * The backend enum has exactly four values; anything else is rendered as a
 * neutral, clearly-unmapped chip rather than being guessed at.
 */
const STATUS_META: Record<FixedAssetStatus, { label: string; variant: BadgeVariant }> = {
  DRAFT: { label: "Draft", variant: "warning" },
  ACTIVE: { label: "Active", variant: "success" },
  FULLY_DEPRECIATED: { label: "Fully depreciated", variant: "info" },
  DISPOSED: { label: "Disposed", variant: "neutral" },
};

export function FixedAssetStatusBadge({ status }: { status: FixedAssetStatus }) {
  const meta = STATUS_META[status];
  if (!meta) {
    return <Badge variant="neutral">{status}</Badge>;
  }
  return <Badge variant={meta.variant}>{meta.label}</Badge>;
}
