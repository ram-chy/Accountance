"use client";

import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils/cn";
import { MONEY_DISPLAY_DECIMALS, formatMoney } from "@/lib/format/money";
import { cashBankKindLabel, type CashBankAccount } from "@/lib/cash-bank/types";
import { useAccountBalance } from "@/lib/cash-bank/use-account-balance";

/**
 * Responsive cash/bank account list: a table on medium+ screens and stacked
 * cards below.
 *
 * The balance column is a ledger read, not a field on the account — the account
 * resource deliberately exposes no balance. It is fetched per row by a small
 * cell component (each row owns its own request) and labelled "Ledger balance"
 * because that is exactly what it is: the posted-journal balance as of now, not
 * a reconciled or available figure. Without `accounting.ledger.view` the
 * endpoint returns 403 and the cell shows that the balance is unavailable rather
 * than a misleading zero.
 */

type CashBankAccountsTableProps = {
  items: CashBankAccount[];
  canManage: boolean;
  canViewBalance: boolean;
  companyId: number | null;
  canViewTransactions: boolean;
  onManage: (account: CashBankAccount) => void;
};

function BankDetailsSummary({ account }: { account: CashBankAccount }) {
  if (!account.bank_account) {
    return <span className="text-sm text-muted-foreground">—</span>;
  }

  return (
    <div className="flex flex-col">
      <span className="text-sm text-foreground">{account.bank_account.bank_name}</span>
      {account.bank_account.account_number ? (
        <span className="font-mono text-xs text-muted-foreground">
          {account.bank_account.account_number}
        </span>
      ) : null}
      {!account.bank_account.is_active ? (
        <span className="text-xs text-muted-foreground">Bank details inactive</span>
      ) : null}
    </div>
  );
}

function KindCell({ account }: { account: CashBankAccount }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {account.cash_bank_kind ? (
        <Badge variant={account.cash_bank_kind === "CASH" ? "info" : "primary"}>
          {cashBankKindLabel(account.cash_bank_kind)}
        </Badge>
      ) : (
        <Badge variant="neutral">Unclassified</Badge>
      )}
      <Badge variant={account.is_active ? "success" : "neutral"}>
        {account.is_active ? "Active" : "Inactive"}
      </Badge>
    </div>
  );
}

function BalanceCell({
  account,
  companyId,
  canViewBalance,
}: {
  account: CashBankAccount;
  companyId: number | null;
  canViewBalance: boolean;
}) {
  const state = useAccountBalance(companyId, account.id, canViewBalance, 0);

  if (!canViewBalance) {
    return <span className="text-muted-foreground">—</span>;
  }

  if (state.status === "loading") {
    return <span className="animate-pulse text-muted-foreground">…</span>;
  }

  if (state.status === "ready") {
    return (
      <span className="font-mono">{formatMoney(state.balance, { decimals: MONEY_DISPLAY_DECIMALS })}</span>
    );
  }

  return <span className="text-muted-foreground">—</span>;
}

function RowActions({
  account,
  canManage,
  canViewTransactions,
  onManage,
}: {
  account: CashBankAccount;
  canManage: boolean;
  canViewTransactions: boolean;
  onManage: (account: CashBankAccount) => void;
}) {
  return (
    <div className="flex flex-wrap items-center justify-end gap-1.5">
      {canViewTransactions ? (
        <Link
          href={`/cash-bank/transactions?account_id=${account.id}`}
          className={cn(
            "inline-flex h-8 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium text-foreground transition-colors hover:bg-surface-muted",
            "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
          )}
        >
          Transactions
        </Link>
      ) : null}
      {canManage ? (
        <Button size="sm" variant="secondary" onClick={() => onManage(account)}>
          Manage
        </Button>
      ) : null}
    </div>
  );
}

export function CashBankAccountsTable({
  items,
  canManage,
  canViewBalance,
  companyId,
  canViewTransactions,
  onManage,
}: CashBankAccountsTableProps) {
  return (
    <>
      <table className="hidden w-full border-collapse text-left md:table">
        <caption className="sr-only">Cash and bank accounts</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Code
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Name
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Classification
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Bank details
            </th>
            <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
              Ledger balance
            </th>
            <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
              <span className="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((account) => (
            <tr key={account.id} className="border-b border-border last:border-0">
              <td className="px-3 py-2 font-mono text-sm text-foreground">{account.code}</td>
              <td className="px-3 py-2 text-sm text-foreground">
                <span className="font-medium">{account.name}</span>
                {!account.is_active ? (
                  <span className="ml-2 text-xs text-muted-foreground">(account inactive)</span>
                ) : null}
              </td>
              <td className="px-3 py-2">
                <KindCell account={account} />
              </td>
              <td className="px-3 py-2">
                <BankDetailsSummary account={account} />
              </td>
              <td className="whitespace-nowrap px-3 py-2 text-right text-sm text-foreground">
                <BalanceCell
                  account={account}
                  companyId={companyId}
                  canViewBalance={canViewBalance}
                />
              </td>
              <td className="px-3 py-2">
                <RowActions
                  account={account}
                  canManage={canManage}
                  canViewTransactions={canViewTransactions}
                  onManage={onManage}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <ul className="flex flex-col gap-3 md:hidden">
        {items.map((account) => (
          <li key={account.id} className="rounded-lg border border-border bg-surface p-4">
            <div className="flex items-baseline justify-between gap-3">
              <p className="font-mono text-sm text-muted-foreground">{account.code}</p>
              <KindCell account={account} />
            </div>
            <p className="mt-1 text-sm font-medium text-foreground">{account.name}</p>
            <div className="mt-1">
              <BankDetailsSummary account={account} />
            </div>
            <div className="mt-2 flex items-center justify-between text-sm">
              <span className="text-muted-foreground">Ledger balance</span>
              <BalanceCell account={account} companyId={companyId} canViewBalance={canViewBalance} />
            </div>
            <div className="mt-3 border-t border-border pt-3">
              <RowActions
                account={account}
                canManage={canManage}
                canViewTransactions={canViewTransactions}
                onManage={onManage}
              />
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
