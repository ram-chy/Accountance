"use client";

import { useState, type FormEvent } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { useCustomerOptions } from "@/lib/customers/use-customer-options";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import {
  DEFAULT_CUSTOMER_STATEMENT_FILTERS,
  type CustomerStatementFilters,
  type CustomerStatementRow,
} from "@/lib/reports/customer-statement-types";
import { useCustomerStatement } from "@/lib/reports/use-customer-statement";

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function typeLabel(row: CustomerStatementRow): string {
  switch (row.type) {
    case "invoice":
      return "Invoice";
    case "receipt":
      return "Receipt";
    case "credit_note":
      return "Credit note";
    case "debit_note":
      return "Debit note";
    default:
      return row.type;
  }
}

export function CustomerStatementView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.reports.view");

  const [draft, setDraft] = useState<CustomerStatementFilters>(DEFAULT_CUSTOMER_STATEMENT_FILTERS);
  const [applied, setApplied] = useState<CustomerStatementFilters>(DEFAULT_CUSTOMER_STATEMENT_FILTERS);
  const [reloadToken, setReloadToken] = useState(0);

  const customersState = useCustomerOptions(companyId, canView);
  const customers = customersState.status === "ready" ? customersState.items : [];

  const state = useCustomerStatement({ companyId, enabled: canView, filters: applied, reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
  };

  const clear = () => {
    setDraft(DEFAULT_CUSTOMER_STATEMENT_FILTERS);
    setApplied(DEFAULT_CUSTOMER_STATEMENT_FILTERS);
  };

  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its customer statements." />;
    }
    if (!canView) {
      return <EmptyState title="Reports unavailable" description="Your role does not include access to reports." />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Reports unavailable" description={state.message} />;
    }
    if (state.status === "idle") {
      return <EmptyState title="Select a customer" description="Choose a customer to generate their account statement." />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading customer statement…" />;
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
          {d.customer.code ? `${d.customer.code} — ` : ""}
          {d.customer.name ?? `Customer ${d.customer.id}`} · {periodLabel} · Currency: {d.base_currency.code ?? "—"}
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
            Receivable-positive: a positive balance is owed to you. Balances are in {d.base_currency.code ?? "the base currency"}.
          </p>
        </div>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Customer Statement</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Invoices, receipts and notes for one customer with a running balance.
        </p>
      </div>

      <form
        onSubmit={apply}
        className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      >
        <FormField label="Customer" htmlFor="stmt-customer" required>
          <select
            id="stmt-customer"
            className={selectClasses}
            value={draft.customer_id === null ? "" : String(draft.customer_id)}
            onChange={(event) =>
              setDraft((current) => ({
                ...current,
                customer_id: event.target.value === "" ? null : Number(event.target.value),
              }))
            }
            disabled={customersState.status !== "ready"}
          >
            <option value="">Select a customer…</option>
            {customers.map((customer) => (
              <option key={customer.id} value={customer.id}>
                {customer.customer_code} — {customer.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="From" htmlFor="stmt-from">
          <Input id="stmt-from" type="date" value={draft.from} onChange={(event) => setDraft((c) => ({ ...c, from: event.target.value }))} />
        </FormField>
        <FormField label="To" htmlFor="stmt-to">
          <Input id="stmt-to" type="date" value={draft.to} onChange={(event) => setDraft((c) => ({ ...c, to: event.target.value }))} />
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
