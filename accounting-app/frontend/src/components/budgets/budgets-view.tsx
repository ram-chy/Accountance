"use client";

import Link from "next/link";
import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import {
  BUDGET_STATUS_LABELS,
  BUDGET_STATUS_VALUES,
  type Budget,
  type BudgetStatusValue,
} from "@/lib/budgets/types";
import { BUDGETS_PER_PAGE, useBudgets } from "@/lib/budgets/use-budgets";
import { useFinancialYearOptions } from "@/lib/financial-years/use-financial-years";

import { ApproveBudgetDialog, DeleteBudgetDialog, ReviseBudgetDialog } from "./budget-action-dialogs";
import { BudgetFormDialog } from "./budget-form-dialog";
import { BudgetStatusBadge } from "./budget-status-badge";

/**
 * Budget management.
 *
 * A budget is a plan for a financial year: a set of lines saying "this account,
 * this period, this planned amount". The list supports search plus a year and
 * status filter; create and edit use a dialog form; approve, revise and delete
 * are confirmed. Server rules still govern what is allowed (approval needs at
 * least one line, an approved budget is immutable, only a draft may be deleted)
 * and any rejection is shown verbatim.
 */

type StatusFilter = "all" | BudgetStatusValue;

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export function BudgetsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.budgets.view");
  const canCreate = can("accounting.budgets.create");
  const canUpdate = can("accounting.budgets.update");
  const canDelete = can("accounting.budgets.delete");
  const canApprove = can("accounting.budgets.approve");

  const [search, setSearch] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");
  const [yearFilter, setYearFilter] = useState<"all" | string>("all");
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("all");
  const [page, setPage] = useState(1);
  const [reloadToken, setReloadToken] = useState(0);

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Budget | null>(null);
  const [approving, setApproving] = useState<Budget | null>(null);
  const [revising, setRevising] = useState<Budget | null>(null);
  const [deleting, setDeleting] = useState<Budget | null>(null);

  const yearsState = useFinancialYearOptions(companyId, canView && companyId !== null);
  const years = yearsState.status === "ready" ? yearsState.items : [];

  const financialYearId = yearFilter === "all" ? undefined : Number.parseInt(yearFilter, 10);

  const state = useBudgets({
    companyId,
    enabled: canView,
    filters: {
      financial_year_id: financialYearId,
      status: statusFilter === "all" ? undefined : statusFilter,
      search: appliedSearch.trim() || undefined,
    },
    page,
    perPage: BUDGETS_PER_PAGE,
    reloadToken,
  });

  const reload = () => setReloadToken((token) => token + 1);
  const isFiltered = appliedSearch.trim() !== "" || yearFilter !== "all" || statusFilter !== "all";

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const openEdit = (budget: Budget) => {
    setEditing(budget);
    setFormOpen(true);
  };

  const onSaved = () => {
    setFormOpen(false);
    setEditing(null);
    reload();
  };

  const header = (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Budgets</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Plans for a financial year, measured against posted actuals by the variance report.
        </p>
      </div>
      {canCreate ? (
        <Button type="button" onClick={openCreate}>
          New budget
        </Button>
      ) : null}
    </div>
  );

  const filterBar = (
    <form
      className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      onSubmit={(event) => {
        event.preventDefault();
        setAppliedSearch(search);
        setPage(1);
      }}
    >
      <FormField label="Search" htmlFor="budget-search">
        <Input
          id="budget-search"
          value={search}
          placeholder="Code or name"
          onChange={(event) => setSearch(event.target.value)}
        />
      </FormField>

      <FormField label="Financial year" htmlFor="budget-year-filter">
        <select
          id="budget-year-filter"
          className={selectClasses}
          value={yearFilter}
          onChange={(event) => {
            setYearFilter(event.target.value);
            setPage(1);
          }}
          disabled={yearsState.status !== "ready"}
        >
          <option value="all">All years</option>
          {years.map((year) => (
            <option key={year.id} value={year.id}>
              {year.name}
            </option>
          ))}
        </select>
      </FormField>

      <FormField label="Status" htmlFor="budget-status-filter">
        <select
          id="budget-status-filter"
          className={selectClasses}
          value={statusFilter}
          onChange={(event) => {
            setStatusFilter(event.target.value as StatusFilter);
            setPage(1);
          }}
        >
          <option value="all">All</option>
          {BUDGET_STATUS_VALUES.map((status) => (
            <option key={status} value={status}>
              {BUDGET_STATUS_LABELS[status]}
            </option>
          ))}
        </select>
      </FormField>

      <div className="flex gap-2">
        <Button type="submit">Apply</Button>
        {isFiltered ? (
          <Button
            type="button"
            variant="ghost"
            onClick={() => {
              setSearch("");
              setAppliedSearch("");
              setYearFilter("all");
              setStatusFilter("all");
              setPage(1);
            }}
          >
            Clear
          </Button>
        ) : null}
      </div>
    </form>
  );

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its budgets." />;
    }
    if (!canView) {
      return (
        <EmptyState title="Budgets unavailable" description="Your role does not include access to budgets." />
      );
    }
    if (state.status === "loading") {
      return <p className="text-sm text-muted-foreground">Loading budgets…</p>;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Budgets unavailable" description={state.message} />;
    }
    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load budgets"
          message={state.message}
          requestId={state.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      );
    }
    if (state.items.length === 0) {
      return (
        <EmptyState
          title={isFiltered ? "No matching budgets" : "No budgets yet"}
          description={
            isFiltered ? "No budgets match the current filters." : "Create a budget to start planning."
          }
          action={
            canCreate && !isFiltered ? (
              <Button type="button" onClick={openCreate}>
                New budget
              </Button>
            ) : undefined
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <table className="w-full min-w-[56rem] border-collapse text-sm">
          <caption className="sr-only">Budgets</caption>
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
              <th scope="col" className="px-3 py-2 font-medium">Budget</th>
              <th scope="col" className="px-3 py-2 font-medium">Financial year</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Version</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Lines</th>
              <th scope="col" className="px-3 py-2 font-medium">Status</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Actions</th>
            </tr>
          </thead>
          <tbody>
            {state.items.map((budget) => {
              const isDraft = budget.status === "DRAFT";
              return (
                <tr key={budget.id} className="border-b border-border/70 align-top">
                  <td className="px-3 py-3">
                    <Link href={`/budgets/${budget.id}`} className="font-medium text-primary hover:underline">
                      {budget.code}
                    </Link>
                    <div className="text-muted-foreground">{budget.name}</div>
                  </td>
                  <td className="px-3 py-3 text-muted-foreground">{budget.financial_year?.name ?? "—"}</td>
                  <td className="px-3 py-3 text-right tabular-nums text-muted-foreground">
                    {budget.version_number}
                  </td>
                  <td className="px-3 py-3 text-right tabular-nums text-muted-foreground">
                    {budget.lines_count ?? "—"}
                  </td>
                  <td className="px-3 py-3">
                    <BudgetStatusBadge status={budget.status} />
                  </td>
                  <td className="px-3 py-3">
                    <div className="flex flex-wrap justify-end gap-3">
                      {canUpdate && isDraft ? (
                        <button
                          type="button"
                          className="text-sm font-medium text-primary hover:underline"
                          onClick={() => openEdit(budget)}
                        >
                          Edit
                        </button>
                      ) : null}
                      {canApprove && isDraft ? (
                        <button
                          type="button"
                          className="text-sm font-medium text-primary hover:underline"
                          onClick={() => setApproving(budget)}
                        >
                          Approve
                        </button>
                      ) : null}
                      {canCreate && !isDraft ? (
                        <button
                          type="button"
                          className="text-sm font-medium text-primary hover:underline"
                          onClick={() => setRevising(budget)}
                        >
                          Revise
                        </button>
                      ) : null}
                      <Link
                        href={`/reports/budget-variance?budget_id=${budget.id}`}
                        className="text-sm font-medium text-primary hover:underline"
                      >
                        Variance
                      </Link>
                      {canDelete && isDraft ? (
                        <button
                          type="button"
                          className="text-sm font-medium text-danger hover:underline"
                          onClick={() => setDeleting(budget)}
                        >
                          Delete
                        </button>
                      ) : null}
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      {header}
      {filterBar}

      <div className="flex flex-col gap-4">
        {body}

        {state.status === "ready" && (state.items.length > 0 || page > 1) ? (
          <div className="flex items-center justify-between gap-3">
            <Button
              variant="secondary"
              disabled={page <= 1}
              onClick={() => setPage((current) => Math.max(1, current - 1))}
            >
              Previous
            </Button>
            <span className="text-sm text-muted-foreground">Page {page}</span>
            <Button variant="secondary" disabled={!state.hasMore} onClick={() => setPage((current) => current + 1)}>
              Next
            </Button>
          </div>
        ) : null}
      </div>

      <BudgetFormDialog
        open={formOpen}
        companyId={companyId}
        budget={editing}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSaved={onSaved}
      />

      {approving ? (
        <ApproveBudgetDialog
          open
          budget={approving}
          onClose={() => setApproving(null)}
          onDone={() => {
            setApproving(null);
            reload();
          }}
        />
      ) : null}

      {revising ? (
        <ReviseBudgetDialog
          open
          budget={revising}
          onClose={() => setRevising(null)}
          onDone={() => {
            setRevising(null);
            reload();
          }}
        />
      ) : null}

      {deleting ? (
        <DeleteBudgetDialog
          open
          budget={deleting}
          onClose={() => setDeleting(null)}
          onDeleted={() => {
            setDeleting(null);
            reload();
          }}
        />
      ) : null}
    </div>
  );
}
