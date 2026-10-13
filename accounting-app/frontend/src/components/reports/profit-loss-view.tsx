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

import { DEFAULT_PROFIT_LOSS_FILTERS, type ProfitLossFilters } from "@/lib/reports/profit-loss-types";
import { useProfitLoss } from "@/lib/reports/use-profit-loss";

const permissionMsg = "Your role does not include access to reports.";

export function ProfitLossView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.reports.view");

  const [draft, setDraft] = useState<ProfitLossFilters>(DEFAULT_PROFIT_LOSS_FILTERS);
  const [applied, setApplied] = useState<ProfitLossFilters>(DEFAULT_PROFIT_LOSS_FILTERS);
  const [reloadToken, setReloadToken] = useState(0);

  const state = useProfitLoss({ companyId, enabled: canView, filters: applied, reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
  };
  const clear = () => {
    setDraft(DEFAULT_PROFIT_LOSS_FILTERS);
    setApplied(DEFAULT_PROFIT_LOSS_FILTERS);
  };

  const filterBar = (
    <form onSubmit={apply} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end">
      <FormField label="From" htmlFor="pl-from">
        <Input id="pl-from" type="date" value={draft.from} onChange={(e) => setDraft((c) => ({ ...c, from: e.target.value }))} />
      </FormField>
      <FormField label="To" htmlFor="pl-to">
        <Input id="pl-to" type="date" value={draft.to} onChange={(e) => setDraft((c) => ({ ...c, to: e.target.value }))} />
      </FormField>
      <div className="flex gap-2">
        <Button type="submit">Apply</Button>
        <Button type="button" variant="secondary" onClick={clear}>Clear</Button>
      </div>
    </form>
  );

  const renderSection = (title: string, total: string, accounts: Array<{ account_id: number; code: string; name: string; amount: string }>) => (
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
            {accounts.map((a) => {
              const link = `/reports/general-ledger?account_id=${a.account_id}${applied.from ? `&from=${applied.from}` : ""}${applied.to ? `&to=${applied.to}` : ""}`;
              return (
                <tr key={a.account_id}>
                  <td className="px-3 py-2">
                    <Link href={link} className="font-medium text-primary hover:underline">{a.code} — {a.name}</Link>
                  </td>
                  <td className="px-3 py-2 text-right">{formatMoney(a.amount, { decimals: MONEY_DISPLAY_DECIMALS })}</td>
                </tr>
              );
            })}
          </tbody>
          <tfoot className="bg-surface-muted">
            <tr>
              <td className="px-3 py-2 font-medium">Total</td>
              <td className="px-3 py-2 text-right font-medium">{formatMoney(total, { decimals: MONEY_DISPLAY_DECIMALS })}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  );

  const body = (() => {
    if (!activeCompany) return <EmptyState title="No company selected" description="Choose a company." />;
    if (!canView) return <EmptyState title="Reports unavailable" description={permissionMsg} />;
    if (state.status === "loading") return <LoadingState label="Loading profit & loss…" />;
    if (state.status === "error") return <ErrorState message={state.message} requestId={state.requestId} action={<Button type="button" onClick={() => setReloadToken((t) => t + 1)}>Retry</Button>} />;
    if (state.status === "unavailable") return <EmptyState title="Reports unavailable" description={state.message} />;
    const d = state.data;
    return (
      <div className="flex flex-col gap-6">
        {renderSection("Revenue", d.revenue.total, d.revenue.accounts)}
        {renderSection("Expenses", d.expenses.total, d.expenses.accounts)}
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Gross profit</div>
            <div className="text-lg font-semibold">{formatMoney(d.gross_profit, { decimals: MONEY_DISPLAY_DECIMALS })}</div>
          </div>
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Net profit</div>
            <div className="text-lg font-semibold">{formatMoney(d.net_profit, { decimals: MONEY_DISPLAY_DECIMALS })}</div>
            <div className="text-xs text-muted-foreground mt-1">Profitable: {d.is_profitable ? "Yes" : "No"}</div>
          </div>
        </div>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Profit & Loss</h1>
        <p className="mt-1 text-sm text-muted-foreground">Revenue vs expenses for the selected period.</p>
      </div>
      {filterBar}
      {body}
    </div>
  );
}
