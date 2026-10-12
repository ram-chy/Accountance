"use client";

import { useState, type FormEvent } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { MONEY_DISPLAY_DECIMALS, formatMoney } from "@/lib/format/money";

import {
  DEFAULT_TAX_RANGE_FILTERS,
  type TaxRangeFilters,
  type TaxReportRow,
  type TaxReportTotals,
} from "@/lib/taxes/types";
import { useTaxReport } from "@/lib/taxes/use-tax-report";
import { validateReportRange } from "@/lib/taxes/validators";

/**
 * Tax reports screen.
 *
 * Two read-only reports share one date window: the summary (collected, recovered
 * and net tax) and the by-tax breakdown (the same figures, one row per tax). Both
 * are derived from posted documents' own line snapshots, so changing a rate never
 * restates a past period. All figures are rendered exactly as returned — the
 * frontend performs no arithmetic on them.
 *
 * The window is the inclusive `from`/`to` bound; leaving one empty means unbounded
 * on that side. A row whose tax was deleted keeps its figures with a null
 * code/name, and is labelled accordingly rather than dropped.
 */

const WHERE_LABELS: { key: keyof TaxReportTotals; label: string }[] = [
  { key: "sales_taxable", label: "Sales taxable" },
  { key: "output_tax", label: "Output tax" },
  { key: "purchase_taxable", label: "Purchase taxable" },
  { key: "input_tax", label: "Input tax" },
  { key: "net_tax", label: "Net tax" },
  { key: "unattributed_tax", label: "Unattributed tax" },
];

function amount(value: string): string {
  return formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });
}

function TotalsGrid({ totals }: { totals: TaxReportTotals }) {
  return (
    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {WHERE_LABELS.map(({ key, label }) => (
        <div key={key} className="flex flex-col gap-1">
          <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            {label}
          </dt>
          <dd className="text-lg font-semibold text-foreground">{amount(totals[key])}</dd>
        </div>
      ))}
    </dl>
  );
}

function taxLabel(row: TaxReportRow): string {
  if (row.tax_code === null && row.tax_name === null) {
    return row.tax_id === null ? "Unattributed" : `Tax #${row.tax_id}`;
  }

  return [row.tax_code, row.tax_name].filter(Boolean).join(" · ");
}

export function TaxReportsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.tax.report.view");

  const [draft, setDraft] = useState<TaxRangeFilters>(DEFAULT_TAX_RANGE_FILTERS);
  const [applied, setApplied] = useState<TaxRangeFilters>(DEFAULT_TAX_RANGE_FILTERS);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [reloadToken, setReloadToken] = useState(0);

  const state = useTaxReport({ companyId, enabled: canView, filters: applied, reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFieldErrors({});

    const errors = validateReportRange(draft);

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    setApplied(draft);
  };

  const clear = () => {
    setDraft(DEFAULT_TAX_RANGE_FILTERS);
    setApplied(DEFAULT_TAX_RANGE_FILTERS);
    setFieldErrors({});
  };

  const filterBar = (
    <form
      onSubmit={apply}
      className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
    >
      <FormField label="From" htmlFor="report-from" error={fieldErrors.from}>
        <Input
          id="report-from"
          type="date"
          value={draft.from}
          onChange={(event) => setDraft((current) => ({ ...current, from: event.target.value }))}
          invalid={Boolean(fieldErrors.from)}
          aria-describedby={fieldErrors.from ? "report-from-error" : undefined}
        />
      </FormField>

      <FormField label="To" htmlFor="report-to" error={fieldErrors.to}>
        <Input
          id="report-to"
          type="date"
          value={draft.to}
          onChange={(event) => setDraft((current) => ({ ...current, to: event.target.value }))}
          invalid={Boolean(fieldErrors.to)}
          aria-describedby={fieldErrors.to ? "report-to-error" : undefined}
        />
      </FormField>

      <div className="flex gap-2">
        <Button type="submit">Apply</Button>
        <Button type="button" variant="secondary" onClick={clear}>
          Clear
        </Button>
      </div>
    </form>
  );

  const body = (() => {
    if (!activeCompany) {
      return (
        <EmptyState title="No company selected" description="Choose a company to view its tax reports." />
      );
    }

    if (!canView) {
      return (
        <EmptyState
          title="Tax reports unavailable"
          description="Your role does not include access to tax reports."
        />
      );
    }

    if (state.status === "loading") {
      return <LoadingState label="Loading tax reports…" />;
    }

    if (state.status === "unavailable") {
      return <EmptyState title="Tax reports unavailable" description={state.message} />;
    }

    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load the tax reports"
          message={state.message}
          requestId={state.requestId}
          action={
            <Button variant="secondary" onClick={() => setReloadToken((token) => token + 1)}>
              Try again
            </Button>
          }
        />
      );
    }

    const summary = state.summary;
    const byTax = state.byTax;

    if (summary === null || byTax === null) {
      return <EmptyState title="No report data" description="There is no tax activity to report." />;
    }

    return (
      <div className="flex flex-col gap-6">
        <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4 sm:p-6">
          <div className="flex flex-col gap-1">
            <h2 className="text-base font-semibold text-foreground">Summary</h2>
            <p className="text-sm text-muted-foreground">
              {summary.period.from === null && summary.period.to === null
                ? "All posted documents."
                : `Posted documents from ${summary.period.from ?? "the beginning"} to ${
                    summary.period.to ?? "now"
                  }.`}
            </p>
          </div>
          <TotalsGrid totals={summary.totals} />
        </section>

        <section className="flex flex-col gap-4">
          <div className="flex flex-col gap-1">
            <h2 className="text-base font-semibold text-foreground">By tax</h2>
            <p className="text-sm text-muted-foreground">
              The same window, broken down one row per tax.
            </p>
            {byTax.base_currency?.code ? (
              <p className="text-sm text-muted-foreground">
                Amounts are in {byTax.base_currency.code}.
              </p>
            ) : null}
          </div>

          {byTax.rows.length === 0 ? (
            <EmptyState
              title="No tax activity"
              description="No posted documents carried tax in this period."
            />
          ) : (
            <div className="overflow-x-auto rounded-lg border border-border">
              <table className="w-full border-collapse text-left">
                <caption className="sr-only">Tax report by tax</caption>
                <thead>
                  <tr className="border-b border-border bg-surface-muted">
                    <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
                      Tax
                    </th>
                    {WHERE_LABELS.map(({ key, label }) => (
                      <th
                        key={key}
                        scope="col"
                        className="px-3 py-2 text-right text-xs font-medium text-muted-foreground"
                      >
                        {label}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {byTax.rows.map((row, index) => (
                    <tr
                      key={row.tax_id ?? `unattributed-${index}`}
                      className="border-b border-border last:border-0"
                    >
                      <td className="px-3 py-2 text-sm text-foreground">{taxLabel(row)}</td>
                      {WHERE_LABELS.map(({ key }) => (
                        <td
                          key={key}
                          className="px-3 py-2 text-right text-sm text-muted-foreground"
                        >
                          {amount(row[key])}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t border-border bg-surface-muted font-medium">
                    <td className="px-3 py-2 text-sm text-foreground">Total</td>
                    {WHERE_LABELS.map(({ key }) => (
                      <td key={key} className="px-3 py-2 text-right text-sm text-foreground">
                        {amount(byTax.totals[key])}
                      </td>
                    ))}
                  </tr>
                </tfoot>
              </table>
            </div>
          )}
        </section>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">Tax reports</h1>
        <p className="text-sm text-muted-foreground">
          Collected and recovered tax for posted documents, taken from each line&apos;s own tax
          snapshot.
        </p>
      </div>

      {canView && activeCompany ? filterBar : null}
      {body}
    </div>
  );
}
