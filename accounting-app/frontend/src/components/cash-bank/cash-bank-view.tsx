"use client";

import Link from "next/link";
import { useMemo, useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { useCashBankAccountList } from "@/lib/cash-bank/use-cash-bank-accounts";
import {
  CASH_BANK_KIND_VALUES,
  type CashBankAccount,
  type CashBankAccountFilters,
  type CashBankKindValue,
} from "@/lib/cash-bank/types";

import {
  CashBankAccountActionsDialog,
  type CashBankBankDetailsAction,
} from "./cash-bank-account-actions-dialog";
import { CashBankAccountDialog } from "./cash-bank-account-dialog";
import { CashBankAccountsTable } from "./cash-bank-accounts-table";
import { CashBankListSkeleton } from "./cash-bank-list-skeleton";

/**
 * Cash & banking overview.
 *
 * Classifying an account as cash or bank, and recording its bank details, is done
 * from the chart of accounts; this screen owns what happens afterwards — seeing
 * which accounts are cash/bank, each one's ledger balance, and their bank-detail
 * lifecycle. It deliberately does not offer to create or reclassify an account
 * from scratch, so the chart of accounts stays the single place that owns the
 * account list.
 *
 * Balances are per-account ledger reads. There is intentionally no company-wide
 * cash total: the account and transaction resources expose no currency, so
 * summing balances across accounts could add currencies together. The overview
 * shows the per-account figures and leaves totals to the reports, which do know
 * the reporting currency.
 */

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function kindLabel(value: CashBankKindValue): string {
  return value === "CASH" ? "Cash" : "Bank";
}

export function CashBankView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.cash_bank.view");
  const canManage = can("accounting.cash_bank.update");
  const canViewBalance = can("accounting.ledger.view");

  const [kind, setKind] = useState<CashBankKindValue | "">("");
  const [reloadToken, setReloadToken] = useState(0);
  const [editor, setEditor] = useState<CashBankAccount | null>(null);
  const [action, setAction] = useState<CashBankBankDetailsAction | null>(null);

  const filters = useMemo<CashBankAccountFilters>(() => ({ kind }), [kind]);

  const state = useCashBankAccountList({
    companyId,
    enabled: canView,
    filters,
    reloadToken,
  });

  const reload = () => setReloadToken((token) => token + 1);

  const header = (
    <div className="flex flex-col gap-1">
      <h1 className="text-xl font-semibold text-foreground">Cash &amp; banking</h1>
      <p className="text-sm text-muted-foreground">
        The accounts money moves through for the active company, their bank details
        and their ledger balances.
      </p>
    </div>
  );

  const filterBar = (
    <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="cash-bank-kind" className="text-sm font-medium text-foreground">
          Classification
        </label>
        <select
          id="cash-bank-kind"
          className={selectClasses}
          value={kind}
          onChange={(event) => setKind(event.target.value as CashBankKindValue | "")}
          disabled={!activeCompany}
        >
          <option value="">Cash and bank</option>
          {CASH_BANK_KIND_VALUES.map((value) => (
            <option key={value} value={value}>
              {kindLabel(value)}
            </option>
          ))}
        </select>
      </div>

      <Link
        href="/accounts"
        className="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-border bg-surface px-4 text-sm font-medium text-foreground transition-colors hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        Manage in chart of accounts
      </Link>
    </div>
  );

  const body = (() => {
    if (!activeCompany) {
      return (
        <EmptyState
          title="No company selected"
          description="Choose a company to view its cash and bank accounts."
        />
      );
    }

    if (!canView) {
      return (
        <EmptyState
          title="Cash and banking unavailable"
          description="Your role does not include access to cash and bank accounts."
        />
      );
    }

    if (state.status === "loading") {
      return <CashBankListSkeleton label="Loading cash and bank accounts" />;
    }

    if (state.status === "unavailable") {
      return <EmptyState title="Cash and banking unavailable" description={state.message} />;
    }

    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load cash and bank accounts"
          message={state.message}
          requestId={state.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      );
    }

    if (state.items.length === 0) {
      return (
        <EmptyState
          title={kind === "" ? "No cash or bank accounts" : `No ${kindLabel(kind)} accounts`}
          description="Classify an account as cash or bank from the chart of accounts to see it here."
          action={
            <Link
              href="/accounts"
              className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-on-primary transition-colors hover:bg-primary-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
              Go to chart of accounts
            </Link>
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <CashBankAccountsTable
          items={state.items}
          canManage={canManage}
          canViewBalance={canViewBalance}
          companyId={companyId}
          canViewTransactions={canView}
          onManage={(account) => setEditor(account)}
        />
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      {header}

      <p className="rounded-lg border border-border bg-surface-muted px-4 py-3 text-xs text-muted-foreground">
        Balances below are{" "}
        <span className="font-medium text-foreground">ledger balances</span> — the
        posted-journal position for each account. They are not reconciled or
        available figures, and are shown per account without a combined total.
      </p>

      {filterBar}

      {canView && !canViewBalance ? (
        <Badge variant="warning">
          Ledger balances are hidden because your role cannot read the ledger.
        </Badge>
      ) : null}

      <div className="flex flex-col gap-4">
        {body}
        {state.status === "ready" && state.hasMore ? (
          <p className="text-sm text-muted-foreground">
            Showing {state.items.length} accounts; more may exist beyond this page.
          </p>
        ) : null}
      </div>

      <CashBankAccountDialog
        key={editor ? `manage-${editor.id}` : "manage-none"}
        editor={editor}
        onClose={() => setEditor(null)}
        onSaved={reload}
        onBankDetailAction={setAction}
      />

      <CashBankAccountActionsDialog
        action={action}
        onClose={() => setAction(null)}
        onDone={reload}
      />
    </div>
  );
}
