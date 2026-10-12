"use client";

import type { ReactNode } from "react";

import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils/cn";
import { formatDateOnly, formatTimestamp } from "@/lib/format/date";
import { MONEY_DISPLAY_DECIMALS, formatMoney } from "@/lib/format/money";
import type { BankReconciliation } from "@/lib/bank-reconciliations/types";
import { isZeroDecimalString } from "@/lib/bank-reconciliations/validators";

/**
 * Read-only reconciliation summary.
 *
 * Every figure is the backend's own value from `BankReconciliationResource` —
 * the statement balances the user entered against the ledger totals the
 * calculator derived, the reconciled balance and the difference. The frontend
 * never recomputes any of them; it only formats decimal strings and flags a
 * non-zero difference as a warning for the "Clear"/"Complete" review.
 */

function MoneyValue({ value, className }: { value: string | null; className?: string }) {
  return (
    <dd className={cn("font-mono text-sm text-foreground", className)}>
      {formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS })}
    </dd>
  );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-4 py-1.5">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      {children}
    </div>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="rounded-lg border border-border bg-surface p-4">
      <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        {title}
      </h2>
      <dl className="mt-2 flex flex-col divide-y divide-border">{children}</dl>
    </section>
  );
}

export function ReconciliationSummary({
  reconciliation,
}: {
  reconciliation: BankReconciliation;
}) {
  const differenceIsZero = isZeroDecimalString(reconciliation.difference);

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div className="rounded-lg border border-border bg-surface-muted px-4 py-3">
          <p className="text-xs font-medium text-muted-foreground">Reconciled balance</p>
          <p className="font-mono text-lg font-semibold text-foreground">
            {formatMoney(reconciliation.reconciled_balance, { decimals: MONEY_DISPLAY_DECIMALS })}
          </p>
        </div>

        <div className="rounded-lg border border-border bg-surface-muted px-4 py-3">
          <p className="text-xs font-medium text-muted-foreground">Statement closing</p>
          <p className="font-mono text-lg font-semibold text-foreground">
            {formatMoney(reconciliation.statement_closing_balance, {
              decimals: MONEY_DISPLAY_DECIMALS,
            })}
          </p>
        </div>

        <div
          className={cn(
            "rounded-lg border px-4 py-3",
            differenceIsZero
              ? "border-success-border bg-success-soft"
              : "border-warning-border bg-warning-soft",
          )}
        >
          <p className="text-xs font-medium text-muted-foreground">Difference</p>
          <p className="font-mono text-lg font-semibold text-foreground">
            {formatMoney(reconciliation.difference, { decimals: MONEY_DISPLAY_DECIMALS })}
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Statement">
          <Row label="Opening balance">
            <MoneyValue value={reconciliation.statement_opening_balance} />
          </Row>
          <Row label="Closing balance">
            <MoneyValue value={reconciliation.statement_closing_balance} />
          </Row>
          {reconciliation.previous_reconciled_closing !== null ? (
            <Row label="Previous reconciled closing">
              <MoneyValue value={reconciliation.previous_reconciled_closing} />
            </Row>
          ) : null}
          {reconciliation.opening_balance_difference !== null ? (
            <Row label="Opening balance difference">
              <dd className="flex items-center gap-2">
                <span className="font-mono text-sm text-foreground">
                  {formatMoney(reconciliation.opening_balance_difference, {
                    decimals: MONEY_DISPLAY_DECIMALS,
                  })}
                </span>
                {!isZeroDecimalString(reconciliation.opening_balance_difference) ? (
                  <Badge variant="warning">Check</Badge>
                ) : null}
              </dd>
            </Row>
          ) : null}
        </Section>

        <Section title="Ledger">
          <Row label="Opening balance">
            <MoneyValue value={reconciliation.ledger_opening} />
          </Row>
          <Row label="Closing balance">
            <MoneyValue value={reconciliation.ledger_closing} />
          </Row>
          <Row label="Period debits">
            <MoneyValue value={reconciliation.period_debits} />
          </Row>
          <Row label="Period credits">
            <MoneyValue value={reconciliation.period_credits} />
          </Row>
        </Section>

        <Section title="Cleared">
          <Row label="Cleared debits">
            <MoneyValue value={reconciliation.cleared_debits} />
          </Row>
          <Row label="Cleared credits">
            <MoneyValue value={reconciliation.cleared_credits} />
          </Row>
          <Row label="Uncleared debits">
            <MoneyValue value={reconciliation.uncleared_debits} />
          </Row>
          <Row label="Uncleared credits">
            <MoneyValue value={reconciliation.uncleared_credits} />
          </Row>
        </Section>

        <Section title="Reconciliation">
          <Row label="Reconciled balance">
            <MoneyValue value={reconciliation.reconciled_balance} className="font-semibold" />
          </Row>
          <Row label="Difference">
            <MoneyValue value={reconciliation.difference} className="font-semibold" />
          </Row>
          <Row label="Movements">
            <dd className="text-sm text-foreground">
              {reconciliation.cleared_movements} cleared of {reconciliation.total_movements}
            </dd>
          </Row>
        </Section>
      </div>

      <div className="rounded-lg border border-border bg-surface p-4">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Audit
        </h2>
        <dl className="mt-2 grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
          <div className="flex flex-col gap-0.5">
            <dt className="text-xs font-medium text-muted-foreground">Period</dt>
            <dd className="text-sm text-foreground">
              {formatDateOnly(reconciliation.from_date)} – {formatDateOnly(reconciliation.to_date)}
            </dd>
          </div>
          <div className="flex flex-col gap-0.5">
            <dt className="text-xs font-medium text-muted-foreground">Completed</dt>
            <dd className="text-sm text-foreground">
              {formatTimestamp(reconciliation.completed_at)}
            </dd>
          </div>
          {reconciliation.reopened_at !== null ? (
            <div className="flex flex-col gap-0.5">
              <dt className="text-xs font-medium text-muted-foreground">Reopened</dt>
              <dd className="text-sm text-foreground">
                {formatTimestamp(reconciliation.reopened_at)}
              </dd>
            </div>
          ) : null}
          <div className="flex flex-col gap-0.5 sm:col-span-2">
            <dt className="text-xs font-medium text-muted-foreground">Notes</dt>
            <dd className="text-sm text-foreground">{reconciliation.notes ?? "—"}</dd>
          </div>
        </dl>
      </div>
    </div>
  );
}
