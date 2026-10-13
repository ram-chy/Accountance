"use client";

import { useState, type FormEvent } from "react";
import { useSearchParams } from "next/navigation";

import { useAccountOptions } from "@/lib/accounts/use-account-options";
import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { useCashBankAccountOptions } from "@/lib/cash-bank/use-cash-bank-accounts";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { LedgerMovements } from "@/components/reports/ledger-movements";
import {
  DEFAULT_CASH_BANK_FILTERS,
  type CashBankFilters,
} from "@/lib/reports/cash-bank-report-types";
import { useCashBankReport } from "@/lib/reports/use-cash-bank-report";

const permissionMsg = "Your role does not include access to reports.";

const selectClasses =
  "h-10 min-w-56 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type SearchParamsLike = { get(key: string): string | null };

function parseAccountId(value: string | null): number | null {
  if (value === null || value === "") return null;
  const parsed = Number.parseInt(value, 10);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

export function readCashBankFilters(params: SearchParamsLike): CashBankFilters {
  return {
    account_id: parseAccountId(params.get("account_id")),
    from: params.get("from") ?? "",
    to: params.get("to") ?? "",
  };
}

export function CashBankReportView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.reports.view");
  const canViewCashBank = can("accounting.cash_bank.view");
  const canViewAccounts = can("accounts.view");
  const searchParams = useSearchParams();

  const [draft, setDraft] = useState<CashBankFilters>(() => readCashBankFilters(searchParams));
  const [applied, setApplied] = useState<CashBankFilters>(() => readCashBankFilters(searchParams));
  const [reloadToken, setReloadToken] = useState(0);

  /*
   * The picker prefers the classified cash/bank accounts (`accounting.cash_bank.view`).
   * Every role that holds `accounting.reports.view` also holds that permission, but
   * the two are separate grants: if a role ever read reports without it, the picker
   * falls back to the company's full account list (`accounts.view`) rather than
   * making the report unusable. Both hooks walk all pages, so an account beyond the
   * first 50 is still selectable.
   */
  const cashBankOptions = useCashBankAccountOptions(companyId, canView && canViewCashBank);
  const accountOptions = useAccountOptions(companyId, canView && canViewAccounts && !canViewCashBank);
  const optionsState = canViewCashBank ? cashBankOptions : accountOptions;
  const accounts = optionsState.status === "ready" ? optionsState.items : [];

  const state = useCashBankReport({ companyId, enabled: canView, filters: applied, reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
  };

  const clear = () => {
    setDraft(DEFAULT_CASH_BANK_FILTERS);
    setApplied(DEFAULT_CASH_BANK_FILTERS);
  };

  const filterBar = (
    <form
      onSubmit={apply}
      className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
    >
      <FormField label="Account" htmlFor="cb-account">
        <select
          id="cb-account"
          className={selectClasses}
          value={draft.account_id === null ? "" : String(draft.account_id)}
          onChange={(event) =>
            setDraft((current) => ({ ...current, account_id: parseAccountId(event.target.value) }))
          }
          disabled={optionsState.status !== "ready"}
        >
          <option value="">Select an account…</option>
          {accounts.map((account) => (
            <option key={account.id} value={account.id}>
              {account.code} — {account.name}
              {account.is_active ? "" : " (inactive)"}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="From" htmlFor="cb-from">
        <Input
          id="cb-from"
          type="date"
          value={draft.from}
          onChange={(event) => setDraft((current) => ({ ...current, from: event.target.value }))}
        />
      </FormField>
      <FormField label="To" htmlFor="cb-to">
        <Input
          id="cb-to"
          type="date"
          value={draft.to}
          onChange={(event) => setDraft((current) => ({ ...current, to: event.target.value }))}
        />
      </FormField>
      <div className="flex gap-2 pb-2">
        <Button type="submit">Apply</Button>
        <Button type="button" variant="secondary" onClick={clear}>
          Clear
        </Button>
      </div>
    </form>
  );

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its cash and bank report." />;
    }
    if (!canView) {
      return <EmptyState title="Reports unavailable" description={permissionMsg} />;
    }
    if (optionsState.status === "error") {
      return (
        <ErrorState
          title="Accounts unavailable"
          message={optionsState.message ?? "We could not load the account list."}
        />
      );
    }
    if (optionsState.status === "loading") {
      return <LoadingState label="Loading accounts…" />;
    }
    if (accounts.length === 0) {
      return (
        <EmptyState
          title="No accounts available"
          description="Classify at least one cash or bank account to run this report."
        />
      );
    }
    if (applied.account_id === null) {
      return (
        <EmptyState
          title="Select an account"
          description="Choose a cash or bank account to see its movements."
        />
      );
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading cash and bank report…" />;
    }
    if (state.status === "error") {
      return (
        <ErrorState
          message={state.message}
          requestId={state.requestId}
          action={
            <Button type="button" onClick={() => setReloadToken((token) => token + 1)}>
              Retry
            </Button>
          }
        />
      );
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Reports unavailable" description={state.message} />;
    }
    return <LedgerMovements report={state.data} />;
  })();

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Cash &amp; Bank</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Posted movements for a cash or bank account, with a running balance.
        </p>
      </div>
      {filterBar}
      {body}
    </div>
  );
}
