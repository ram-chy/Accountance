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
import { useSupplier } from "@/lib/suppliers/use-supplier";

function DetailRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="text-sm text-foreground">{value}</dd>
    </div>
  );
}

export function SupplierDetailView({ supplierId }: { supplierId: number }) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("suppliers.view");
  const [reloadToken, setReloadToken] = useState(0);

  const state = useSupplier({ supplierId, companyId, enabled: canView, reloadToken });

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view this supplier." />;
    }
    if (!canView) {
      return <EmptyState title="Supplier unavailable" description="Your role does not include access to suppliers." />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading supplier…" />;
    }
    if (state.status === "notfound") {
      return <EmptyState title="Supplier not found" description="This supplier does not exist for the selected company." />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Supplier unavailable" description={state.message} />;
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

    const s = state.data;
    return (
      <div className="flex flex-col gap-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-2xl font-semibold text-foreground">{s.name}</h1>
            <p className="mt-1 font-mono text-sm text-muted-foreground">{s.supplier_code}</p>
          </div>
          <Badge variant={s.is_active ? "success" : "neutral"}>{s.is_active ? "Active" : "Inactive"}</Badge>
        </div>

        <div className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-3">
          <DetailRow label="Email" value={s.email ?? "—"} />
          <DetailRow label="Phone" value={s.phone ?? "—"} />
          <DetailRow label="Tax identifier" value={s.tax_identifier ?? "—"} />
          <DetailRow label="Address" value={s.address ?? "—"} />
          <DetailRow label="City" value={s.city ?? "—"} />
          <DetailRow label="State/Region" value={s.state ?? "—"} />
          <DetailRow label="Country" value={s.country_code ?? "—"} />
          <DetailRow
            label="Payable account"
            value={
              s.payable_account
                ? `${s.payable_account.code} — ${s.payable_account.name}`
                : String(s.payable_account_id)
            }
          />
          <DetailRow label="Updated" value={formatTimestamp(s.updated_at)} />
        </div>

        <div className="flex flex-wrap gap-2">
          <Link href={`/purchase-bills?supplier_id=${s.id}`}>
            <Button variant="secondary" size="sm">
              Bills
            </Button>
          </Link>
          <Link href={`/supplier-payments?supplier_id=${s.id}`}>
            <Button variant="secondary" size="sm">
              Payments
            </Button>
          </Link>
          <Link href={`/reports/supplier-statement?supplier_id=${s.id}`}>
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
