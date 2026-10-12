"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { formatDateOnly } from "@/lib/format/date";
import { formatDecimal } from "@/lib/format/money";
import type { Tax } from "@/lib/taxes/types";
import { useTax } from "@/lib/taxes/use-tax";
import { useTaxRates } from "@/lib/taxes/use-tax-rates";

import { TaxActionDialog, type TaxActionKind } from "./tax-action-dialog";
import { TaxDetailSkeleton } from "./tax-detail-skeleton";
import { TaxFormDialog, type TaxEditorMode } from "./tax-form-dialog";
import { TaxMappingPanel } from "./tax-mapping-panel";
import { TaxRateActionDialog, type RateActionKind } from "./tax-rate-action-dialog";
import { TaxRateFormDialog, type RateEditorMode } from "./tax-rate-form-dialog";
import { TaxRatesTable } from "./tax-rates-table";

/**
 * Tax detail screen.
 *
 * Reads one tax (`useTax`) and its effective-dated rate history (`useTaxRates`),
 * then hosts every write that touches them: editing the tax, its lifecycle,
 * deleting it, and the create/edit/activate/deactivate/delete of individual rates.
 * It also hosts the account-mapping panel.
 *
 * Nothing here is computed locally — the current rate, the side flags and the
 * mapping all come straight from the backend, and every write re-reads the tax so
 * the screen only ever shows authoritative state. Deletion navigates back to the
 * list; a 404 renders the not-found boundary, which is also what a cross-company
 * id produces.
 */

type RateActionTarget = { rateId: number; action: RateActionKind };

type ActionTarget = { action: TaxActionKind };

const TYPE_LABEL: Record<Tax["tax_type"], string> = {
  OUTPUT: "Output — charged on sales",
  INPUT: "Input — recovered on purchases",
  BOTH: "Both — sales and purchases",
};

const BASIS_LABEL: Record<Tax["calculation_basis"], string> = {
  EXCLUSIVE: "Exclusive — amounts do not include tax",
  INCLUSIVE: "Inclusive — amounts already include tax",
};

function SummaryItem({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </dt>
      <dd className="text-sm text-foreground">{children}</dd>
    </div>
  );
}

export function TaxDetailView({ taxId }: { taxId: number }) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const router = useRouter();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.tax.view");
  const canCreate = can("accounting.tax.create");
  const canUpdate = can("accounting.tax.update");
  const canDelete = can("accounting.tax.delete");
  const canViewAccounts = can("accounts.view");

  const [reloadToken, setReloadToken] = useState(0);
  const [editor, setEditor] = useState<TaxEditorMode | null>(null);
  const [actionTarget, setActionTarget] = useState<ActionTarget | null>(null);
  const [rateEditor, setRateEditor] = useState<RateEditorMode | null>(null);
  const [rateActionTarget, setRateActionTarget] = useState<RateActionTarget | null>(null);

  const detail = useTax({ taxId, companyId, enabled: canView, reloadToken });
  const rates = useTaxRates({ taxId, companyId, enabled: canView, reloadToken });

  const reload = () => setReloadToken((token) => token + 1);

  const tax = detail.status === "ready" ? detail.tax : null;

  const header = (() => {
    if (tax === null) {
      return (
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold text-foreground">Tax</h1>
        </div>
      );
    }

    return (
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex flex-col gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-semibold text-foreground">
              {tax.code} · {tax.name}
            </h1>
            <Badge variant={tax.is_active ? "success" : "neutral"}>
              {tax.is_active ? "Active" : "Inactive"}
            </Badge>
            {tax.current_rate === null ? <Badge variant="warning">No current rate</Badge> : null}
          </div>
          <p className="text-sm text-muted-foreground">
            {TYPE_LABEL[tax.tax_type]} · {BASIS_LABEL[tax.calculation_basis]}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {canUpdate ? (
            <Button variant="secondary" onClick={() => setEditor({ mode: "edit", tax })}>
              Edit
            </Button>
          ) : null}
          {canUpdate ? (
            tax.is_active ? (
              <Button
                variant="ghost"
                onClick={() => setActionTarget({ action: "deactivate" })}
              >
                Deactivate
              </Button>
            ) : (
              <Button variant="ghost" onClick={() => setActionTarget({ action: "activate" })}>
                Activate
              </Button>
            )
          ) : null}
          {canDelete ? (
            <Button
              variant="ghost"
              className="text-danger hover:bg-danger-soft hover:text-danger"
              onClick={() => setActionTarget({ action: "delete" })}
            >
              Delete
            </Button>
          ) : null}
        </div>
      </div>
    );
  })();

  const ratesBody = (() => {
    if (rates.status === "loading") {
      return <TaxDetailSkeleton />;
    }

    if (rates.status === "unavailable") {
      return <EmptyState title="Tax rates unavailable" description={rates.message} />;
    }

    if (rates.status === "error") {
      return (
        <ErrorState
          title="Could not load tax rates"
          message={rates.message}
          requestId={rates.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      );
    }

    if (rates.items.length === 0) {
      return (
        <EmptyState
          title="No rates yet"
          description="Add a rate period so this tax can be applied. A tax with no rate in force cannot be calculated."
          action={
            canCreate ? (
              <Button onClick={() => setRateEditor({ mode: "create" })}>New rate</Button>
            ) : undefined
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <TaxRatesTable
          items={rates.items}
          canUpdate={canUpdate}
          canDelete={canDelete}
          onEdit={(rate) => setRateEditor({ mode: "edit", rate })}
          onActivate={(rate) => setRateActionTarget({ rateId: rate.id, action: "activate" })}
          onDeactivate={(rate) => setRateActionTarget({ rateId: rate.id, action: "deactivate" })}
          onDelete={(rate) => setRateActionTarget({ rateId: rate.id, action: "delete" })}
        />
      </div>
    );
  })();

  const body = (() => {
    if (!activeCompany) {
      return (
        <EmptyState
          title="No company selected"
          description="Choose a company to view this tax."
        />
      );
    }

    if (!canView) {
      return (
        <EmptyState
          title="Taxes unavailable"
          description="Your role does not include access to taxes."
        />
      );
    }

    if (detail.status === "loading") {
      return <TaxDetailSkeleton />;
    }

    if (detail.status === "notfound") {
      return (
        <EmptyState
          title="Tax not found"
          description="This tax does not exist, or belongs to another company."
          action={
            <Link href="/taxes" className="text-sm font-medium text-primary">
              Back to taxes
            </Link>
          }
        />
      );
    }

    if (detail.status === "unavailable") {
      return <EmptyState title="Tax unavailable" description={detail.message} />;
    }

    if (detail.status === "error") {
      return (
        <ErrorState
          title="Could not load this tax"
          message={detail.message}
          requestId={detail.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      );
    }

    if (tax === null) {
      return <TaxDetailSkeleton />;
    }

    return (
      <div className="flex flex-col gap-6">
        <section className="rounded-lg border border-border bg-surface p-4 sm:p-6">
          <h2 className="text-sm font-semibold text-foreground">Details</h2>
          <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <SummaryItem label="Code">{tax.code}</SummaryItem>
            <SummaryItem label="Name">{tax.name}</SummaryItem>
            <SummaryItem label="Current rate">
              {tax.current_rate === null
                ? "No rate in force today"
                : `${formatDecimal(tax.current_rate, { fallback: "—" })}%`}
            </SummaryItem>
            <SummaryItem label="Applies to">
              {tax.applies_to_sales && tax.applies_to_purchase
                ? "Sales and purchases"
                : tax.applies_to_sales
                  ? "Sales"
                  : "Purchases"}
            </SummaryItem>
            <SummaryItem label="Created">
              {tax.created_at === null ? "—" : formatDateOnly(tax.created_at.slice(0, 10))}
            </SummaryItem>
            <SummaryItem label="Last updated">
              {tax.updated_at === null ? "—" : formatDateOnly(tax.updated_at.slice(0, 10))}
            </SummaryItem>
            {tax.description ? (
              <div className="flex flex-col gap-1 sm:col-span-2 lg:col-span-3">
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  Description
                </dt>
                <dd className="text-sm text-foreground">{tax.description}</dd>
              </div>
            ) : null}
          </dl>
        </section>

        <TaxMappingPanel
          companyId={companyId}
          taxId={tax.id}
          mapping={tax.account_mapping}
          canUpdate={canUpdate}
          canViewAccounts={canViewAccounts}
          onSaved={reload}
        />

        <section className="flex flex-col gap-3">
          <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
            <div className="flex flex-col gap-1">
              <h2 className="text-base font-semibold text-foreground">Rates</h2>
              <p className="text-sm text-muted-foreground">
                Effective-dated percentage periods. A tax has exactly one rate in force on any
                given day.
              </p>
            </div>
            {canCreate ? (
              <Button onClick={() => setRateEditor({ mode: "create" })}>New rate</Button>
            ) : null}
          </div>

          {ratesBody}
        </section>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <Link href="/taxes" className="text-sm font-medium text-muted-foreground hover:text-foreground">
        ← All taxes
      </Link>

      {header}
      {body}

      <TaxFormDialog
        key={editor?.mode === "edit" ? `edit-${editor.tax.id}` : "create"}
        editor={editor}
        onClose={() => setEditor(null)}
        onSaved={reload}
      />

      <TaxActionDialog
        tax={tax}
        action={actionTarget?.action ?? null}
        onClose={() => setActionTarget(null)}
        onDone={async () => {
          if (actionTarget?.action === "delete") {
            router.push("/taxes");
            return;
          }
          reload();
        }}
      />

      <TaxRateFormDialog
        key={rateEditor?.mode === "edit" ? `rate-edit-${rateEditor.rate.id}` : "rate-create"}
        taxId={taxId}
        editor={rateEditor}
        onClose={() => setRateEditor(null)}
        onSaved={reload}
      />

      <TaxRateActionDialog
        taxId={taxId}
        rate={rates.items.find((rate) => rate.id === rateActionTarget?.rateId) ?? null}
        action={rateActionTarget?.action ?? null}
        onClose={() => setRateActionTarget(null)}
        onDone={reload}
      />
    </div>
  );
}
