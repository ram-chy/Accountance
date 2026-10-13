"use client";

import { useState, type FormEvent } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import {
  DEFAULT_PAYABLES_AGING_FILTERS,
  type PayablesAgingFilters,
} from "@/lib/reports/payables-aging-types";
import { usePayablesAging } from "@/lib/reports/use-payables-aging";
import { useSupplierOptions } from "@/lib/suppliers/use-supplier-options";

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export function PayablesAgingView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.reports.view");

  const [draft, setDraft] = useState<PayablesAgingFilters>(DEFAULT_PAYABLES_AGING_FILTERS);
  const [applied, setApplied] = useState<PayablesAgingFilters>(DEFAULT_PAYABLES_AGING_FILTERS);
  const [reloadToken, setReloadToken] = useState(0);

  const suppliersState = useSupplierOptions(companyId, canView);
  const suppliers = suppliersState.status === "ready" ? suppliersState.items : [];

  const state = usePayablesAging({ companyId, enabled: canView, filters: applied, reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
  };

  const clear = () => {
    setDraft(DEFAULT_PAYABLES_AGING_FILTERS);
    setApplied(DEFAULT_PAYABLES_AGING_FILTERS);
  };

  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its aged payables." />;
    }
    if (!canView) {
      return <EmptyState title="Reports unavailable" description="Your role does not include access to reports." />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Reports unavailable" description={state.message} />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading aged payables…" />;
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

    const d = state.data;

    return (
      <div className="flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">
          As of {d.as_of} · Currency: {d.base_currency.code ?? "—"}
        </p>

        {d.rows.length === 0 ? (
          <EmptyState title="Nothing outstanding" description="No posted bills are owed as of this date." />
        ) : (
          <div className="overflow-x-auto rounded-lg border border-border bg-surface">
            <table className="min-w-full divide-y divide-border text-sm">
              <thead className="bg-surface-muted">
                <tr>
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Supplier</th>
                  {d.buckets.map((bucket) => (
                    <th key={bucket.key} className="px-3 py-2 text-right font-medium text-muted-foreground">
                      {bucket.label}
                    </th>
                  ))}
                  <th className="px-3 py-2 text-right font-medium text-muted-foreground">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {d.rows.map((row) => (
                  <tr key={row.counterparty.id ?? "unknown"}>
                    <td className="px-3 py-2">{row.counterparty.name ?? row.counterparty.id ?? "—"}</td>
                    {d.buckets.map((bucket) => (
                      <td key={bucket.key} className="px-3 py-2 text-right text-muted-foreground">
                        {money(row.buckets[bucket.key] ?? "0.0000")}
                      </td>
                    ))}
                    <td className="px-3 py-2 text-right font-medium">{money(row.total)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="bg-surface-muted">
                <tr>
                  <td className="px-3 py-2 font-medium">Total</td>
                  {d.buckets.map((bucket) => (
                    <td key={bucket.key} className="px-3 py-2 text-right font-medium">
                      {money(d.totals.by_bucket[bucket.key] ?? bucket.total)}
                    </td>
                  ))}
                  <td className="px-3 py-2 text-right font-medium">{money(d.totals.total)}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        )}
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Aged Payables</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Outstanding bills grouped by age, by supplier, as of a date.
        </p>
      </div>

      <form
        onSubmit={apply}
        className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      >
        <FormField label="Supplier" htmlFor="apa-supplier">
          <select
            id="apa-supplier"
            className={selectClasses}
            value={draft.supplier_id === null ? "" : String(draft.supplier_id)}
            onChange={(event) =>
              setDraft((current) => ({
                ...current,
                supplier_id: event.target.value === "" ? null : Number(event.target.value),
              }))
            }
            disabled={suppliersState.status !== "ready"}
          >
            <option value="">All suppliers</option>
            {suppliers.map((supplier) => (
              <option key={supplier.id} value={supplier.id}>
                {supplier.supplier_code} — {supplier.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="As of" htmlFor="apa-as-of" hint="Defaults to today when left blank.">
          <Input id="apa-as-of" type="date" value={draft.as_of} onChange={(event) => setDraft((c) => ({ ...c, as_of: event.target.value }))} />
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
