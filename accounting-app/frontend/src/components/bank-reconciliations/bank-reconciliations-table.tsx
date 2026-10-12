"use client";

import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import type { BadgeVariant } from "@/components/ui/badge";
import { formatDateOnly } from "@/lib/format/date";
import { MONEY_DISPLAY_DECIMALS, formatMoney } from "@/lib/format/money";
import {
  bankReconciliationStatusLabel,
  type BankReconciliation,
  type BankReconciliationStatusValue,
} from "@/lib/bank-reconciliations/types";

/**
 * Responsive bank-reconciliation list: a table on medium+ screens and stacked
 * cards below.
 *
 * Columns follow the verified `BankReconciliationResource` — the reconciled
 * account, the settled period, the statement closing balance, the backend's
 * authoritative reconciled balance and difference, and the cleared/total
 * movement counts. Nothing is recomputed here: every figure is formatted
 * directly from the resource.
 *
 * The account label comes from a caller-supplied map because the resource
 * exposes only `account_id`; the `bank_account_id` needed to fetch bank details
 * is not exposed by any read endpoint (see the Phase 9 report).
 */

export type ReconciliationAccountLabels = Record<number, string>;

export function statusBadgeVariant(
  status: BankReconciliationStatusValue,
): BadgeVariant {
  switch (status) {
    case "DRAFT":
      return "warning";
    case "IN_PROGRESS":
      return "info";
    case "RECONCILED":
      return "success";
  }
}

export function ReconciliationStatusBadge({
  status,
}: {
  status: BankReconciliationStatusValue;
}) {
  return <Badge variant={statusBadgeVariant(status)}>{bankReconciliationStatusLabel(status)}</Badge>;
}

function accountLabel(
  accountId: number | null,
  labels: ReconciliationAccountLabels,
): string {
  if (accountId === null) {
    return "—";
  }

  return labels[accountId] ?? `Account #${accountId}`;
}

function rangeLabel(reconciliation: BankReconciliation): string {
  return `${formatDateOnly(reconciliation.from_date)} – ${formatDateOnly(reconciliation.to_date)}`;
}

function money(value: string | null): string {
  return formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });
}

const linkButtonClasses =
  "inline-flex h-8 items-center justify-center rounded-md border border-border bg-surface px-3 " +
  "text-sm font-medium text-foreground transition-colors hover:bg-surface-muted " +
  "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

function ReconciliationRowActions({ reconciliation }: { reconciliation: BankReconciliation }) {
  return (
    <div className="flex items-center justify-end">
      <Link href={`/bank-reconciliations/${reconciliation.id}`} className={linkButtonClasses}>
        View
      </Link>
    </div>
  );
}

export type BankReconciliationsTableProps = {
  items: BankReconciliation[];
  labels: ReconciliationAccountLabels;
};

export function BankReconciliationsTable({
  items,
  labels,
}: BankReconciliationsTableProps) {
  return (
    <>
      <table className="hidden w-full border-collapse text-left md:table">
        <caption className="sr-only">Bank reconciliations</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Bank account
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Period
            </th>
            <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
              Statement closing
            </th>
            <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
              Reconciled balance
            </th>
            <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
              Difference
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Movements
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
          {items.map((reconciliation) => (
            <tr key={reconciliation.id} className="border-b border-border last:border-0">
              <td className="px-3 py-2 text-sm font-medium text-foreground">
                {accountLabel(reconciliation.account_id, labels)}
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {rangeLabel(reconciliation)}
              </td>
              <td className="px-3 py-2 text-right font-mono text-sm text-foreground">
                {money(reconciliation.statement_closing_balance)}
              </td>
              <td className="px-3 py-2 text-right font-mono text-sm text-foreground">
                {money(reconciliation.reconciled_balance)}
              </td>
              <td className="px-3 py-2 text-right font-mono text-sm text-foreground">
                {money(reconciliation.difference)}
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {reconciliation.cleared_movements} / {reconciliation.total_movements}
              </td>
              <td className="px-3 py-2">
                <ReconciliationStatusBadge status={reconciliation.status} />
              </td>
              <td className="px-3 py-2">
                <ReconciliationRowActions reconciliation={reconciliation} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <ul className="flex flex-col gap-3 md:hidden">
        {items.map((reconciliation) => (
          <li key={reconciliation.id} className="rounded-lg border border-border bg-surface p-4">
            <div className="flex items-baseline justify-between gap-3">
              <p className="text-sm font-medium text-foreground">
                {accountLabel(reconciliation.account_id, labels)}
              </p>
              <ReconciliationStatusBadge status={reconciliation.status} />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">{rangeLabel(reconciliation)}</p>
            <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2">
              <div>
                <dt className="text-xs text-muted-foreground">Statement closing</dt>
                <dd className="font-mono text-sm text-foreground">
                  {money(reconciliation.statement_closing_balance)}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Reconciled balance</dt>
                <dd className="font-mono text-sm text-foreground">
                  {money(reconciliation.reconciled_balance)}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Difference</dt>
                <dd className="font-mono text-sm text-foreground">
                  {money(reconciliation.difference)}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Movements</dt>
                <dd className="text-sm text-foreground">
                  {reconciliation.cleared_movements} / {reconciliation.total_movements}
                </dd>
              </div>
            </dl>
            <div className="mt-3 border-t border-border pt-3">
              <Link href={`/bank-reconciliations/${reconciliation.id}`} className={linkButtonClasses}>
                View
              </Link>
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
