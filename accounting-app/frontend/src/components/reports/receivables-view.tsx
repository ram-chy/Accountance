"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { useCustomerOptions } from "@/lib/customers/use-customer-options";
import { formatDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import {
  DEFAULT_RECEIVABLES_FILTERS,
  type ReceivablesFilters,
} from "@/lib/reports/receivables-types";
import { useReceivables } from "@/lib/reports/use-receivables";

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export function ReceivablesView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.reports.view");

  const [draft, setDraft] = useState<ReceivablesFilters>(DEFAULT_RECEIVABLES_FILTERS);
  const [applied, setApplied] = useState<ReceivablesFilters>(DEFAULT_RECEIVABLES_FILTERS);
  const [reloadToken, setReloadToken] = useState(0);

  const customersState = useCustomerOptions(companyId, canView);
  const customers = customersState.status === "ready" ? customersState.items : [];

  const state = useReceivables({ companyId, enabled: canView, filters: applied, reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
  };

  const clear = () => {
    setDraft(DEFAULT_RECEIVABLES_FILTERS);
    setApplied(DEFAULT_RECEIVABLES_FILTERS);
  };

  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its receivables." />;
    }
    if (!canView) {
      return <EmptyState title="Reports unavailable" description="Your role does not include access to reports." />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Reports unavailable" description={state.message} />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading receivables…" />;
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
          As of {d.as_of} · {d.count} {d.count === 1 ? "invoice" : "invoices"} · Currency: {d.base_currency.code ?? "—"}
        </p>

        {d.rows.length === 0 ? (
          <EmptyState title="Nothing outstanding" description="No posted invoices are owed as of this date." />
        ) : (
          <div className="overflow-x-auto rounded-lg border border-border bg-surface">
            <table className="min-w-full divide-y divide-border text-sm">
              <thead className="bg-surface-muted">
                <tr>
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Invoice</th>
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Customer</th>
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Due</th>
                  <th className="px-3 py-2 text-right font-medium text-muted-foreground">Total</th>
                  <th className="px-3 py-2 text-right font-medium text-muted-foreground">Paid</th>
                  <th className="px-3 py-2 text-right font-medium text-muted-foreground">Outstanding</th>
                  <th className="px-3 py-2 text-right font-medium text-muted-foreground">Overdue</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {d.rows.map((row) => (
                  <tr key={row.invoice_id}>
                    <td className="px-3 py-2 font-mono text-xs">
                      <Link className="text-foreground hover:underline" href={`/sales-invoices/${row.invoice_id}`}>
                        {row.invoice_number ?? `#${row.invoice_id}`}
                      </Link>
                      {row.currency_code ? (
                        <span className="ml-2 text-muted-foreground">{row.currency_code}</span>
                      ) : null}
                    </td>
                    <td className="px-3 py-2">{row.customer.name ?? row.customer.id ?? "—"}</td>
                    <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(row.invoice_date)}</td>
                    <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(row.due_date)}</td>
                    <td className="px-3 py-2 text-right">{money(row.grand_total)}</td>
                    <td className="px-3 py-2 text-right">{money(row.paid_total)}</td>
                    <td className="px-3 py-2 text-right font-medium">{money(row.balance_due)}</td>
                    <td className="px-3 py-2 text-right">
                      {row.days_past_due && row.days_past_due > 0 ? (
                        <Badge variant="warning">{row.days_past_due}d</Badge>
                      ) : (
                        <Badge variant="neutral">Current</Badge>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="bg-surface-muted">
                <tr>
                  <td className="px-3 py-2 font-medium" colSpan={4}>
                    Totals
                  </td>
                  <td className="px-3 py-2 text-right font-medium">{money(d.totals.grand_total)}</td>
                  <td className="px-3 py-2 text-right font-medium">{money(d.totals.paid_total)}</td>
                  <td className="px-3 py-2 text-right font-medium">{money(d.totals.balance_due)}</td>
                  <td className="px-3 py-2" />
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
        <h1 className="text-2xl font-semibold text-foreground">Receivables</h1>
        <p className="mt-1 text-sm text-muted-foreground">Posted invoices with a balance still owed, as of a date.</p>
      </div>

      <form
        onSubmit={apply}
        className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      >
        <FormField label="Customer" htmlFor="ar-customer">
          <select
            id="ar-customer"
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
            <option value="">All customers</option>
            {customers.map((customer) => (
              <option key={customer.id} value={customer.id}>
                {customer.customer_code} — {customer.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="As of" htmlFor="ar-as-of" hint="Defaults to today when left blank.">
          <Input id="ar-as-of" type="date" value={draft.as_of} onChange={(event) => setDraft((c) => ({ ...c, as_of: event.target.value }))} />
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
