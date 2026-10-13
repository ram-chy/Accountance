"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { ApiError } from "@/lib/api/errors";
import { formatDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import { deleteSupplierPayment, postSupplierPayment } from "@/lib/supplier-payments/api";
import type {
  SupplierPayment,
  SupplierPaymentListQuery,
  SupplierPaymentStatus,
} from "@/lib/supplier-payments/types";
import { SUPPLIER_PAYMENT_LIST_PER_PAGE, useSupplierPayments } from "@/lib/supplier-payments/use-supplier-payments";
import { useSupplierOptions } from "@/lib/suppliers/use-supplier-options";

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export function paymentStatusLabel(status: SupplierPaymentStatus): string {
  return status === "POSTED" ? "Posted" : "Draft";
}

export function paymentStatusVariant(status: SupplierPaymentStatus): "neutral" | "success" {
  return status === "POSTED" ? "success" : "neutral";
}

type Filters = {
  status: SupplierPaymentStatus | "";
  supplier_id: number | null;
  from: string;
  to: string;
  reference: string;
};

const DEFAULT_FILTERS: Filters = { status: "", supplier_id: null, from: "", to: "", reference: "" };

function toQuery(filters: Filters, page: number): SupplierPaymentListQuery {
  return {
    status: filters.status || undefined,
    supplier_id: filters.supplier_id ?? undefined,
    from: filters.from || undefined,
    to: filters.to || undefined,
    reference: filters.reference.trim() || undefined,
    page,
    per_page: SUPPLIER_PAYMENT_LIST_PER_PAGE,
  };
}

export function SupplierPaymentsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("supplier.payments.view");
  const canCreate = can("supplier.payments.create");
  const canUpdate = can("supplier.payments.update");
  const canDelete = can("supplier.payments.delete");
  const canPost = can("supplier.payments.post");

  const [draft, setDraft] = useState<Filters>(DEFAULT_FILTERS);
  const [applied, setApplied] = useState<Filters>(DEFAULT_FILTERS);
  const [page, setPage] = useState(1);
  const [reloadToken, setReloadToken] = useState(0);

  const [confirm, setConfirm] = useState<{ payment: SupplierPayment; action: "post" | "delete" } | null>(null);
  const [working, setWorking] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const suppliersState = useSupplierOptions(companyId, canView);
  const suppliers = suppliersState.status === "ready" ? suppliersState.items : [];

  const state = useSupplierPayments({ companyId, enabled: canView, query: toQuery(applied, page), reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
    setPage(1);
  };

  const clear = () => {
    setDraft(DEFAULT_FILTERS);
    setApplied(DEFAULT_FILTERS);
    setPage(1);
  };

  const runAction = async () => {
    if (!confirm || working) return;
    setWorking(true);
    setActionError(null);
    try {
      if (confirm.action === "post") {
        await postSupplierPayment(confirm.payment.id);
      } else {
        await deleteSupplierPayment(confirm.payment.id);
      }
      setConfirm(null);
      setReloadToken((token) => token + 1);
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : "We could not complete this action. Please try again.");
    } finally {
      setWorking(false);
    }
  };

  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its supplier payments." />;
    }
    if (!canView) {
      return <EmptyState title="Payments unavailable" description="Your role does not include access to supplier payments." />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading supplier payments…" />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Payments unavailable" description={state.message} />;
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
    if (state.items.length === 0) {
      return page > 1 ? (
        <EmptyState title="No payments on this page" description="Go back to the previous page." />
      ) : (
        <EmptyState title="No supplier payments yet" description="Record a payment to settle outstanding bills." />
      );
    }

    return (
      <div className="flex flex-col gap-4">
        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead className="bg-surface-muted">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Payment</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Supplier</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Reference</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Amount</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Status</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {state.items.map((payment) => (
                <tr key={payment.id}>
                  <td className="px-3 py-2 font-mono text-xs">
                    <Link className="text-foreground hover:underline" href={`/supplier-payments/${payment.id}`}>
                      {payment.payment_number}
                    </Link>
                  </td>
                  <td className="px-3 py-2">{payment.supplier?.name ?? payment.supplier_id}</td>
                  <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(payment.payment_date)}</td>
                  <td className="px-3 py-2 text-muted-foreground">{payment.reference ?? "—"}</td>
                  <td className="px-3 py-2 text-right">{money(payment.amount)}</td>
                  <td className="px-3 py-2">
                    <Badge variant={paymentStatusVariant(payment.status)}>{paymentStatusLabel(payment.status)}</Badge>
                  </td>
                  <td className="px-3 py-2">
                    <div className="flex justify-end gap-2">
                      {canUpdate && payment.status === "DRAFT" ? (
                        <Link href={`/supplier-payments/${payment.id}/edit`}>
                          <Button size="sm" variant="secondary">
                            Edit
                          </Button>
                        </Link>
                      ) : null}
                      {canPost && payment.status === "DRAFT" ? (
                        <Button
                          size="sm"
                          onClick={() => {
                            setActionError(null);
                            setConfirm({ payment, action: "post" });
                          }}
                        >
                          Post
                        </Button>
                      ) : null}
                      {canDelete && payment.status === "DRAFT" ? (
                        <Button
                          size="sm"
                          variant="danger"
                          onClick={() => {
                            setActionError(null);
                            setConfirm({ payment, action: "delete" });
                          }}
                        >
                          Delete
                        </Button>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="flex items-center justify-between">
          <Button type="button" variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((c) => Math.max(1, c - 1))}>
            Previous
          </Button>
          <span className="text-sm text-muted-foreground">Page {page}</span>
          <Button type="button" variant="secondary" size="sm" disabled={!state.hasMore} onClick={() => setPage((c) => c + 1)}>
            Next
          </Button>
        </div>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">Supplier Payments</h1>
          <p className="mt-1 text-sm text-muted-foreground">Record payments made and allocate them to bills.</p>
        </div>
        {canCreate ? (
          <Link href="/supplier-payments/new">
            <Button type="button">New payment</Button>
          </Link>
        ) : null}
      </div>

      <form
        onSubmit={apply}
        className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      >
        <FormField label="Supplier" htmlFor="payment-filter-supplier">
          <select
            id="payment-filter-supplier"
            className={selectClasses}
            value={draft.supplier_id === null ? "" : String(draft.supplier_id)}
            onChange={(event) =>
              setDraft((current) => ({
                ...current,
                supplier_id: event.target.value === "" ? null : Number(event.target.value),
              }))
            }
            disabled={suppliersState.status !== "ready"}
          >
            <option value="">All suppliers</option>
            {suppliers.map((supplier) => (
              <option key={supplier.id} value={supplier.id}>
                {supplier.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Status" htmlFor="payment-filter-status">
          <select
            id="payment-filter-status"
            className={selectClasses}
            value={draft.status}
            onChange={(event) =>
              setDraft((current) => ({ ...current, status: event.target.value as SupplierPaymentStatus | "" }))
            }
          >
            <option value="">All</option>
            <option value="DRAFT">Draft</option>
            <option value="POSTED">Posted</option>
          </select>
        </FormField>
        <FormField label="From" htmlFor="payment-filter-from">
          <Input id="payment-filter-from" type="date" value={draft.from} onChange={(event) => setDraft((c) => ({ ...c, from: event.target.value }))} />
        </FormField>
        <FormField label="To" htmlFor="payment-filter-to">
          <Input id="payment-filter-to" type="date" value={draft.to} onChange={(event) => setDraft((c) => ({ ...c, to: event.target.value }))} />
        </FormField>
        <FormField label="Reference" htmlFor="payment-filter-reference">
          <Input id="payment-filter-reference" value={draft.reference} onChange={(event) => setDraft((c) => ({ ...c, reference: event.target.value }))} />
        </FormField>
        <div className="flex gap-2 pb-1.5">
          <Button type="submit">Apply</Button>
          <Button type="button" variant="secondary" onClick={clear}>
            Clear
          </Button>
        </div>
      </form>

      {body}

      <Dialog
        open={confirm !== null}
        title={confirm?.action === "delete" ? "Delete draft payment" : "Post payment"}
        description={
          confirm
            ? confirm.action === "delete"
              ? `Delete draft ${confirm.payment.payment_number}? This cannot be undone.`
              : `Post ${confirm.payment.payment_number}? Posting writes the accounting entries and updates the settled bills.`
            : undefined
        }
        onClose={() => {
          if (!working) {
            setConfirm(null);
            setActionError(null);
          }
        }}
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setConfirm(null);
                setActionError(null);
              }}
              disabled={working}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant={confirm?.action === "delete" ? "danger" : "primary"}
              loading={working}
              onClick={runAction}
            >
              {confirm?.action === "delete" ? "Delete" : "Post"}
            </Button>
          </>
        }
      >
        {actionError ? (
          <p role="alert" className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground">
            {actionError}
          </p>
        ) : null}
      </Dialog>
    </div>
  );
}
