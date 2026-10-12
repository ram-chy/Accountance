/**
 * Dashboard data contracts.
 *
 * These mirror the verified Laravel report responses exactly (Phase 6 reports,
 * Phase 4 ledger balance, Phase 4 journals). Money and quantities are always
 * decimal strings from the backend; nothing here is recomputed with floating
 * point. Every response the UI consumes is passed through `validators.ts`
 * before it reaches a component, because dashboard data is untrusted input.
 */

/** Base-currency disclosure block returned by the Phase 6 report family. */
export type CurrencyInfo = {
  code: string | null;
  name: string | null;
  symbol: string | null;
  decimals: number | null;
};

export type AccountAmountRow = {
  accountId: number;
  code: string;
  name: string;
  amount: string;
};

/** `GET /accounting/reports/profit-loss` */
export type ProfitLossReport = {
  period: { from: string | null; to: string | null };
  baseCurrency: CurrencyInfo;
  revenue: { total: string; accounts: AccountAmountRow[] };
  expenses: { total: string; accounts: AccountAmountRow[] };
  grossProfit: string;
  netProfit: string;
  isProfitable: boolean;
};

/** `GET /accounting/reports/receivables` and `.../payables` */
export type SettlementTotalsReport = {
  asOf: string;
  baseCurrency: CurrencyInfo;
  count: number;
  balanceDue: string;
};

/** `GET /accounting/accounts/{account}/balance` (no statement requested). */
export type AccountBalance = {
  accountId: number;
  code: string;
  name: string;
  balance: string;
};

/** `GET /cash-bank-accounts` */
export type CashBankAccount = {
  id: number;
  code: string;
  name: string;
  kind: string | null;
  isActive: boolean;
};

/** `GET /journals?status=POSTED` row, reduced to the fields the table shows. */
export type JournalActivity = {
  id: number;
  number: string;
  date: string | null;
  description: string | null;
  reference: string | null;
  status: string;
  sourceType: string | null;
  amount: string | null;
};

/** One month of the income-vs-expenses trend, derived from bounded P&L calls. */
export type TrendPoint = {
  key: string;
  label: string;
  revenue: string;
  expenses: string;
};

/** Total cash and bank balance, summed from per-account ledger balances. */
export type CashBankTotal = {
  total: string;
  accountCount: number;
};

/** A single asynchronous slice of the dashboard: never throws to a component. */
export type Loadable<T> =
  | { status: "loading" }
  | { status: "ready"; data: T }
  | { status: "error"; message: string; requestId?: string }
  | { status: "unavailable"; reason: string };

/** Everything the dashboard view renders, produced by `useDashboardData`. */
export type DashboardData = {
  currency: CurrencyInfo | null;
  /** True when the selected window exceeded the trend cap and was trimmed. */
  trendLimited: boolean;
  income: Loadable<string>;
  expenses: Loadable<string>;
  netProfit: Loadable<{ amount: string; profitable: boolean }>;
  receivables: Loadable<string>;
  payables: Loadable<string>;
  cashBank: Loadable<CashBankTotal>;
  expenseBreakdown: Loadable<AccountAmountRow[]>;
  trend: Loadable<TrendPoint[]>;
  activity: Loadable<JournalActivity[]>;
};
