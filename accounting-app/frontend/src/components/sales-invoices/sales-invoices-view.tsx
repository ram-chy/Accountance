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
import { useCustomerOptions } from "@/lib/customers/use-customer-options";
import { formatDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import { deleteSalesInvoice, postSalesInvoice } from "@/lib/sales-invoices/api";
import type { SalesInvoice, SalesInvoiceListQuery, SalesInvoiceStatus } from "@/lib/sales-invoices/types";
import { useSalesInvoices, SALES_INVOICE_LIST_PER_PAGE } from "@/lib/sales-invoices/use-sales-invoices";

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export function invoiceStatusVariant(
  status: SalesInvoiceStatus,
): "neutral" | "success" | "warning" | "info" {
  switch (status) {
    case "POSTED":
      return "info";
    case "PARTIALLY_PAID":
      return "warning";
    case "PAID":
      return "success";
    default:
      return "neutral";
  }
}

export function invoiceStatusLabel(status: SalesInvoiceStatus): string {
  switch (status) {
    case "DRAFT":
      return "Draft";
    case "POSTED":
      return "Posted";
    case "PARTIALLY_PAID":
      return "Partially paid";
    case "PAID":
      return "Paid";
  }
}

type Filters = {
  search: string;
  status: SalesInvoiceStatus | "";
  customer_id: number | null;
  from: string;
  to: string;
};

const DEFAULT_FILTERS: Filters = { search: "", status: "", customer_id: null, from: "", to: "" };

function toQuery(filters: Filters, page: number): SalesInvoiceListQuery {
  return {
    search: filters.search.trim() || undefined,
    status: filters.status || undefined,
    customer_id: filters.customer_id ?? undefined,
    from: filters.from || undefined,
    to: filters.to || undefined,
    page,
    per_page: SALES_INVOICE_LIST_PER_PAGE,
  };
}

export function SalesInvoicesView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("sales.invoices.view");
  const canCreate = can("sales.invoices.create");
  const canUpdate = can("sales.invoices.update");
  const canDelete = can("sales.invoices.delete");
  const canPost = can("sales.invoices.post");

  const [draft, setDraft] = useState<Filters>(DEFAULT_FILTERS);
  const [applied, setApplied] = useState<Filters>(DEFAULT_FILTERS);
  const [page, setPage] = useState(1);
  const [reloadToken, setReloadToken] = useState(0);

  const [confirm, setConfirm] = useState<{ invoice: SalesInvoice; action: "post" | "delete" } | null>(null);
  const [working, setWorking] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const customersState = useCustomerOptions(companyId, canView);
  const customers = customersState.status === "ready" ? customersState.items : [];

  const state = useSalesInvoices({ companyId, enabled: canView, query: toQuery(applied, page), reloadToken });

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
        await postSalesInvoice(confirm.invoice.id);
      } else {
        await deleteSalesInvoice(confirm.invoice.id);
      }
      setConfirm(null);
      setReloadToken((token) => token + 1);
    } catch (error) {
      setActionError(
        error instanceof ApiError ? error.message : "We could not complete this action. Please try again.",
      );
    } finally {
      setWorking(false);
    }
  };

  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its sales invoices." />;
    }
    if (!canView) {
      return <EmptyState title="Invoices unavailable" description="Your role does not include access to sales invoices." />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading sales invoices…" />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Invoices unavailable" description={state.message} />;
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
        <EmptyState title="No invoices on this page" description="Go back to the previous page." />
      ) : (
        <EmptyState title="No sales invoices yet" description="Create your first invoice to get started." />
      );
    }

    return (
      <div className="flex flex-col gap-4">
        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead className="bg-surface-muted">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Invoice</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Customer</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Due</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Total</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Outstanding</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Status</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {state.items.map((invoice) => (
                <tr key={invoice.id}>
                  <td className="px-3 py-2 font-mono text-xs">
                    <Link className="text-foreground hover:underline" href={`/sales-invoices/${invoice.id}`}>
                      {invoice.invoice_number}
                    </Link>
                  </td>
                  <td className="px-3 py-2">{invoice.customer?.name ?? invoice.customer_id}</td>
                  <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(invoice.invoice_date)}</td>
                  <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(invoice.due_date)}</td>
                  <td className="px-3 py-2 text-right">{money(invoice.grand_total)}</td>
                  <td className="px-3 py-2 text-right">{money(invoice.balance_due)}</td>
                  <td className="px-3 py-2">
                    <Badge variant={invoiceStatusVariant(invoice.status)}>{invoiceStatusLabel(invoice.status)}</Badge>
                  </td>
                  <td className="px-3 py-2">
                    <div className="flex justify-end gap-2">
                      {canUpdate && invoice.status === "DRAFT" ? (
                        <Link href={`/sales-invoices/${invoice.id}/edit`}>
                          <Button size="sm" variant="secondary">
                            Edit
                          </Button>
                        </Link>
                      ) : null}
                      {canPost && invoice.status === "DRAFT" ? (
                        <Button
                          size="sm"
                          variant="primary"
                          onClick={() => {
                            setActionError(null);
                            setConfirm({ invoice, action: "post" });
                          }}
                        >
                          Post
                        </Button>
                      ) : null}
                      {canDelete && invoice.status === "DRAFT" ? (
                        <Button
                          size="sm"
                          variant="danger"
                          onClick={() => {
                            setActionError(null);
                            setConfirm({ invoice, action: "delete" });
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
          <Button
            type="button"
            variant="secondary"
            size="sm"
            disabled={page <= 1}
            onClick={() => setPage((current) => Math.max(1, current - 1))}
          >
            Previous
          </Button>
          <span className="text-sm text-muted-foreground">Page {page}</span>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            disabled={!state.hasMore}
            onClick={() => setPage((current) => current + 1)}
          >
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
          <h1 className="text-2xl font-semibold text-foreground">Sales Invoices</h1>
          <p className="mt-1 text-sm text-muted-foreground">Draft, post, and track customer invoices.</p>
        </div>
        {canCreate ? (
          <Link href="/sales-invoices/new">
            <Button type="button">New invoice</Button>
          </Link>
        ) : null}
      </div>

      <form
        onSubmit={apply}
        className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      >
        <FormField label="Search" htmlFor="invoice-search">
          <Input
            id="invoice-search"
            value={draft.search}
            placeholder="Number, customer, notes…"
            onChange={(event) => setDraft((current) => ({ ...current, search: event.target.value }))}
          />
        </FormField>
        <FormField label="Customer" htmlFor="invoice-customer">
          <select
            id="invoice-customer"
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
        <FormField label="Status" htmlFor="invoice-status">
          <select
            id="invoice-status"
            className={selectClasses}
            value={draft.status}
            onChange={(event) =>
              setDraft((current) => ({ ...current, status: event.target.value as SalesInvoiceStatus | "" }))
            }
          >
            <option value="">All</option>
            <option value="DRAFT">Draft</option>
            <option value="POSTED">Posted</option>
            <option value="PARTIALLY_PAID">Partially paid</option>
            <option value="PAID">Paid</option>
          </select>
        </FormField>
        <FormField label="From" htmlFor="invoice-from">
          <Input
            id="invoice-from"
            type="date"
            value={draft.from}
            onChange={(event) => setDraft((current) => ({ ...current, from: event.target.value }))}
          />
        </FormField>
        <FormField label="To" htmlFor="invoice-to">
          <Input
            id="invoice-to"
            type="date"
            value={draft.to}
            onChange={(event) => setDraft((current) => ({ ...current, to: event.target.value }))}
          />
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
        title={confirm?.action === "delete" ? "Delete draft invoice" : "Post invoice"}
        description={
          confirm
            ? confirm.action === "delete"
              ? `Delete draft ${confirm.invoice.invoice_number}? This cannot be undone and the number is not reused.`
              : `Post ${confirm.invoice.invoice_number}? Posting writes the accounting entries and makes the invoice immutable.`
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
