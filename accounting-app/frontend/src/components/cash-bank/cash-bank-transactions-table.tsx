"use client";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { formatDateOnly } from "@/lib/format/date";
import { MONEY_DISPLAY_DECIMALS, formatMoney } from "@/lib/format/money";
import {
  cashBankTransactionStatusLabel,
  cashBankTransactionTypeLabel,
  type CashBankTransaction,
} from "@/lib/cash-bank/types";

/**
 * Responsive cash/bank transaction list: a table on medium+ screens and stacked
 * cards below.
 *
 * Each row is a read-only summary of one movement; the detail dialog carries the
 * full record and the actions. The amount is the movement's magnitude — the
 * direction is already expressed by the From/Into columns, so no sign is added.
 */

function accountLabel(
  account: { code: string; name: string } | null,
  fallbackId: number,
): string {
  if (account === null) {
    return `#${fallbackId}`;
  }

  return `${account.code} — ${account.name}`;
}

function StatusBadge({ transaction }: { transaction: CashBankTransaction }) {
  return (
    <Badge variant={transaction.status === "POSTED" ? "success" : "warning"}>
      {cashBankTransactionStatusLabel(transaction.status)}
    </Badge>
  );
}

function TypeBadge({ transaction }: { transaction: CashBankTransaction }) {
  return <Badge variant="info">{cashBankTransactionTypeLabel(transaction.transaction_type)}</Badge>;
}

export function CashBankTransactionsTable({
  items,
  onOpen,
}: {
  items: CashBankTransaction[];
  onOpen: (transaction: CashBankTransaction) => void;
}) {
  return (
    <>
      <table className="hidden w-full border-collapse text-left md:table">
        <caption className="sr-only">Cash and bank transactions</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Number
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Date
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Type
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              From
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Into
            </th>
            <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
              Amount
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Status
            </th>
            <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
              <span className="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((transaction) => (
            <tr key={transaction.id} className="border-b border-border last:border-0">
              <td className="px-3 py-2 font-mono text-sm text-foreground">
                {transaction.transaction_number}
              </td>
              <td className="whitespace-nowrap px-3 py-2 text-sm text-foreground">
                {formatDateOnly(transaction.transaction_date)}
              </td>
              <td className="px-3 py-2">
                <TypeBadge transaction={transaction} />
              </td>
              <td className="px-3 py-2 text-sm text-foreground">
                {accountLabel(transaction.source_account, transaction.source_account_id)}
              </td>
              <td className="px-3 py-2 text-sm text-foreground">
                {accountLabel(transaction.destination_account, transaction.destination_account_id)}
              </td>
              <td className="whitespace-nowrap px-3 py-2 text-right font-mono text-sm text-foreground">
                {formatMoney(transaction.amount, { decimals: MONEY_DISPLAY_DECIMALS })}
              </td>
              <td className="px-3 py-2">
                <StatusBadge transaction={transaction} />
              </td>
              <td className="px-3 py-2 text-right">
                <Button size="sm" variant="secondary" onClick={() => onOpen(transaction)}>
                  View
                </Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <ul className="flex flex-col gap-3 md:hidden">
        {items.map((transaction) => (
          <li key={transaction.id} className="rounded-lg border border-border bg-surface p-4">
            <div className="flex items-baseline justify-between gap-3">
              <span className="font-mono text-sm text-muted-foreground">
                {transaction.transaction_number}
              </span>
              <StatusBadge transaction={transaction} />
            </div>
            <div className="mt-1 flex flex-wrap items-center gap-2">
              <TypeBadge transaction={transaction} />
              <span className="text-xs text-muted-foreground">
                {formatDateOnly(transaction.transaction_date)}
              </span>
            </div>
            <div className="mt-2 flex flex-col gap-0.5 text-sm text-foreground">
              <span>
                <span className="text-muted-foreground">From </span>
                {accountLabel(transaction.source_account, transaction.source_account_id)}
              </span>
              <span>
                <span className="text-muted-foreground">Into </span>
                {accountLabel(transaction.destination_account, transaction.destination_account_id)}
              </span>
            </div>
            <div className="mt-2 flex items-center justify-between">
              <span className="font-mono text-sm text-foreground">
                {formatMoney(transaction.amount, { decimals: MONEY_DISPLAY_DECIMALS })}
              </span>
              <Button size="sm" variant="secondary" onClick={() => onOpen(transaction)}>
                View
              </Button>
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
