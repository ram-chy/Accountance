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
import { deleteCustomerReceipt, postCustomerReceipt } from "@/lib/customer-receipts/api";
import type { CustomerReceipt, CustomerReceiptListQuery, CustomerReceiptStatus } from "@/lib/customer-receipts/types";
import { useCustomerReceipts, CUSTOMER_RECEIPT_LIST_PER_PAGE } from "@/lib/customer-receipts/use-customer-receipts";
import { useCustomerOptions } from "@/lib/customers/use-customer-options";
import { formatDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export function receiptStatusLabel(status: CustomerReceiptStatus): string {
  return status === "POSTED" ? "Posted" : "Draft";
}

export function receiptStatusVariant(status: CustomerReceiptStatus): "neutral" | "success" {
  return status === "POSTED" ? "success" : "neutral";
}

type Filters = {
  status: CustomerReceiptStatus | "";
  customer_id: number | null;
  from: string;
  to: string;
  reference: string;
};

const DEFAULT_FILTERS: Filters = { status: "", customer_id: null, from: "", to: "", reference: "" };

function toQuery(filters: Filters, page: number): CustomerReceiptListQuery {
  return {
    status: filters.status || undefined,
    customer_id: filters.customer_id ?? undefined,
    from: filters.from || undefined,
    to: filters.to || undefined,
    reference: filters.reference.trim() || undefined,
    page,
    per_page: CUSTOMER_RECEIPT_LIST_PER_PAGE,
  };
}

export function CustomerReceiptsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("customer.receipts.view");
  const canCreate = can("customer.receipts.create");
  const canUpdate = can("customer.receipts.update");
  const canDelete = can("customer.receipts.delete");
  const canPost = can("customer.receipts.post");

  const [draft, setDraft] = useState<Filters>(DEFAULT_FILTERS);
  const [applied, setApplied] = useState<Filters>(DEFAULT_FILTERS);
  const [page, setPage] = useState(1);
  const [reloadToken, setReloadToken] = useState(0);

  const [confirm, setConfirm] = useState<{ receipt: CustomerReceipt; action: "post" | "delete" } | null>(null);
  const [working, setWorking] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const customersState = useCustomerOptions(companyId, canView);
  const customers = customersState.status === "ready" ? customersState.items : [];

  const state = useCustomerReceipts({ companyId, enabled: canView, query: toQuery(applied, page), reloadToken });

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
        await postCustomerReceipt(confirm.receipt.id);
      } else {
        await deleteCustomerReceipt(confirm.receipt.id);
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
      return <EmptyState title="No company selected" description="Choose a company to view its customer receipts." />;
    }
    if (!canView) {
      return <EmptyState title="Receipts unavailable" description="Your role does not include access to customer receipts." />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading customer receipts…" />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Receipts unavailable" description={state.message} />;
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
        <EmptyState title="No receipts on this page" description="Go back to the previous page." />
      ) : (
        <EmptyState title="No customer receipts yet" description="Record a receipt to settle outstanding invoices." />
      );
    }

    return (
      <div className="flex flex-col gap-4">
        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead className="bg-surface-muted">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Receipt</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Customer</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Reference</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Amount</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Status</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {state.items.map((receipt) => (
                <tr key={receipt.id}>
                  <td className="px-3 py-2 font-mono text-xs">
                    <Link className="text-foreground hover:underline" href={`/customer-receipts/${receipt.id}`}>
                      {receipt.receipt_number}
                    </Link>
                  </td>
                  <td className="px-3 py-2">{receipt.customer?.name ?? receipt.customer_id}</td>
                  <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(receipt.receipt_date)}</td>
                  <td className="px-3 py-2 text-muted-foreground">{receipt.reference ?? "—"}</td>
                  <td className="px-3 py-2 text-right">{money(receipt.amount)}</td>
                  <td className="px-3 py-2">
                    <Badge variant={receiptStatusVariant(receipt.status)}>{receiptStatusLabel(receipt.status)}</Badge>
                  </td>
                  <td className="px-3 py-2">
                    <div className="flex justify-end gap-2">
                      {canUpdate && receipt.status === "DRAFT" ? (
                        <Link href={`/customer-receipts/${receipt.id}/edit`}>
                          <Button size="sm" variant="secondary">
                            Edit
                          </Button>
                        </Link>
                      ) : null}
                      {canPost && receipt.status === "DRAFT" ? (
                        <Button
                          size="sm"
                          onClick={() => {
                            setActionError(null);
                            setConfirm({ receipt, action: "post" });
                          }}
                        >
                          Post
                        </Button>
                      ) : null}
                      {canDelete && receipt.status === "DRAFT" ? (
                        <Button
                          size="sm"
                          variant="danger"
                          onClick={() => {
                            setActionError(null);
                            setConfirm({ receipt, action: "delete" });
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
          <h1 className="text-2xl font-semibold text-foreground">Customer Receipts</h1>
          <p className="mt-1 text-sm text-muted-foreground">Record payments received and allocate them to invoices.</p>
        </div>
        {canCreate ? (
          <Link href="/customer-receipts/new">
            <Button type="button">New receipt</Button>
          </Link>
        ) : null}
      </div>

      <form
        onSubmit={apply}
        className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      >
        <FormField label="Customer" htmlFor="receipt-filter-customer">
          <select
            id="receipt-filter-customer"
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
                {customer.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Status" htmlFor="receipt-filter-status">
          <select
            id="receipt-filter-status"
            className={selectClasses}
            value={draft.status}
            onChange={(event) =>
              setDraft((current) => ({ ...current, status: event.target.value as CustomerReceiptStatus | "" }))
            }
          >
            <option value="">All</option>
            <option value="DRAFT">Draft</option>
            <option value="POSTED">Posted</option>
          </select>
        </FormField>
        <FormField label="From" htmlFor="receipt-filter-from">
          <Input id="receipt-filter-from" type="date" value={draft.from} onChange={(event) => setDraft((c) => ({ ...c, from: event.target.value }))} />
        </FormField>
        <FormField label="To" htmlFor="receipt-filter-to">
          <Input id="receipt-filter-to" type="date" value={draft.to} onChange={(event) => setDraft((c) => ({ ...c, to: event.target.value }))} />
        </FormField>
        <FormField label="Reference" htmlFor="receipt-filter-reference">
          <Input id="receipt-filter-reference" value={draft.reference} onChange={(event) => setDraft((c) => ({ ...c, reference: event.target.value }))} />
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
        title={confirm?.action === "delete" ? "Delete draft receipt" : "Post receipt"}
        description={
          confirm
            ? confirm.action === "delete"
              ? `Delete draft ${confirm.receipt.receipt_number}? This cannot be undone.`
              : `Post ${confirm.receipt.receipt_number}? Posting writes the accounting entries and updates the settled invoices.`
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
