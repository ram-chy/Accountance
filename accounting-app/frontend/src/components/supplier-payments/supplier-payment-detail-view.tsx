"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import {
  paymentStatusLabel,
  paymentStatusVariant,
} from "@/components/supplier-payments/supplier-payments-view";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { LoadingState } from "@/components/ui/loading-state";
import { ApiError } from "@/lib/api/errors";
import { formatDateOnly, formatTimestamp } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import { deleteSupplierPayment, postSupplierPayment } from "@/lib/supplier-payments/api";
import { useSupplierPayment } from "@/lib/supplier-payments/use-supplier-payment";

export function SupplierPaymentDetailView({ paymentId }: { paymentId: number }) {
  const router = useRouter();
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("supplier.payments.view");
  const canUpdate = can("supplier.payments.update");
  const canDelete = can("supplier.payments.delete");
  const canPost = can("supplier.payments.post");

  const [reloadToken, setReloadToken] = useState(0);
  const [confirm, setConfirm] = useState<"post" | "delete" | null>(null);
  const [working, setWorking] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const state = useSupplierPayment({ paymentId, companyId, enabled: canView, reloadToken });

  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  const runAction = async () => {
    if (!confirm || working) return;
    setWorking(true);
    setActionError(null);
    try {
      if (confirm === "post") {
        await postSupplierPayment(paymentId);
        setConfirm(null);
        setReloadToken((token) => token + 1);
      } else {
        await deleteSupplierPayment(paymentId);
        router.push("/supplier-payments");
      }
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : "We could not complete this action. Please try again.");
      setWorking(false);
    }
  };

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view this payment." />;
    }
    if (!canView) {
      return <EmptyState title="Payment unavailable" description="Your role does not include access to supplier payments." />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading payment…" />;
    }
    if (state.status === "notfound") {
      return <EmptyState title="Payment not found" description="This payment does not exist for the selected company." />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Payment unavailable" description={state.message} />;
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

    const payment = state.data;
    const isDraft = payment.status === "DRAFT";

    return (
      <div className="flex flex-col gap-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-semibold text-foreground">{payment.payment_number}</h1>
              <Badge variant={paymentStatusVariant(payment.status)}>{paymentStatusLabel(payment.status)}</Badge>
            </div>
            <p className="mt-1 text-sm text-muted-foreground">
              {payment.supplier
                ? `${payment.supplier.supplier_code} — ${payment.supplier.name}`
                : `Supplier ${payment.supplier_id}`}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            {canUpdate && isDraft ? (
              <Link href={`/supplier-payments/${payment.id}/edit`}>
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
                Post payment
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
            <Link href="/supplier-payments">
              <Button variant="ghost" size="sm">
                Back
              </Button>
            </Link>
          </div>
        </div>

        <div className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <div className="text-xs uppercase text-muted-foreground">Payment date</div>
            <div className="text-sm">{formatDateOnly(payment.payment_date)}</div>
          </div>
          <div>
            <div className="text-xs uppercase text-muted-foreground">Amount</div>
            <div className="text-sm font-medium">{money(payment.amount)}</div>
          </div>
          <div>
            <div className="text-xs uppercase text-muted-foreground">Posted</div>
            <div className="text-sm">{payment.posted_at ? formatTimestamp(payment.posted_at) : "Not posted"}</div>
          </div>
          <div>
            <div className="text-xs uppercase text-muted-foreground">Reference</div>
            <div className="text-sm">{payment.reference ?? "—"}</div>
          </div>
        </div>

        <div className="flex flex-col gap-2">
          <h2 className="text-lg font-medium">Allocations</h2>
          <div className="overflow-x-auto rounded-lg border border-border bg-surface">
            <table className="min-w-full divide-y divide-border text-sm">
              <thead className="bg-surface-muted">
                <tr>
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Bill</th>
                  <th className="px-3 py-2 text-right font-medium text-muted-foreground">Allocated</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {payment.allocations.length === 0 ? (
                  <tr>
                    <td className="px-3 py-2 text-muted-foreground" colSpan={2}>
                      No allocations.
                    </td>
                  </tr>
                ) : (
                  payment.allocations.map((allocation) => (
                    <tr key={allocation.id}>
                      <td className="px-3 py-2 font-mono text-xs">
                        {allocation.purchase_bill ? (
                          <Link className="text-foreground hover:underline" href={`/purchase-bills/${allocation.purchase_bill.id}`}>
                            {allocation.purchase_bill.bill_number}
                          </Link>
                        ) : (
                          `#${allocation.purchase_bill_id}`
                        )}
                      </td>
                      <td className="px-3 py-2 text-right">{money(allocation.amount)}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        {payment.notes ? (
          <div className="rounded-lg border border-border bg-surface-muted/40 p-4 text-sm">
            <div className="font-medium text-foreground">Notes</div>
            <p className="mt-1 whitespace-pre-wrap text-muted-foreground">{payment.notes}</p>
          </div>
        ) : null}

        <Dialog
          open={confirm !== null}
          title={confirm === "delete" ? "Delete draft payment" : "Post payment"}
          description={
            confirm === "delete"
              ? `Delete draft ${payment.payment_number}? This cannot be undone.`
              : `Post ${payment.payment_number}? Posting writes the accounting entries and updates the settled bills.`
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
