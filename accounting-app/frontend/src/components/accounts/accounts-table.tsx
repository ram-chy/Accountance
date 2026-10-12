"use client";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import type {
  Account,
  AccountTypeValue,
  CashBankKindValue,
  NormalBalanceValue,
} from "@/lib/accounts/types";

import type { AccountLifecycleAction } from "./confirm-action-dialog";

/**
 * Responsive account list: a table on medium+ screens and stacked cards below.
 *
 * Columns follow the verified account contract — code, name, type, effective
 * normal balance, contra indicator, cash/bank classification and lifecycle
 * status. No balances are shown because the account endpoints carry none.
 * Action buttons are rendered only when the caller has the matching permission;
 * the backend still authorises every request.
 */

type AccountRowActionsProps = {
  account: Account;
  canUpdate: boolean;
  canActivate: boolean;
  canDeactivate: boolean;
  canDelete: boolean;
  onEdit: (account: Account) => void;
  onAction: (action: AccountLifecycleAction) => void;
};

function typeLabel(value: AccountTypeValue): string {
  return value.charAt(0) + value.slice(1).toLowerCase();
}

function balanceLabel(value: NormalBalanceValue): string {
  return value.charAt(0) + value.slice(1).toLowerCase();
}

function kindLabel(value: CashBankKindValue): string {
  return value.charAt(0) + value.slice(1).toLowerCase();
}

function AccountRowActions({
  account,
  canUpdate,
  canActivate,
  canDeactivate,
  canDelete,
  onEdit,
  onAction,
}: AccountRowActionsProps) {
  const canDeleteThis = canDelete && !account.has_journal_history;

  return (
    <div className="flex flex-wrap items-center justify-end gap-1.5">
      {canUpdate ? (
        <Button size="sm" variant="secondary" onClick={() => onEdit(account)}>
          Edit
        </Button>
      ) : null}

      {account.is_active && canDeactivate ? (
        <Button
          size="sm"
          variant="secondary"
          disabled={account.is_system}
          title={account.is_system ? "System accounts cannot be deactivated." : undefined}
          onClick={() => onAction({ kind: "deactivate", account })}
        >
          Deactivate
        </Button>
      ) : null}

      {!account.is_active && canActivate ? (
        <Button
          size="sm"
          variant="secondary"
          onClick={() => onAction({ kind: "activate", account })}
        >
          Activate
        </Button>
      ) : null}

      {canDelete ? (
        <Button
          size="sm"
          variant="ghost"
          disabled={!canDeleteThis}
          title={
            !canDeleteThis
              ? "Accounts with journal or sub-accounts cannot be deleted. Deactivate instead."
              : "Permanently delete this account"
          }
          className="text-danger hover:bg-danger-soft hover:text-danger"
          onClick={() => onAction({ kind: "delete", account })}
        >
          Delete
        </Button>
      ) : null}
    </div>
  );
}

function StatusCell({ account }: { account: Account }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <Badge variant={account.is_active ? "success" : "neutral"}>
        {account.is_active ? "Active" : "Inactive"}
      </Badge>
      {account.has_journal_history ? (
        <Badge variant="warning">In use</Badge>
      ) : null}
    </div>
  );
}

function ClassificationCell({ account }: { account: Account }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <span className="text-sm text-foreground">
        {typeLabel(account.account_type)}
      </span>
      {account.cash_bank_kind ? (
        <Badge variant="info">{kindLabel(account.cash_bank_kind)}</Badge>
      ) : null}
    </div>
  );
}

function BalanceCell({ account }: { account: Account }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <span className="text-sm text-foreground">
        {balanceLabel(account.normal_balance)}
      </span>
      {account.is_contra ? <Badge variant="primary">Contra</Badge> : null}
    </div>
  );
}

export type AccountsTableProps = Omit<AccountRowActionsProps, "account"> & {
  items: Account[];
};

export function AccountsTable({
  items,
  canUpdate,
  canActivate,
  canDeactivate,
  canDelete,
  onEdit,
  onAction,
}: AccountsTableProps) {
  return (
    <>
      <table className="hidden w-full border-collapse text-left md:table">
        <caption className="sr-only">Chart of accounts</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Code
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Name
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Type
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Normal balance
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
          {items.map((account) => (
            <tr key={account.id} className="border-b border-border last:border-0">
              <td className="px-3 py-2 font-mono text-sm text-foreground">
                {account.code}
              </td>
              <td className="px-3 py-2">
                <p className="text-sm font-medium text-foreground">
                  {account.name}
                </p>
                {account.description ? (
                  <p className="mt-0.5 max-w-md truncate text-xs text-muted-foreground">
                    {account.description}
                  </p>
                ) : null}
              </td>
              <td className="px-3 py-2">
                <ClassificationCell account={account} />
              </td>
              <td className="px-3 py-2">
                <BalanceCell account={account} />
              </td>
              <td className="px-3 py-2">
                <StatusCell account={account} />
              </td>
              <td className="px-3 py-2">
                <AccountRowActions
                  account={account}
                  canUpdate={canUpdate}
                  canActivate={canActivate}
                  canDeactivate={canDeactivate}
                  canDelete={canDelete}
                  onEdit={onEdit}
                  onAction={onAction}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <ul className="flex flex-col gap-3 md:hidden">
        {items.map((account) => (
          <li
            key={account.id}
            className="rounded-lg border border-border bg-surface p-4"
          >
            <div className="flex items-baseline justify-between gap-3">
              <p className="font-mono text-sm text-muted-foreground">
                {account.code}
              </p>
              <StatusCell account={account} />
            </div>
            <p className="mt-1 text-sm font-medium text-foreground">
              {account.name}
            </p>
            {account.description ? (
              <p className="mt-0.5 text-xs text-muted-foreground">
                {account.description}
              </p>
            ) : null}
            <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
              <ClassificationCell account={account} />
              <span aria-hidden="true" className="text-border-strong">•</span>
              <BalanceCell account={account} />
            </div>
            <div className="mt-3 border-t border-border pt-3">
              <AccountRowActions
                account={account}
                canUpdate={canUpdate}
                canActivate={canActivate}
                canDeactivate={canDeactivate}
                canDelete={canDelete}
                onEdit={onEdit}
                onAction={onAction}
              />
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}