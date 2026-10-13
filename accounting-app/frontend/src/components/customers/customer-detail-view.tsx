"use client";

import Link from "next/link";
import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { LoadingState } from "@/components/ui/loading-state";
import { formatTimestamp } from "@/lib/format/date";
import { useCustomer } from "@/lib/customers/use-customer";

function DetailRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="text-sm text-foreground">{value}</dd>
    </div>
  );
}

export function CustomerDetailView({ customerId }: { customerId: number }) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("customers.view");
  const [reloadToken, setReloadToken] = useState(0);

  const state = useCustomer({ customerId, companyId, enabled: canView, reloadToken });

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view this customer." />;
    }
    if (!canView) {
      return <EmptyState title="Customer unavailable" description="Your role does not include access to customers." />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading customer…" />;
    }
    if (state.status === "notfound") {
      return <EmptyState title="Customer not found" description="This customer does not exist for the selected company." />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Customer unavailable" description={state.message} />;
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

    const c = state.data;
    return (
      <div className="flex flex-col gap-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-2xl font-semibold text-foreground">{c.name}</h1>
            <p className="mt-1 font-mono text-sm text-muted-foreground">{c.customer_code}</p>
          </div>
          <Badge variant={c.is_active ? "success" : "neutral"}>{c.is_active ? "Active" : "Inactive"}</Badge>
        </div>

        <div className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-3">
          <DetailRow label="Email" value={c.email ?? "—"} />
          <DetailRow label="Phone" value={c.phone ?? "—"} />
          <DetailRow label="Tax identifier" value={c.tax_identifier ?? "—"} />
          <DetailRow label="Address" value={c.address ?? "—"} />
          <DetailRow label="City" value={c.city ?? "—"} />
          <DetailRow label="State/Region" value={c.state ?? "—"} />
          <DetailRow label="Country" value={c.country_code ?? "—"} />
          <DetailRow
            label="Receivable account"
            value={
              c.receivable_account
                ? `${c.receivable_account.code} — ${c.receivable_account.name}`
                : String(c.receivable_account_id)
            }
          />
          <DetailRow label="Updated" value={formatTimestamp(c.updated_at)} />
        </div>

        <div className="flex flex-wrap gap-2">
          <Link href={`/sales-invoices?customer_id=${c.id}`}>
            <Button variant="secondary" size="sm">
              Invoices
            </Button>
          </Link>
          <Link href={`/customer-receipts?customer_id=${c.id}`}>
            <Button variant="secondary" size="sm">
              Receipts
            </Button>
          </Link>
          <Link href={`/reports/customer-statement?customer_id=${c.id}`}>
            <Button variant="secondary" size="sm">
              Statement
            </Button>
          </Link>
        </div>
      </div>
    );
  })();

  return body;
}
