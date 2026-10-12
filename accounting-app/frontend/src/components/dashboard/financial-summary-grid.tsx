"use client";

import type { CashBankTotal, CurrencyInfo, Loadable } from "@/lib/dashboard/types";

import {
  FinancialSummaryCard,
  type SummaryTone,
} from "./financial-summary-card";

type NetProfitValue = { amount: string; profitable: boolean };

export type FinancialSummaryGridProps = {
  currency: CurrencyInfo | null;
  /** Context shown under the period metrics, e.g. "1 Apr 2026 – 31 Mar 2027". */
  periodHint: string;
  /** Context shown under the point-in-time metrics, e.g. "as of 31 Mar 2027". */
  asOfHint: string;
  income: Loadable<string>;
  expenses: Loadable<string>;
  netProfit: Loadable<NetProfitValue>;
  receivables: Loadable<string>;
  payables: Loadable<string>;
  cashBank: Loadable<CashBankTotal>;
  showReports: boolean;
  showCashBank: boolean;
};

function mapLoadable<T, U>(state: Loadable<T>, map: (data: T) => U): Loadable<U> {
  return state.status === "ready"
    ? { status: "ready", data: map(state.data) }
    : state;
}

/**
 * Responsive grid of the account overview metrics.
 *
 * Only the metrics the current role can read are shown; the rest are omitted
 * rather than rendered as empty placeholders, since the backend would reject
 * them anyway.
 */
export function FinancialSummaryGrid({
  currency,
  periodHint,
  asOfHint,
  income,
  expenses,
  netProfit,
  receivables,
  payables,
  cashBank,
  showReports,
  showCashBank,
}: FinancialSummaryGridProps) {
  const netProfitLabel: Loadable<string> = mapLoadable(
    netProfit,
    (value) => value.amount,
  );

  const netTone: SummaryTone =
    netProfit.status === "ready" && !netProfit.data.profitable
      ? "negative"
      : "neutral";

  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      {showCashBank ? (
        <FinancialSummaryCard
          label="Cash and bank balance"
          hint={asOfHint}
          currency={currency}
          state={mapLoadable(cashBank, (value) => value.total)}
        />
      ) : null}

      {showReports ? (
        <FinancialSummaryCard
          label="Accounts receivable"
          hint={asOfHint}
          currency={currency}
          state={receivables}
        />
      ) : null}

      {showReports ? (
        <FinancialSummaryCard
          label="Accounts payable"
          hint={asOfHint}
          currency={currency}
          state={payables}
        />
      ) : null}

      {showReports ? (
        <FinancialSummaryCard
          label="Income (revenue)"
          hint={periodHint}
          currency={currency}
          state={income}
        />
      ) : null}

      {showReports ? (
        <FinancialSummaryCard
          label="Expenses"
          hint={periodHint}
          currency={currency}
          state={expenses}
        />
      ) : null}

      {showReports ? (
        <FinancialSummaryCard
          label="Net profit / loss"
          hint={periodHint}
          currency={currency}
          state={netProfitLabel}
          tone={netTone}
          badge={
            netProfit.status === "ready"
              ? {
                  label: netProfit.data.profitable ? "Profit" : "Loss",
                  variant: netProfit.data.profitable ? "success" : "danger",
                }
              : undefined
          }
        />
      ) : null}
    </div>
  );
}
