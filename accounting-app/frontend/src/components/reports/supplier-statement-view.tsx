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
  DEFAULT_SUPPLIER_STATEMENT_FILTERS,
  type SupplierStatementFilters,
  type SupplierStatementRow,
} from "@/lib/reports/supplier-statement-types";
import { useSupplierStatement } from "@/lib/reports/use-supplier-statement";
import { useSupplierOptions } from "@/lib/suppliers/use-supplier-options";

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function typeLabel(row: SupplierStatementRow): string {
  switch (row.type) {
    case "bill":
      return "Bill";
    case "payment":
      return "Payment";
    case "credit_note":
      return "Credit note";
    case "debit_note":
      return "Debit note";
    default:
      return row.type;
  }
}

export function SupplierStatementView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.reports.view");

  const [draft, setDraft] = useState<SupplierStatementFilters>(DEFAULT_SUPPLIER_STATEMENT_FILTERS);
  const [applied, setApplied] = useState<SupplierStatementFilters>(DEFAULT_SUPPLIER_STATEMENT_FILTERS);
  const [reloadToken, setReloadToken] = useState(0);

  const suppliersState = useSupplierOptions(companyId, canView);
  const suppliers = suppliersState.status === "ready" ? suppliersState.items : [];

  const state = useSupplierStatement({ companyId, enabled: canView, filters: applied, reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
  };

  const clear = () => {
    setDraft(DEFAULT_SUPPLIER_STATEMENT_FILTERS);
    setApplied(DEFAULT_SUPPLIER_STATEMENT_FILTERS);
  };

  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its supplier statements." />;
    }
    if (!canView) {
      return <EmptyState title="Reports unavailable" description="Your role does not include access to reports." />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Reports unavailable" description={state.message} />;
    }
    if (state.status === "idle") {
      return <EmptyState title="Select a supplier" description="Choose a supplier to generate their account statement." />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading supplier statement…" />;
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
    const periodLabel =
      d.period.from || d.period.to
        ? `${d.period.from ?? "start"} → ${d.period.to ?? "today"}`
        : "All dates";

    return (
      <div className="flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">
          {d.supplier.code ? `${d.supplier.code} — ` : ""}
          {d.supplier.name ?? `Supplier ${d.supplier.id}`} · {periodLabel} · Currency: {d.base_currency.code ?? "—"}
        </p>

        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead className="bg-surface-muted">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Type</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Reference</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Description</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Debit</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Credit</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Balance</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              <tr className="text-muted-foreground">
                <td className="px-3 py-2" colSpan={6}>
                  Opening balance
                </td>
                <td className="px-3 py-2 text-right">{money(d.opening_balance)}</td>
              </tr>
              {d.rows.length === 0 ? (
                <tr>
                  <td className="px-3 py-2 text-muted-foreground" colSpan={7}>
                    No movements in this period.
                  </td>
                </tr>
              ) : (
                d.rows.map((row, index) => (
                  <tr key={`${row.type}-${row.reference ?? "row"}-${index}`}>
                    <td className="px-3 py-2">{row.date ?? "—"}</td>
                    <td className="px-3 py-2">{typeLabel(row)}</td>
                    <td className="px-3 py-2 font-mono text-xs">{row.reference ?? "—"}</td>
                    <td className="px-3 py-2 text-muted-foreground">{row.description ?? "—"}</td>
                    <td className="px-3 py-2 text-right">{row.debit === "0.0000" ? "—" : money(row.debit)}</td>
                    <td className="px-3 py-2 text-right">{row.credit === "0.0000" ? "—" : money(row.credit)}</td>
                    <td className="px-3 py-2 text-right">{money(row.running_balance)}</td>
                  </tr>
                ))
              )}
            </tbody>
            <tfoot className="bg-surface-muted">
              <tr>
                <td className="px-3 py-2 font-medium" colSpan={4}>
                  Totals
                </td>
                <td className="px-3 py-2 text-right font-medium">{money(d.totals.debit)}</td>
                <td className="px-3 py-2 text-right font-medium">{money(d.totals.credit)}</td>
                <td className="px-3 py-2 text-right font-medium">{money(d.closing_balance)}</td>
              </tr>
            </tfoot>
          </table>
        </div>

        <div className="rounded-lg border border-border bg-surface p-4">
          <div className="text-sm text-muted-foreground">Closing balance</div>
          <div className="text-lg font-semibold">{money(d.closing_balance)}</div>
          <p className="mt-1 text-xs text-muted-foreground">
            Payable-positive: a positive balance is owed to the supplier. Balances are in {d.base_currency.code ?? "the base currency"}.
          </p>
        </div>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Supplier Statement</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Bills, payments and notes for one supplier with a running balance.
        </p>
      </div>

      <form
        onSubmit={apply}
        className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      >
        <FormField label="Supplier" htmlFor="sstmt-supplier" required>
          <select
            id="sstmt-supplier"
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
            <option value="">Select a supplier…</option>
            {suppliers.map((supplier) => (
              <option key={supplier.id} value={supplier.id}>
                {supplier.supplier_code} — {supplier.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="From" htmlFor="sstmt-from">
          <Input id="sstmt-from" type="date" value={draft.from} onChange={(event) => setDraft((c) => ({ ...c, from: event.target.value }))} />
        </FormField>
        <FormField label="To" htmlFor="sstmt-to">
          <Input id="sstmt-to" type="date" value={draft.to} onChange={(event) => setDraft((c) => ({ ...c, to: event.target.value }))} />
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
