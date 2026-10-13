"use client";

import { EmptyState } from "@/components/ui/empty-state";
import { formatDecimal, formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import type { GeneralLedgerResponse } from "@/lib/reports/general-ledger-types";

/**
 * Renders a general-ledger-shaped report: account header, opening balance,
 * dated movements with a running balance, and the period/closing totals.
 *
 * The General Ledger and Cash & Bank reports share this component because the
 * backend shares the response — `CashBankReportService` delegates straight to
 * `GeneralLedgerReportService`. Every figure is printed from the backend
 * payload; nothing is recomputed here.
 *
 * The journal number is rendered as text, not a link: there is no journal
 * detail route in the app (`app/(app)/journals/page.tsx` is the only route),
 * and the brief is explicit that a drill-down link is added only when the route
 * and identifier match the backend contract.
 */
export function LedgerMovements({
  report,
  emptyTitle = "No movements",
  emptyDescription = "This account has no posted movements in the selected period.",
}: {
  report: GeneralLedgerResponse;
  emptyTitle?: string;
  emptyDescription?: string;
}) {
  const { account, period, base_currency: currency, rows } = report;
  const periodLabel =
    period.from && period.to
      ? `${period.from} to ${period.to}`
      : period.to
        ? `Through ${period.to}`
        : "All posted activity";

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1 rounded-lg border border-border bg-surface p-4">
        <div className="flex flex-wrap items-baseline gap-x-2">
          <span className="text-base font-semibold text-foreground">
            {account.code} — {account.name}
          </span>
          {account.is_active ? null : (
            <span className="text-xs font-medium text-muted-foreground">(inactive)</span>
          )}
        </div>
        <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
          <span>Type: {account.account_type}</span>
          <span>Normal balance: {account.normal_balance}</span>
          <span>Period: {periodLabel}</span>
          <span>Currency: {currency.code ?? "—"}</span>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <div className="rounded-lg border border-border bg-surface p-4">
          <div className="text-sm text-muted-foreground">Opening balance</div>
          <div className="text-lg font-semibold">
            {formatMoney(report.opening_balance, { decimals: MONEY_DISPLAY_DECIMALS })}
          </div>
        </div>
        <div className="rounded-lg border border-border bg-surface p-4">
          <div className="text-sm text-muted-foreground">Period debits</div>
          <div className="text-lg font-semibold">
            {formatMoney(report.period_debits, { decimals: MONEY_DISPLAY_DECIMALS })}
          </div>
        </div>
        <div className="rounded-lg border border-border bg-surface p-4">
          <div className="text-sm text-muted-foreground">Period credits</div>
          <div className="text-lg font-semibold">
            {formatMoney(report.period_credits, { decimals: MONEY_DISPLAY_DECIMALS })}
          </div>
        </div>
      </div>

      {rows.length === 0 ? (
        <EmptyState title={emptyTitle} description={emptyDescription} />
      ) : (
        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead className="bg-surface-muted">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Journal</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Reference</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Description</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Debit</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Credit</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Balance</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {rows.map((row) => (
                <tr key={row.journal_line_id}>
                  <td className="whitespace-nowrap px-3 py-2">{row.journal_date}</td>
                  <td className="whitespace-nowrap px-3 py-2">{row.journal_number ?? "—"}</td>
                  <td className="px-3 py-2">{row.reference ?? "—"}</td>
                  <td className="px-3 py-2">{row.description ?? "—"}</td>
                  <td className="px-3 py-2 text-right">
                    <div>{formatMoney(row.debit, { decimals: MONEY_DISPLAY_DECIMALS })}</div>
                    {row.currency_id !== null && row.foreign_debit !== null ? (
                      <div className="text-xs text-muted-foreground">
                        {formatDecimal(row.foreign_debit)} @ {row.exchange_rate ?? "—"}
                      </div>
                    ) : null}
                  </td>
                  <td className="px-3 py-2 text-right">
                    <div>{formatMoney(row.credit, { decimals: MONEY_DISPLAY_DECIMALS })}</div>
                    {row.currency_id !== null && row.foreign_credit !== null ? (
                      <div className="text-xs text-muted-foreground">
                        {formatDecimal(row.foreign_credit)} @ {row.exchange_rate ?? "—"}
                      </div>
                    ) : null}
                  </td>
                  <td className="px-3 py-2 text-right">
                    {formatMoney(row.running_balance, { decimals: MONEY_DISPLAY_DECIMALS })}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="rounded-lg border border-border bg-surface p-4">
          <div className="text-sm text-muted-foreground">Closing balance</div>
          <div className="text-lg font-semibold">
            {formatMoney(report.closing_balance, { decimals: MONEY_DISPLAY_DECIMALS })}
          </div>
        </div>
        <div className="rounded-lg border border-border bg-surface p-4">
          <div className="text-sm text-muted-foreground">
            Closing balance ({account.normal_balance.toLowerCase()} side)
          </div>
          <div className="text-lg font-semibold">
            {formatMoney(report.closing_balance_signed, { decimals: MONEY_DISPLAY_DECIMALS })}
          </div>
        </div>
      </div>
    </div>
  );
}
