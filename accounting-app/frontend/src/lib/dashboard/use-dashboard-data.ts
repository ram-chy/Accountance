"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";
import { hasPermission } from "@/lib/permissions/permissions";

import {
  fetchAccountBalance,
  fetchCashBankAccounts,
  fetchPayables,
  fetchProfitLoss,
  fetchReceivables,
  fetchRecentJournals,
  type DateWindow,
} from "./api";
import { sumDecimalStrings } from "./format";
import { MAX_TREND_MONTHS, monthBuckets, type DashboardPeriod } from "./period";
import type {
  CashBankTotal,
  DashboardData,
  JournalActivity,
  Loadable,
  ProfitLossReport,
  TrendPoint,
} from "./types";
import {
  DashboardDataError,
  parseAccountBalance,
  parseCashBankAccounts,
  parseJournals,
  parsePayables,
  parseProfitLoss,
  parseReceivables,
} from "./validators";

/**
 * Orchestrates every dashboard request for the active company and reporting
 * period.
 *
 * Design notes:
 *
 * - Each metric is fetched and validated independently and surfaces as its own
 *   `Loadable`, so one failing endpoint shows one error card rather than
 *   blanking the page.
 * - A single `AbortController` is created per (company, period, permission) run
 *   and aborted when the inputs change. Every state write is guarded by
 *   `signal.aborted`, so a slow response for a previous selection can never
 *   overwrite a newer one.
 * - The loading and "no company" states are derived from the run key during
 *   render rather than written from the effect, so no state is set
 *   synchronously inside the effect body.
 * - Permissions are checked up front: a slice the user cannot read is reported
 *   `unavailable` and never requested, so no unauthorised call is attempted.
 * - The income-vs-expenses trend is derived from bounded per-month P&L calls
 *   (there is no aggregation endpoint — see the Phase 4 report); the cash/bank
 *   total is summed client-side with BigInt arithmetic from per-account ledger
 *   balances, again because no single endpoint exists.
 */

const REPORTS_PERMISSION = "accounting.reports.view";
const LEDGER_PERMISSION = "accounting.ledger.view";
const CASH_BANK_PERMISSION = "accounting.cash_bank.view";
const JOURNALS_PERMISSION = "journals.view";

export const ACTIVITY_LIMIT = 8;

const REPORT_REASON = "Your role does not include access to accounting reports.";
const CASH_REASON = "Your role does not include access to cash and bank balances.";
const JOURNALS_REASON = "Your role does not include access to journal activity.";
const NO_COMPANY_REASON = "Select a company to see its figures.";
const NO_RANGE_REASON = "Choose a reporting period to see the monthly trend.";

function unavailable(reason: string): Loadable<never> {
  return { status: "unavailable", reason };
}

function ready<T>(data: T): Loadable<T> {
  return { status: "ready", data };
}

function toErrorState(error: unknown): Loadable<never> {
  if (error instanceof ApiError) {
    if (error.status === 403) {
      return unavailable("Your role does not include access to this information.");
    }

    return { status: "error", message: error.message, requestId: error.requestId };
  }

  if (error instanceof DashboardDataError) {
    return { status: "error", message: error.message };
  }

  return { status: "error", message: "We could not load this information." };
}

type Flags = { reports: boolean; cashBank: boolean; journals: boolean };

function buildState(status: "loading" | "no-company", flags: Flags): DashboardData {
  const slice = (can: boolean, reason: string): Loadable<never> => {
    if (!can) {
      return unavailable(reason);
    }

    return status === "no-company" ? unavailable(NO_COMPANY_REASON) : { status: "loading" };
  };

  return {
    currency: null,
    trendLimited: false,
    income: slice(flags.reports, REPORT_REASON),
    expenses: slice(flags.reports, REPORT_REASON),
    netProfit: slice(flags.reports, REPORT_REASON),
    receivables: slice(flags.reports, REPORT_REASON),
    payables: slice(flags.reports, REPORT_REASON),
    cashBank: slice(flags.cashBank, CASH_REASON),
    expenseBreakdown: slice(flags.reports, REPORT_REASON),
    trend: slice(flags.reports, NO_RANGE_REASON),
    activity: slice(flags.journals, JOURNALS_REASON),
  };
}

function plainLoadable<T>(
  result: PromiseSettledResult<unknown>,
  enabled: boolean,
  reason: string,
): Loadable<T> {
  if (!enabled) {
    return unavailable(reason);
  }

  if (result.status === "rejected") {
    return toErrorState(result.reason);
  }

  if (result.status === "fulfilled" && result.value != null) {
    return ready(result.value as T);
  }

  return { status: "error", message: "No data was returned." };
}

function runKey(
  companyId: number,
  from: string | null,
  to: string | null,
  flags: Flags,
): string {
  return [
    companyId,
    from ?? "",
    to ?? "",
    flags.reports ? 1 : 0,
    flags.cashBank ? 1 : 0,
    flags.journals ? 1 : 0,
  ].join("|");
}

export function useDashboardData(
  period: DashboardPeriod,
  permissions: ReadonlySet<string>,
  companyId: number | null,
): DashboardData {
  const canReports = hasPermission(permissions, REPORTS_PERMISSION);
  const canLedger = hasPermission(permissions, LEDGER_PERMISSION);
  const canCashBank = hasPermission(permissions, CASH_BANK_PERMISSION);
  const canJournals = hasPermission(permissions, JOURNALS_PERMISSION);
  const flags: Flags = {
    reports: canReports,
    cashBank: canCashBank && canLedger,
    journals: canJournals,
  };

  const from = period.from;
  const to = period.to;
  const key = companyId === null ? null : runKey(companyId, from, to, flags);

  const [stored, setStored] = useState<{
    key: string;
    data: DashboardData;
  } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    const window: DateWindow = { from, to };
    const asOf = to;
    const buckets = monthBuckets(from, to);
    const plotted = buckets.slice(Math.max(0, buckets.length - MAX_TREND_MONTHS));
    const trendLimited = buckets.length > MAX_TREND_MONTHS;
    const trendEnabled = canReports && plotted.length > 0;

    const pnlTask = canReports
      ? fetchProfitLoss(window, signal).then(parseProfitLoss)
      : Promise.resolve(null);

    const trendTask = trendEnabled
      ? Promise.all(
          plotted.map(async (bucket): Promise<TrendPoint> => {
            const report = parseProfitLoss(
              await fetchProfitLoss({ from: bucket.from, to: bucket.to }, signal),
            );

            return {
              key: bucket.key,
              label: bucket.label,
              revenue: report.revenue.total,
              expenses: report.expenses.total,
            };
          }),
        )
      : Promise.resolve(null);

    const arTask = canReports
      ? fetchReceivables(asOf, signal).then((raw) => parseReceivables(raw).balanceDue)
      : Promise.resolve(null);

    const apTask = canReports
      ? fetchPayables(asOf, signal).then((raw) => parsePayables(raw).balanceDue)
      : Promise.resolve(null);

    const cashTask = flags.cashBank
      ? (async (): Promise<CashBankTotal> => {
          const accounts = parseCashBankAccounts(await fetchCashBankAccounts(signal));
          const balances = await Promise.all(
            accounts.map(async (account) =>
              parseAccountBalance(await fetchAccountBalance(account.id, signal)).balance,
            ),
          );

          return { total: sumDecimalStrings(balances), accountCount: accounts.length };
        })()
      : Promise.resolve(null);

    const activityTask = canJournals
      ? fetchRecentJournals(window, ACTIVITY_LIMIT, signal).then(parseJournals)
      : Promise.resolve(null);

    Promise.allSettled([
      pnlTask,
      trendTask,
      arTask,
      apTask,
      cashTask,
      activityTask,
    ]).then((results) => {
      if (signal.aborted) {
        return;
      }

      const [pnl, trend, ar, ap, cash, activity] = results;
      const report = pnl.status === "fulfilled" ? (pnl.value as ProfitLossReport | null) : null;

      const reportSlice = <T,>(extract: (value: ProfitLossReport) => T): Loadable<T> => {
        if (!canReports) {
          return unavailable(REPORT_REASON) as Loadable<T>;
        }

        if (pnl.status === "rejected") {
          return toErrorState(pnl.reason) as Loadable<T>;
        }

        if (report) {
          return ready(extract(report));
        }

        return { status: "error", message: "The profit and loss report was empty." };
      };

      const trendSlice: Loadable<TrendPoint[]> = !canReports
        ? unavailable(REPORT_REASON)
        : !trendEnabled
          ? unavailable(NO_RANGE_REASON)
          : plainLoadable<TrendPoint[]>(trend, true, REPORT_REASON);

      setStored({
        key,
        data: {
          currency: report ? report.baseCurrency : null,
          trendLimited,
          income: reportSlice((value) => value.revenue.total),
          expenses: reportSlice((value) => value.expenses.total),
          netProfit: reportSlice((value) => ({
            amount: value.netProfit,
            profitable: value.isProfitable,
          })),
          expenseBreakdown: reportSlice((value) => value.expenses.accounts),
          receivables: plainLoadable<string>(ar, canReports, REPORT_REASON),
          payables: plainLoadable<string>(ap, canReports, REPORT_REASON),
          cashBank: plainLoadable<CashBankTotal>(cash, flags.cashBank, CASH_REASON),
          trend: trendSlice,
          activity: plainLoadable<JournalActivity[]>(
            activity,
            canJournals,
            JOURNALS_REASON,
          ),
        },
      });
    });

    return () => controller.abort();
    // `flags`/`can*` are derived from `permissions`; the booleans below are the
    // real dependencies and keep the effect stable across unrelated re-renders.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canReports, canLedger, canCashBank, canJournals, companyId, from, to]);

  if (companyId === null) {
    return buildState("no-company", flags);
  }

  if (stored && stored.key === key) {
    return stored.data;
  }

  return buildState("loading", flags);
}
