"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { invoiceStatusLabel, invoiceStatusVariant } from "@/components/sales-invoices/sales-invoices-view";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { LoadingState } from "@/components/ui/loading-state";
import { ApiError } from "@/lib/api/errors";
import { formatDateOnly, formatTimestamp } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import { deleteSalesInvoice, postSalesInvoice } from "@/lib/sales-invoices/api";
import { useSalesInvoice } from "@/lib/sales-invoices/use-sales-invoice";

export function SalesInvoiceDetailView({ invoiceId }: { invoiceId: number }) {
  const router = useRouter();
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("sales.invoices.view");
  const canUpdate = can("sales.invoices.update");
  const canDelete = can("sales.invoices.delete");
  const canPost = can("sales.invoices.post");

  const [reloadToken, setReloadToken] = useState(0);
  const [confirm, setConfirm] = useState<"post" | "delete" | null>(null);
  const [working, setWorking] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const state = useSalesInvoice({ invoiceId, companyId, enabled: canView, reloadToken });

  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  const runAction = async () => {
    if (!confirm || working) return;
    setWorking(true);
    setActionError(null);
    try {
      if (confirm === "post") {
        await postSalesInvoice(invoiceId);
        setConfirm(null);
        setReloadToken((token) => token + 1);
      } else {
        await deleteSalesInvoice(invoiceId);
        router.push("/sales-invoices");
      }
    } catch (error) {
      setActionError(
        error instanceof ApiError ? error.message : "We could not complete this action. Please try again.",
      );
      setWorking(false);
    }
  };

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view this invoice." />;
    }
    if (!canView) {
      return <EmptyState title="Invoice unavailable" description="Your role does not include access to sales invoices." />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading invoice…" />;
    }
    if (state.status === "notfound") {
      return <EmptyState title="Invoice not found" description="This invoice does not exist for the selected company." />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Invoice unavailable" description={state.message} />;
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

    const invoice = state.data;
    const isDraft = invoice.status === "DRAFT";

    return (
      <div className="flex flex-col gap-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-semibold text-foreground">{invoice.invoice_number}</h1>
              <Badge variant={invoiceStatusVariant(invoice.status)}>{invoiceStatusLabel(invoice.status)}</Badge>
            </div>
            <p className="mt-1 text-sm text-muted-foreground">
              {invoice.customer
                ? `${invoice.customer.customer_code} — ${invoice.customer.name}`
                : `Customer ${invoice.customer_id}`}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            {canUpdate && isDraft ? (
              <Link href={`/sales-invoices/${invoice.id}/edit`}>
                <Button variant="secondary" size="sm">
                  Edit draft
                </Button>
              </Link>
            ) : null}
            {canPost && isDraft ? (
              <Button
                size="sm"
                onClick={() => {
                  setActionError(null);
                  setConfirm("post");
                }}
              >
                Post invoice
              </Button>
            ) : null}
            {canDelete && isDraft ? (
              <Button
                size="sm"
                variant="danger"
                onClick={() => {
                  setActionError(null);
                  setConfirm("delete");
                }}
              >
                Delete draft
              </Button>
            ) : null}
            <Link href="/sales-invoices">
              <Button variant="ghost" size="sm">
                Back
              </Button>
            </Link>
          </div>
        </div>

        <div className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <div className="text-xs uppercase text-muted-foreground">Invoice date</div>
            <div className="text-sm">{formatDateOnly(invoice.invoice_date)}</div>
          </div>
          <div>
            <div className="text-xs uppercase text-muted-foreground">Due date</div>
            <div className="text-sm">{formatDateOnly(invoice.due_date)}</div>
          </div>
          <div>
            <div className="text-xs uppercase text-muted-foreground">Posted</div>
            <div className="text-sm">{invoice.posted_at ? formatTimestamp(invoice.posted_at) : "Not posted"}</div>
          </div>
          <div>
            <div className="text-xs uppercase text-muted-foreground">Journal</div>
            <div className="text-sm">{invoice.journal_id ?? "—"}</div>
          </div>
        </div>

        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead className="bg-surface-muted">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">#</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Description</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Qty</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Unit price</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Discount</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Tax</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Line total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {invoice.lines.map((line) => (
                <tr key={line.id}>
                  <td className="px-3 py-2">{line.line_number}</td>
                  <td className="px-3 py-2">{line.description ?? "—"}</td>
                  <td className="px-3 py-2 text-right">{money(line.quantity)}</td>
                  <td className="px-3 py-2 text-right">{money(line.unit_price)}</td>
                  <td className="px-3 py-2 text-right">{money(line.discount)}</td>
                  <td className="px-3 py-2 text-right">{money(line.tax_amount)}</td>
                  <td className="px-3 py-2 text-right">{money(line.line_total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Subtotal</div>
            <div className="text-lg font-semibold">{money(invoice.subtotal)}</div>
          </div>
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Tax</div>
            <div className="text-lg font-semibold">{money(invoice.tax_total)}</div>
          </div>
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Grand total</div>
            <div className="text-lg font-semibold">{money(invoice.grand_total)}</div>
          </div>
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Outstanding</div>
            <div className="text-lg font-semibold">{money(invoice.balance_due)}</div>
            <div className="mt-1 text-xs text-muted-foreground">
              Paid: {money(invoice.paid_total)}
              {invoice.is_overdue ? ` · Overdue by ${invoice.days_overdue ?? 0} day(s)` : ""}
            </div>
          </div>
        </div>

        {invoice.notes ? (
          <div className="rounded-lg border border-border bg-surface-muted/40 p-4 text-sm">
            <div className="font-medium text-foreground">Notes</div>
            <p className="mt-1 whitespace-pre-wrap text-muted-foreground">{invoice.notes}</p>
          </div>
        ) : null}

        <Dialog
          open={confirm !== null}
          title={confirm === "delete" ? "Delete draft invoice" : "Post invoice"}
          description={
            confirm === "delete"
              ? `Delete draft ${invoice.invoice_number}? This cannot be undone and the number is not reused.`
              : `Post ${invoice.invoice_number}? Posting writes the accounting entries and makes the invoice immutable.`
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
                variant={confirm === "delete" ? "danger" : "primary"}
                loading={working}
                onClick={runAction}
              >
                {confirm === "delete" ? "Delete" : "Post"}
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
  })();

  return body;
}
