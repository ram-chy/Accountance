"use client";

import { useSearchParams } from "next/navigation";
import { useEffect, useMemo, useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Input } from "@/components/ui/field";
import { useCashBankAccountOptions } from "@/lib/cash-bank/use-cash-bank-accounts";
import { useCashBankTransactionList } from "@/lib/cash-bank/use-cash-bank-transactions";
import {
  CASH_BANK_TRANSACTION_STATUS_VALUES,
  CASH_BANK_TRANSACTION_TYPE_VALUES,
  cashBankTransactionStatusLabel,
  cashBankTransactionTypeLabel,
  type CashBankTransaction,
  type CashBankTransactionFilters,
} from "@/lib/cash-bank/types";

import {
  CashBankTransactionConfirmDialog,
  type CashBankTransactionAction,
} from "./cash-bank-transaction-confirm-dialog";
import { CashBankTransactionFormDialog } from "./cash-bank-transaction-form-dialog";
import { CashBankTransactionsTable } from "./cash-bank-transactions-table";
import { CashBankTransactionDetailDialog } from "./cash-bank-transaction-detail-dialog";
import { CashBankListSkeleton } from "./cash-bank-list-skeleton";

/**
 * Cash & bank transaction list.
 *
 * Deposits, withdrawals and transfers in one list, filtered to the active
 * company. Creation and editing happen in a dialog (the same pattern as
 * journals), because the three types share one field set and differ only in
 * which side must be cash/bank. The account filter is seeded from
 * `?account_id=` so the overview's "Transactions" action can deep-link to one
 * account's movements, which stands in for a per-account detail route.
 *
 * Filters and the current page live in one object so a filter change resets the
 * page to 1 in the same update. The reference box is debounced so typing does not
 * fire a request per keystroke.
 */

type TransactionQuery = CashBankTransactionFilters & { page: number };

const INITIAL_QUERY: TransactionQuery = {
  transaction_type: "",
  status: "",
  account_id: "",
  from: "",
  to: "",
  reference: "",
  page: 1,
};

const SEARCH_DEBOUNCE_MS = 300;

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type Editor = { mode: "create" } | { mode: "edit"; transaction: CashBankTransaction } | null;

export function CashBankTransactionsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const searchParams = useSearchParams();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.cash_bank.view");
  const canCreate = can("accounting.cash_bank.create");
  const canUpdate = can("accounting.cash_bank.update");
  const canPost = can("accounting.cash_bank.post");
  const canDelete = can("accounting.cash_bank.delete");

  const seededAccountId = searchParams.get("account_id") ?? "";

  const [query, setQuery] = useState<TransactionQuery>(() => ({
    ...INITIAL_QUERY,
    account_id: /^\d+$/.test(seededAccountId) ? seededAccountId : "",
  }));
  const [referenceInput, setReferenceInput] = useState("");
  const [reloadToken, setReloadToken] = useState(0);
  const [editor, setEditor] = useState<Editor>(null);
  const [detail, setDetail] = useState<CashBankTransaction | null>(null);
  const [action, setAction] = useState<CashBankTransactionAction | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery((current) =>
        current.reference === referenceInput
          ? current
          : { ...current, reference: referenceInput, page: 1 },
      );
    }, SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [referenceInput]);

  const accountOptions = useCashBankAccountOptions(companyId, canView);

  const filters = useMemo<CashBankTransactionFilters>(
    () => ({
      transaction_type: query.transaction_type,
      status: query.status,
      account_id: query.account_id,
      from: query.from,
      to: query.to,
      reference: query.reference,
    }),
    [query.transaction_type, query.status, query.account_id, query.from, query.to, query.reference],
  );

  const state = useCashBankTransactionList({
    companyId,
    enabled: canView,
    filters,
    page: query.page,
    reloadToken,
  });

  const reload = () => setReloadToken((token) => token + 1);

  const isFiltered =
    query.transaction_type !== "" ||
    query.status !== "" ||
    query.account_id !== "" ||
    query.from !== "" ||
    query.to !== "" ||
    query.reference.trim() !== "";

  const onClearFilters = () => {
    setReferenceInput("");
    setQuery(INITIAL_QUERY);
  };

  const header = (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">Cash &amp; bank transactions</h1>
        <p className="text-sm text-muted-foreground">
          Deposits, withdrawals and transfers recorded for the active company.
        </p>
      </div>
      {canCreate ? (
        <Button onClick={() => setEditor({ mode: "create" })}>New transaction</Button>
      ) : null}
    </div>
  );

  const filterBar = (
    <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="cb-tx-type" className="text-sm font-medium text-foreground">
          Type
        </label>
        <select
          id="cb-tx-type"
          className={selectClasses}
          value={query.transaction_type}
          onChange={(event) =>
            setQuery((current) => ({
              ...current,
              transaction_type: event.target.value as CashBankTransactionFilters["transaction_type"],
              page: 1,
            }))
          }
          disabled={!activeCompany}
        >
          <option value="">All types</option>
          {CASH_BANK_TRANSACTION_TYPE_VALUES.map((value) => (
            <option key={value} value={value}>
              {cashBankTransactionTypeLabel(value)}
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="cb-tx-status" className="text-sm font-medium text-foreground">
          Status
        </label>
        <select
          id="cb-tx-status"
          className={selectClasses}
          value={query.status}
          onChange={(event) =>
            setQuery((current) => ({
              ...current,
              status: event.target.value as CashBankTransactionFilters["status"],
              page: 1,
            }))
          }
          disabled={!activeCompany}
        >
          <option value="">All statuses</option>
          {CASH_BANK_TRANSACTION_STATUS_VALUES.map((value) => (
            <option key={value} value={value}>
              {cashBankTransactionStatusLabel(value)}
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="cb-tx-account" className="text-sm font-medium text-foreground">
          Account
        </label>
        <select
          id="cb-tx-account"
          className={selectClasses}
          value={query.account_id}
          onChange={(event) =>
            setQuery((current) => ({ ...current, account_id: event.target.value, page: 1 }))
          }
          disabled={!activeCompany || accountOptions.status !== "ready"}
        >
          <option value="">All accounts</option>
          {accountOptions.items.map((account) => (
            <option key={account.id} value={account.id}>
              {account.code} — {account.name}
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="cb-tx-from" className="text-sm font-medium text-foreground">
          From
        </label>
        <Input
          id="cb-tx-from"
          type="date"
          value={query.from}
          onChange={(event) =>
            setQuery((current) => ({ ...current, from: event.target.value, page: 1 }))
          }
          disabled={!activeCompany}
        />
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="cb-tx-to" className="text-sm font-medium text-foreground">
          To
        </label>
        <Input
          id="cb-tx-to"
          type="date"
          value={query.to}
          onChange={(event) =>
            setQuery((current) => ({ ...current, to: event.target.value, page: 1 }))
          }
          disabled={!activeCompany}
        />
      </div>

      <div className="flex min-w-56 flex-1 flex-col gap-1.5">
        <label htmlFor="cb-tx-reference" className="text-sm font-medium text-foreground">
          Reference
        </label>
        <Input
          id="cb-tx-reference"
          type="search"
          value={referenceInput}
          onChange={(event) => setReferenceInput(event.target.value)}
          placeholder="Search by reference"
          autoComplete="off"
          disabled={!activeCompany}
        />
      </div>

      {isFiltered ? (
        <Button variant="ghost" onClick={onClearFilters}>
          Clear
        </Button>
      ) : null}
    </div>
  );

  const resultsSummary =
    state.status === "ready" && state.items.length > 0 ? (
      <p className="text-sm text-muted-foreground">
        Showing {state.items.length} transaction{state.items.length === 1 ? "" : "s"} on page{" "}
        {query.page}
        {state.hasMore ? "; more may exist on later pages." : "."}
      </p>
    ) : null;

  const pagination =
    state.status === "ready" ? (
      <div className="flex items-center justify-between gap-3">
        <Button
          variant="secondary"
          disabled={query.page <= 1}
          onClick={() =>
            setQuery((current) => ({ ...current, page: Math.max(1, current.page - 1) }))
          }
        >
          Previous
        </Button>
        <span className="text-sm text-muted-foreground">Page {query.page}</span>
        <Button
          variant="secondary"
          disabled={!state.hasMore}
          onClick={() => setQuery((current) => ({ ...current, page: current.page + 1 }))}
        >
          Next
        </Button>
      </div>
    ) : null;

  const body = (() => {
    if (!activeCompany) {
      return (
        <EmptyState
          title="No company selected"
          description="Choose a company to view its cash and bank transactions."
        />
      );
    }

    if (!canView) {
      return (
        <EmptyState
          title="Cash and bank transactions unavailable"
          description="Your role does not include access to cash and bank transactions."
        />
      );
    }

    if (state.status === "loading") {
      return <CashBankListSkeleton label="Loading cash and bank transactions" />;
    }

    if (state.status === "unavailable") {
      return (
        <EmptyState
          title="Cash and bank transactions unavailable"
          description={state.message}
        />
      );
    }

    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load cash and bank transactions"
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
      if (query.page > 1) {
        return (
          <EmptyState
            title="No transactions on this page"
            description="This page is empty. Return to the previous page to see the remaining transactions."
            action={
              <Button
                variant="secondary"
                onClick={() =>
                  setQuery((current) => ({ ...current, page: Math.max(1, current.page - 1) }))
                }
              >
                Previous page
              </Button>
            }
          />
        );
      }

      if (isFiltered) {
        return (
          <EmptyState
            title="No transactions match"
            description="No transactions match the current filters."
            action={
              <Button variant="secondary" onClick={onClearFilters}>
                Clear filters
              </Button>
            }
          />
        );
      }

      return (
        <EmptyState
          title="No transactions yet"
          description="Record a deposit, withdrawal or transfer to start this company's cash and bank activity."
          action={
            canCreate ? (
              <Button onClick={() => setEditor({ mode: "create" })}>New transaction</Button>
            ) : undefined
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <CashBankTransactionsTable items={state.items} onOpen={(transaction) => setDetail(transaction)} />
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      {header}
      {filterBar}

      {canView && !canCreate ? (
        <Badge variant="warning">
          You can view transactions but not record new ones.
        </Badge>
      ) : null}

      <div className="flex flex-col gap-4">
        {resultsSummary}
        {body}
        {pagination}
      </div>

      <CashBankTransactionFormDialog
        key={editor?.mode === "edit" ? `edit-${editor.transaction.id}` : "create"}
        editor={editor}
        companyId={companyId}
        onClose={() => setEditor(null)}
        onSaved={reload}
      />

      <CashBankTransactionDetailDialog
        transaction={detail}
        canUpdate={canUpdate}
        canDelete={canDelete}
        canPost={canPost}
        onClose={() => setDetail(null)}
        onEdit={(transaction) => {
          setDetail(null);
          setEditor({ mode: "edit", transaction });
        }}
        onAction={(nextAction) => {
          setDetail(null);
          setAction(nextAction);
        }}
      />

      <CashBankTransactionConfirmDialog
        action={action}
        onClose={() => setAction(null)}
        onDone={reload}
      />
    </div>
  );
}
