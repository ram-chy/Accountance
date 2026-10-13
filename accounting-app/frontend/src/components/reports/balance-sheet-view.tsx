"use client";

import { useState, type FormEvent, type ReactNode } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import {
  DEFAULT_BALANCE_SHEET_FILTERS,
  type BalanceSheetFilters,
  type BalanceSheetSection,
} from "@/lib/reports/balance-sheet-types";
import { useBalanceSheet } from "@/lib/reports/use-balance-sheet";

const permissionMsg = "Your role does not include access to reports.";

export function BalanceSheetView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.reports.view");

  const [draft, setDraft] = useState<BalanceSheetFilters>(DEFAULT_BALANCE_SHEET_FILTERS);
  const [applied, setApplied] = useState<BalanceSheetFilters>(DEFAULT_BALANCE_SHEET_FILTERS);
  const [reloadToken, setReloadToken] = useState(0);

  const state = useBalanceSheet({ companyId, enabled: canView, filters: applied, reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
  };

  const clear = () => {
    setDraft(DEFAULT_BALANCE_SHEET_FILTERS);
    setApplied(DEFAULT_BALANCE_SHEET_FILTERS);
  };

  const money = (value: string) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  const renderSection = (title: string, section: BalanceSheetSection, extraRows?: ReactNode) => (
    <div className="flex flex-col gap-2">
      <h2 className="text-lg font-medium">{title}</h2>
      <div className="overflow-x-auto rounded-lg border border-border bg-surface">
        <table className="min-w-full divide-y divide-border text-sm">
          <thead className="bg-surface-muted">
            <tr>
              <th className="px-3 py-2 text-left font-medium text-muted-foreground">Account</th>
              <th className="px-3 py-2 text-right font-medium text-muted-foreground">Amount</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {section.accounts.length === 0 && !extraRows ? (
              <tr>
                <td className="px-3 py-2 text-muted-foreground" colSpan={2}>
                  No accounts.
                </td>
              </tr>
            ) : null}
            {section.accounts.map((row) => (
              <tr key={row.account_id}>
                <td className="px-3 py-2">
                  {row.code} — {row.name}
                </td>
                <td className="px-3 py-2 text-right">{money(row.amount)}</td>
              </tr>
            ))}
            {extraRows}
          </tbody>
          <tfoot className="bg-surface-muted">
            <tr>
              <td className="px-3 py-2 font-medium">Total</td>
              <td className="px-3 py-2 text-right font-medium">{money(section.total)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  );

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its balance sheet." />;
    }
    if (!canView) {
      return <EmptyState title="Reports unavailable" description={permissionMsg} />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading balance sheet…" />;
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

    const d = state.data;
    return (
      <div className="flex flex-col gap-6">
        <p className="text-sm text-muted-foreground">
          As of {d.period.to ?? "today"} · Currency: {d.base_currency.code ?? "—"}
        </p>

        {renderSection("Assets", d.assets)}
        {renderSection("Liabilities", d.liabilities)}
        {renderSection(
          "Equity",
          d.equity,
          <tr key="retained-earnings">
            <td className="px-3 py-2 text-muted-foreground">Retained earnings</td>
            <td className="px-3 py-2 text-right text-muted-foreground">
              {money(d.equity.retained_earnings)}
            </td>
          </tr>,
        )}

        <div className="grid gap-4 sm:grid-cols-3">
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Total assets</div>
            <div className="text-lg font-semibold">{money(d.totals.assets)}</div>
          </div>
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Liabilities + equity</div>
            <div className="text-lg font-semibold">{money(d.totals.liabilities_and_equity)}</div>
          </div>
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Difference</div>
            <div className="text-lg font-semibold">{money(d.totals.difference)}</div>
            <div className="mt-1 text-xs text-muted-foreground">
              Balanced: {d.totals.is_balanced ? "Yes" : "No"}
            </div>
          </div>
        </div>

        <div className="rounded-lg border border-border bg-surface-muted/40 p-4 text-sm">
          <span className="font-medium text-foreground">Current period result: </span>
          <span className="text-foreground">{money(d.current_period_result)}</span>
          <p className="mt-1 text-xs text-muted-foreground">
            Informational only. It is not added into equity — equity already includes retained
            earnings, so adding this would double-count the period.
          </p>
        </div>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Balance Sheet</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Assets, liabilities and equity cumulative through the as-of date.
        </p>
      </div>
      <form
        onSubmit={apply}
        className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      >
        <FormField label="As of" htmlFor="bs-as-of" hint="Point-in-time report. Balances are cumulative through this date.">
          <Input
            id="bs-as-of"
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
      {body}
    </div>
  );
}
