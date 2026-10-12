"use client";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { formatDateOnly } from "@/lib/format/date";
import { MONEY_DISPLAY_DECIMALS, formatMoney } from "@/lib/format/money";
import type { BankReconciliationMovement } from "@/lib/bank-reconciliations/types";
import { movementDirectionLabel } from "@/lib/bank-reconciliations/validators";

/**
 * The movements eligible to be cleared into the reconciliation.
 *
 * Each row is a posted ledger line from `BankReconciliationMovementResource`;
 * `cleared` marks lines already added as items of this reconciliation. Nothing
 * is recomputed — debits, credits and the direction come straight from the
 * resource.
 *
 * A line can be cleared while the reconciliation is editable and the caller
 * holds `accounting.bank_reconciliation.update`. A cleared line can be removed
 * only when its `bank_reconciliation_items.id` is known — the resource never
 * exposes it, so only items cleared in this session (whose id came from the add
 * response) offer a Remove action (see the Phase 9 report).
 */

export type ReconciliationMovementsTableProps = {
  items: BankReconciliationMovement[];
  editable: boolean;
  canUpdateItem: boolean;
  knownItemIds: Record<number, number>;
  pendingLineId: number | null;
  onClear: (movement: BankReconciliationMovement) => void;
  onRemove: (movement: BankReconciliationMovement) => void;
};

function journalLabel(movement: BankReconciliationMovement): string {
  if (movement.journal_number) {
    return movement.journal_number;
  }

  if (movement.journal_id !== null) {
    return `#${movement.journal_id}`;
  }

  return "—";
}

function money(value: string | null): string {
  return formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });
}

function ClearedBadge({ cleared }: { cleared: boolean }) {
  return (
    <Badge variant={cleared ? "success" : "neutral"}>{cleared ? "Cleared" : "Uncleared"}</Badge>
  );
}

function RowAction({
  movement,
  editable,
  canUpdateItem,
  knownItemIds,
  pendingLineId,
  onClear,
  onRemove,
}: ReconciliationMovementsTableProps & { movement: BankReconciliationMovement }) {
  const clearing = pendingLineId === movement.journal_line_id;

  if (movement.cleared) {
    if (knownItemIds[movement.journal_line_id] !== undefined) {
      return (
        <Button
          size="sm"
          variant="ghost"
          loading={clearing}
          disabled={!canUpdateItem || pendingLineId !== null}
          onClick={() => onRemove(movement)}
        >
          Remove
        </Button>
      );
    }

    return null;
  }

  if (!editable || !canUpdateItem) {
    return null;
  }

  return (
    <Button
      size="sm"
      variant="secondary"
      loading={clearing}
      disabled={pendingLineId !== null}
      onClick={() => onClear(movement)}
    >
      Clear
    </Button>
  );
}

export function ReconciliationMovementsTable(props: ReconciliationMovementsTableProps) {
  const { items } = props;

  return (
    <>
      <table className="hidden w-full border-collapse text-left md:table">
        <caption className="sr-only">Bank reconciliation movements</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Journal
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Date
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Reference
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Description
            </th>
            <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
              Debit
            </th>
            <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
              Credit
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Side
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Status
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              <span className="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((movement) => (
            <tr key={movement.journal_line_id} className="border-b border-border last:border-0">
              <td className="px-3 py-2 text-sm font-medium text-foreground">
                {journalLabel(movement)}
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {formatDateOnly(movement.journal_date)}
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {movement.reference ?? "—"}
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {movement.description ?? "—"}
              </td>
              <td className="px-3 py-2 text-right font-mono text-sm text-foreground">
                {money(movement.debit)}
              </td>
              <td className="px-3 py-2 text-right font-mono text-sm text-foreground">
                {money(movement.credit)}
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {movementDirectionLabel(movement.direction)}
              </td>
              <td className="px-3 py-2">
                <ClearedBadge cleared={movement.cleared} />
              </td>
              <td className="px-3 py-2">
                <div className="flex justify-end">
                  <RowAction {...props} movement={movement} />
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <ul className="flex flex-col gap-3 md:hidden">
        {items.map((movement) => (
          <li
            key={movement.journal_line_id}
            className="rounded-lg border border-border bg-surface p-4"
          >
            <div className="flex items-baseline justify-between gap-3">
              <p className="text-sm font-medium text-foreground">{journalLabel(movement)}</p>
              <ClearedBadge cleared={movement.cleared} />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
              {formatDateOnly(movement.journal_date)}
              {movement.reference ? ` · ${movement.reference}` : ""}
            </p>
            {movement.description ? (
              <p className="mt-0.5 text-xs text-muted-foreground">{movement.description}</p>
            ) : null}
            <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1">
              <div>
                <dt className="text-xs text-muted-foreground">Debit</dt>
                <dd className="font-mono text-sm text-foreground">{money(movement.debit)}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Credit</dt>
                <dd className="font-mono text-sm text-foreground">{money(movement.credit)}</dd>
              </div>
            </dl>
            <div className="mt-3 flex justify-end border-t border-border pt-3">
              <RowAction {...props} movement={movement} />
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
