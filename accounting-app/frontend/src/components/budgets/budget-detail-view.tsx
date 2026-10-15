"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type ReactNode } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { LoadingState } from "@/components/ui/loading-state";
import { ApiError } from "@/lib/api/errors";
import { deleteBudgetLine } from "@/lib/budgets/api";
import type { Budget, BudgetLine } from "@/lib/budgets/types";
import { useBudget } from "@/lib/budgets/use-budget";
import { dimensionLabelKey, useDimensionLabelMap } from "@/lib/dimensions/use-dimension-label-map";
import { formatDateOnly, formatTimestamp } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";

import { ApproveBudgetDialog, DeleteBudgetDialog, ReviseBudgetDialog } from "./budget-action-dialogs";
import { BudgetFormDialog } from "./budget-form-dialog";
import { BudgetLineFormDialog } from "./budget-line-form-dialog";
import { BudgetStatusBadge } from "./budget-status-badge";

/**
 * Budget detail.
 *
 * Shows the budget's identity and lifecycle, its notes, and its lines — the
 * account/period/amount plan — with the per-line analytical labels resolved to
 * names. Lines are only editable while the budget is a draft; an approved budget
 * is read-only and is changed by raising a new version. The planned amounts are
 * rendered exactly as the backend sent them (canonical decimal strings); nothing
 * is totalled in the browser.
 */

function money(value: string | null): string {
  return formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });
}

function SummaryItem({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="text-sm text-foreground">{children}</dd>
    </div>
  );
}

export function BudgetDetailView({ budgetId }: { budgetId: number }) {
  const router = useRouter();
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.budgets.view");
  const canCreate = can("accounting.budgets.create");
  const canUpdate = can("accounting.budgets.update");
  const canDelete = can("accounting.budgets.delete");
  const canApprove = can("accounting.budgets.approve");

  const [reloadToken, setReloadToken] = useState(0);
  const [editOpen, setEditOpen] = useState(false);
  const [approveOpen, setApproveOpen] = useState(false);
  const [reviseOpen, setReviseOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [lineFormOpen, setLineFormOpen] = useState(false);
  const [editingLine, setEditingLine] = useState<BudgetLine | null>(null);
  const [deletingLine, setDeletingLine] = useState<BudgetLine | null>(null);
  const [linePendingId, setLinePendingId] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const detail = useBudget({ id: budgetId, companyId, enabled: canView, reloadToken });
  const labels = useDimensionLabelMap(companyId, canView && detail.status === "ready");

  const reload = () => setReloadToken((token) => token + 1);

  const backLink = (
    <Link href="/budgets" className="text-sm font-medium text-primary hover:underline">
      ← Budgets
    </Link>
  );

  const openCreateLine = () => {
    setEditingLine(null);
    setLineFormOpen(true);
  };

  const openEditLine = (line: BudgetLine) => {
    setEditingLine(line);
    setLineFormOpen(true);
  };

  const onLineSaved = () => {
    setLineFormOpen(false);
    setEditingLine(null);
    reload();
  };

  const confirmDeleteLine = async () => {
    if (!deletingLine || linePendingId !== null) return;
    setLinePendingId(deletingLine.id);
    setActionError(null);
    try {
      await deleteBudgetLine(budgetId, deletingLine.id);
      setDeletingLine(null);
      reload();
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : "We could not delete this line.");
    } finally {
      setLinePendingId(null);
    }
  };

  if (!activeCompany) {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState title="No company selected" description="Choose a company to view this budget." />
      </div>
    );
  }

  if (!canView) {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState title="Budgets unavailable" description="Your role does not include access to budgets." />
      </div>
    );
  }

  if (detail.status === "loading") {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <LoadingState label="Loading budget…" />
      </div>
    );
  }

  if (detail.status === "notfound") {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState
          title="Budget not found"
          description="This budget does not exist or is not in this company."
        />
      </div>
    );
  }

  if (detail.status === "unavailable") {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState title="Budget unavailable" description={detail.message} />
      </div>
    );
  }

  if (detail.status === "error" || !detail.budget) {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <ErrorState
          title="Could not load budget"
          message={detail.message ?? "The budget is unavailable."}
          requestId={detail.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      </div>
    );
  }

  const budget: Budget = detail.budget;
  const isDraft = budget.status === "DRAFT";
  const lines = budget.lines ?? [];

  const linesBody = (() => {
    if (lines.length === 0) {
      return (
        <EmptyState
          title="No lines yet"
          description={
            isDraft
              ? "Add a line to plan an account's amount for a period."
              : "This budget has no lines."
          }
          action={
            isDraft && canUpdate ? (
              <Button type="button" onClick={openCreateLine}>
                Add line
              </Button>
            ) : undefined
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <table className="w-full min-w-[56rem] border-collapse text-sm">
          <caption className="sr-only">Budget lines</caption>
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
              <th scope="col" className="px-3 py-2 font-medium">Account</th>
              <th scope="col" className="px-3 py-2 font-medium">Period</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Amount</th>
              <th scope="col" className="px-3 py-2 font-medium">Dimensions</th>
              <th scope="col" className="px-3 py-2 font-medium">Description</th>
              {isDraft && canUpdate ? (
                <th scope="col" className="px-3 py-2 text-right font-medium">Actions</th>
              ) : null}
            </tr>
          </thead>
          <tbody>
            {lines.map((line) => (
              <tr key={line.id} className="border-b border-border/70 align-top">
                <td className="px-3 py-3">
                  {line.account ? (
                    <>
                      <div className="font-medium text-foreground">{line.account.code}</div>
                      <div className="text-muted-foreground">{line.account.name}</div>
                    </>
                  ) : (
                    <span className="text-muted-foreground">#{line.account_id}</span>
                  )}
                </td>
                <td className="px-3 py-3 text-muted-foreground">
                  {line.accounting_period ? line.accounting_period.name : `#${line.accounting_period_id}`}
                </td>
                <td className="px-3 py-3 text-right tabular-nums">{money(line.amount)}</td>
                <td className="px-3 py-3">
                  {line.dimensions && line.dimensions.length > 0 ? (
                    <div className="flex flex-col gap-1">
                      {line.dimensions.map((assignment) => {
                        const label = labels.labels.get(
                          dimensionLabelKey(assignment.dimension_id, assignment.value_id),
                        );
                        return (
                          <span key={dimensionLabelKey(assignment.dimension_id, assignment.value_id)}>
                            {label ? (
                              <>
                                <span className="text-muted-foreground">{label.dimensionName}: </span>
                                <span className="font-medium text-foreground">{label.valueCode}</span>
                                <span className="text-muted-foreground"> — {label.valueName}</span>
                              </>
                            ) : (
                              <span className="text-muted-foreground">
                                #{assignment.dimension_id} → #{assignment.value_id}
                              </span>
                            )}
                          </span>
                        );
                      })}
                    </div>
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </td>
                <td className="px-3 py-3 text-muted-foreground">{line.description ?? "—"}</td>
                {isDraft && canUpdate ? (
                  <td className="px-3 py-3">
                    <div className="flex flex-wrap justify-end gap-3">
                      <button
                        type="button"
                        className="text-sm font-medium text-primary hover:underline"
                        onClick={() => openEditLine(line)}
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        className="text-sm font-medium text-danger hover:underline disabled:text-muted-foreground"
                        disabled={linePendingId === line.id}
                        onClick={() => {
                          setDeletingLine(line);
                          setActionError(null);
                        }}
                      >
                        Delete
                      </button>
                    </div>
                  </td>
                ) : null}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      {backLink}

      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="flex flex-col gap-2">
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-semibold text-foreground">{budget.code}</h1>
            <Badge variant="neutral">Version {budget.version_number}</Badge>
            <BudgetStatusBadge status={budget.status} />
          </div>
          <p className="text-sm text-muted-foreground">{budget.name}</p>
        </div>

        <div className="flex flex-wrap gap-2">
          <Link href={`/reports/budget-variance?budget_id=${budget.id}`}>
            <Button variant="secondary">View variance</Button>
          </Link>
          {canUpdate && isDraft ? (
            <Button variant="secondary" onClick={() => setEditOpen(true)}>
              Edit
            </Button>
          ) : null}
          {canApprove && isDraft ? <Button onClick={() => setApproveOpen(true)}>Approve</Button> : null}
          {canCreate && !isDraft ? <Button onClick={() => setReviseOpen(true)}>Revise</Button> : null}
          {canDelete && isDraft ? (
            <Button variant="danger" onClick={() => setDeleteOpen(true)}>
              Delete
            </Button>
          ) : null}
        </div>
      </div>

      {actionError ? (
        <p
          role="alert"
          className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
        >
          {actionError}
        </p>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Budget</CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <SummaryItem label="Financial year">{budget.financial_year?.name ?? "—"}</SummaryItem>
            <SummaryItem label="Year period">
              {budget.financial_year
                ? `${formatDateOnly(budget.financial_year.start_date)} → ${formatDateOnly(budget.financial_year.end_date)}`
                : "—"}
            </SummaryItem>
            <SummaryItem label="Version">{budget.version_number}</SummaryItem>
            <SummaryItem label="Lines">{budget.lines_count ?? lines.length}</SummaryItem>
            <SummaryItem label="Status">{budget.status === "DRAFT" ? "Draft" : "Approved"}</SummaryItem>
            <SummaryItem label="Approved">
              {budget.approved_at ? formatTimestamp(budget.approved_at) : "—"}
            </SummaryItem>
            <SummaryItem label="Created">{budget.created_at ? formatTimestamp(budget.created_at) : "—"}</SummaryItem>
            <SummaryItem label="Last updated">
              {budget.updated_at ? formatTimestamp(budget.updated_at) : "—"}
            </SummaryItem>
            <div className="col-span-2 lg:col-span-4">
              <SummaryItem label="Notes">{budget.notes ?? "—"}</SummaryItem>
            </div>
          </dl>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <CardTitle>Lines</CardTitle>
            {canUpdate && isDraft ? <Button onClick={openCreateLine}>Add line</Button> : null}
          </div>
        </CardHeader>
        <CardContent>{linesBody}</CardContent>
      </Card>

      <BudgetFormDialog
        open={editOpen}
        companyId={companyId}
        budget={budget}
        onClose={() => setEditOpen(false)}
        onSaved={() => {
          setEditOpen(false);
          reload();
        }}
      />

      <BudgetLineFormDialog
        open={lineFormOpen}
        companyId={companyId}
        budget={budget}
        line={editingLine}
        onClose={() => {
          setLineFormOpen(false);
          setEditingLine(null);
        }}
        onSaved={onLineSaved}
      />

      <ApproveBudgetDialog
        open={approveOpen}
        budget={budget}
        onClose={() => setApproveOpen(false)}
        onDone={() => {
          setApproveOpen(false);
          reload();
        }}
      />

      <ReviseBudgetDialog
        open={reviseOpen}
        budget={budget}
        onClose={() => setReviseOpen(false)}
        onDone={() => {
          setReviseOpen(false);
          router.refresh();
        }}
      />

      <DeleteBudgetDialog
        open={deleteOpen}
        budget={budget}
        onClose={() => setDeleteOpen(false)}
        onDeleted={() => {
          setDeleteOpen(false);
          router.push("/budgets");
          router.refresh();
        }}
      />

      <Dialog
        open={Boolean(deletingLine)}
        title="Delete line"
        onClose={() => setDeletingLine(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeletingLine(null)} disabled={linePendingId !== null}>
              Cancel
            </Button>
            <Button variant="danger" onClick={confirmDeleteLine} loading={linePendingId === deletingLine?.id}>
              Delete line
            </Button>
          </>
        }
      >
        <p className="text-sm text-muted-foreground">
          Delete this line
          {deletingLine?.account ? (
            <>
              {" "}
              for <span className="font-medium text-foreground">{deletingLine.account.code}</span>
            </>
          ) : null}
          ? This cannot be undone. Only a draft budget&apos;s lines can be changed.
        </p>
      </Dialog>
    </div>
  );
}
