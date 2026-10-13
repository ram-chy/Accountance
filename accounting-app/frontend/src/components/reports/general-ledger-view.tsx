"use client";

import { useState, type FormEvent } from "react";
import { useSearchParams } from "next/navigation";

import { useAccountOptions } from "@/lib/accounts/use-account-options";
import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { LedgerMovements } from "@/components/reports/ledger-movements";
import {
  DEFAULT_GENERAL_LEDGER_FILTERS,
  type GeneralLedgerFilters,
} from "@/lib/reports/general-ledger-types";
import { useGeneralLedger } from "@/lib/reports/use-general-ledger";

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

/**
 * Seed the ledger filters from the URL so a trial balance or P&L drill-down
 * (`/reports/general-ledger?account_id=…&from=…&to=…`) opens on the account and
 * period it linked from. Values are read once, as the initial state; the URL is
 * not re-read on every render and no effect writes state back into the form.
 */
export function readGeneralLedgerFilters(params: SearchParamsLike): GeneralLedgerFilters {
  return {
    account_id: parseAccountId(params.get("account_id")),
    from: params.get("from") ?? "",
    to: params.get("to") ?? "",
  };
}

export function GeneralLedgerView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.reports.view");
  const canViewAccounts = can("accounts.view");
  const searchParams = useSearchParams();

  const [draft, setDraft] = useState<GeneralLedgerFilters>(() => readGeneralLedgerFilters(searchParams));
  const [applied, setApplied] = useState<GeneralLedgerFilters>(() => readGeneralLedgerFilters(searchParams));
  const [reloadToken, setReloadToken] = useState(0);

  /*
   * The account picker walks every page (100 per page, up to 20 pages), so an
   * account beyond the first page of the accounts list is still selectable —
   * unlike a single page-1 request, which would silently cap the picker at 50.
   */
  const accountsState = useAccountOptions(companyId, canView && canViewAccounts);
  const accounts = accountsState.status === "ready" ? accountsState.items : [];

  const state = useGeneralLedger({ companyId, enabled: canView, filters: applied, reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
  };

  const clear = () => {
    setDraft(DEFAULT_GENERAL_LEDGER_FILTERS);
    setApplied(DEFAULT_GENERAL_LEDGER_FILTERS);
  };

  const filterBar = (
    <form
      onSubmit={apply}
      className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
    >
      <FormField label="Account" htmlFor="gl-account">
        <select
          id="gl-account"
          className={selectClasses}
          value={draft.account_id === null ? "" : String(draft.account_id)}
          onChange={(event) =>
            setDraft((current) => ({ ...current, account_id: parseAccountId(event.target.value) }))
          }
          disabled={accountsState.status !== "ready"}
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
      <FormField label="From" htmlFor="gl-from">
        <Input
          id="gl-from"
          type="date"
          value={draft.from}
          onChange={(event) => setDraft((current) => ({ ...current, from: event.target.value }))}
        />
      </FormField>
      <FormField label="To" htmlFor="gl-to">
        <Input
          id="gl-to"
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
      return <EmptyState title="No company selected" description="Choose a company to view its general ledger." />;
    }
    if (!canView) {
      return <EmptyState title="Reports unavailable" description={permissionMsg} />;
    }
    if (accountsState.status === "error") {
      return (
        <ErrorState
          title="Accounts unavailable"
          message={accountsState.message ?? "We could not load the account list."}
        />
      );
    }
    if (accountsState.status === "loading") {
      return <LoadingState label="Loading accounts…" />;
    }
    if (applied.account_id === null) {
      return (
        <EmptyState
          title="Select an account"
          description="Choose an account to see its ledger movements."
        />
      );
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading general ledger…" />;
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
        <h1 className="text-2xl font-semibold text-foreground">General Ledger</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Posted movements for a single account, with a running balance.
        </p>
      </div>
      {filterBar}
      {body}
    </div>
  );
}
