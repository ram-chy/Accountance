"use client";

import { useState, type FormEvent } from "react";
import Link from "next/link";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";

import { DEFAULT_TRIAL_BALANCE_FILTERS, type TrialBalanceFilters } from "@/lib/reports/trial-balance-types";
import { useTrialBalance } from "@/lib/reports/use-trial-balance";

const permissionMsg = "Your role does not include access to reports.";

export function TrialBalanceView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.reports.view");

  const [draft, setDraft] = useState<TrialBalanceFilters>(DEFAULT_TRIAL_BALANCE_FILTERS);
  const [applied, setApplied] = useState<TrialBalanceFilters>(DEFAULT_TRIAL_BALANCE_FILTERS);
  const [reloadToken, setReloadToken] = useState(0);

  const state = useTrialBalance({ companyId, enabled: canView, filters: applied, reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
  };

  const clear = () => {
    setDraft(DEFAULT_TRIAL_BALANCE_FILTERS);
    setApplied(DEFAULT_TRIAL_BALANCE_FILTERS);
  };

  const filterBar = (
    <form
      onSubmit={apply}
      className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
    >
      <FormField label="From" htmlFor="tb-from">
        <Input
          id="tb-from"
          type="date"
          value={draft.from}
          onChange={(e) => setDraft((c) => ({ ...c, from: e.target.value }))}
        />
      </FormField>
      <FormField label="To" htmlFor="tb-to">
        <Input
          id="tb-to"
          type="date"
          value={draft.to}
          onChange={(e) => setDraft((c) => ({ ...c, to: e.target.value }))}
        />
      </FormField>
      <div className="flex items-center gap-2 pb-2">
        <input type="checkbox" id="tb-zero" checked={draft.include_zero_balances} onChange={(e) => setDraft((c) => ({ ...c, include_zero_balances: e.target.checked }))} />
        <label htmlFor="tb-zero" className="text-sm text-foreground">Include zero balances</label>
      </div>
      <div className="flex gap-2">
        <Button type="submit">Apply</Button>
        <Button type="button" variant="secondary" onClick={clear}>Clear</Button>
      </div>
    </form>
  );

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its trial balance." />;
    }
    if (!canView) {
      return <EmptyState title="Reports unavailable" description={permissionMsg} />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading trial balance…" />;
    }
    if (state.status === "error") {
      return <ErrorState message={state.message} requestId={state.requestId} action={<Button type="button" onClick={() => setReloadToken((t) => t + 1)}>Retry</Button>} />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Reports unavailable" description={state.message} />;
    }
    const d = state.data;
    if (d.rows.length === 0) {
      return <EmptyState title="No data" description="No accounts found for the selected range." />;
    }
    return (
      <div className="flex flex-col gap-4">
        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead className="bg-surface-muted">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Account</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Debit</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Credit</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Net</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {d.rows.map((r) => {
                const glLink = `/reports/general-ledger?account_id=${r.account_id}${applied.from ? `&from=${applied.from}` : ""}${applied.to ? `&to=${applied.to}` : ""}`;
                return (
                  <tr key={r.account_id}>
                    <td className="px-3 py-2">
                      <div className="flex flex-col">
                        <Link href={glLink} className="font-medium text-primary hover:underline">{r.code} — {r.name}</Link>
                        <span className="text-xs text-muted-foreground">{r.account_type}</span>
                      </div>
                    </td>
                    <td className="px-3 py-2 text-right">{formatMoney(r.debit_total, { decimals: MONEY_DISPLAY_DECIMALS })}</td>
                    <td className="px-3 py-2 text-right">{formatMoney(r.credit_total, { decimals: MONEY_DISPLAY_DECIMALS })}</td>
                    <td className="px-3 py-2 text-right">{formatMoney(r.net_balance, { decimals: MONEY_DISPLAY_DECIMALS })}</td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot className="bg-surface-muted">
              <tr>
                <td className="px-3 py-2 font-medium">Totals</td>
                <td className="px-3 py-2 text-right font-medium">{formatMoney(d.totals.debit_total, { decimals: MONEY_DISPLAY_DECIMALS })}</td>
                <td className="px-3 py-2 text-right font-medium">{formatMoney(d.totals.credit_total, { decimals: MONEY_DISPLAY_DECIMALS })}</td>
                <td className="px-3 py-2 text-right font-medium">{formatMoney(d.totals.net_balance, { decimals: MONEY_DISPLAY_DECIMALS })}</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div className="text-sm text-muted-foreground">
          Balanced: {d.totals.is_balanced ? "Yes" : "No"} · Difference: {formatMoney(d.totals.difference, { decimals: MONEY_DISPLAY_DECIMALS })}
        </div>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Trial Balance</h1>
        <p className="mt-1 text-sm text-muted-foreground">Posted activity by account with debit/credit/net totals.</p>
      </div>
      {filterBar}
      {body}
    </div>
  );
}
