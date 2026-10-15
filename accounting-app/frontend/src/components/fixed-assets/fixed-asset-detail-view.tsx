"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type ReactNode } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { LoadingState } from "@/components/ui/loading-state";
import { formatDateOnly, formatTimestamp } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import type { AccountRef, FixedAsset } from "@/lib/fixed-assets/types";
import { useFixedAsset } from "@/lib/fixed-assets/use-fixed-asset";

import {
  CapitalizeDialog,
  DeleteFixedAssetDialog,
  DepreciateDialog,
  DisposeDialog,
} from "./fixed-asset-action-dialogs";
import { FixedAssetStatusBadge } from "./fixed-asset-status-badge";
import { DepreciationSchedule } from "./depreciation-schedule";

/**
 * Fixed-asset detail.
 *
 * Shows the asset's identity, acquisition, accounts, and the server-computed
 * book values (accumulated depreciation, net book value, monthly charge, and the
 * period counters), then its posted depreciation history, its remaining schedule,
 * and — when disposed — the disposal and its gain/loss. Lifecycle actions are
 * gated by permission and by the asset's state so the UI only offers transitions
 * the backend will accept.
 */

function money(value: string | null): string {
  return formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });
}

function accountLabel(ref: AccountRef | null): string {
  if (!ref) return "—";
  return `${ref.code} — ${ref.name}`;
}

function SummaryItem({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="text-sm text-foreground">{children}</dd>
    </div>
  );
}

export function FixedAssetDetailView({ assetId }: { assetId: number }) {
  const router = useRouter();
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.fixed_asset.view");
  const canUpdate = can("accounting.fixed_asset.update");
  const canDelete = can("accounting.fixed_asset.delete");
  const canCapitalize = can("accounting.fixed_asset.capitalize");
  const canDepreciate = can("accounting.fixed_asset.depreciate");
  const canDispose = can("accounting.fixed_asset.dispose");

  const [reloadToken, setReloadToken] = useState(0);
  const [capitalizeOpen, setCapitalizeOpen] = useState(false);
  const [depreciateOpen, setDepreciateOpen] = useState(false);
  const [disposeOpen, setDisposeOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  const state = useFixedAsset({ id: assetId, companyId, enabled: canView, reloadToken });

  const reload = () => {
    setReloadToken((token) => token + 1);
    router.refresh();
  };

  const backLink = (
    <Link href="/fixed-assets" className="text-sm font-medium text-primary hover:underline">
      ← Fixed assets
    </Link>
  );

  if (!activeCompany) {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState title="No company selected" description="Choose a company to view this asset." />
      </div>
    );
  }

  if (!canView) {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState
          title="Fixed assets unavailable"
          description="Your role does not include access to fixed assets."
        />
      </div>
    );
  }

  if (state.status === "loading") {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <LoadingState label="Loading asset…" />
      </div>
    );
  }

  if (state.status === "notfound") {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState title="Asset not found" description="This fixed asset does not exist or is not in this company." />
      </div>
    );
  }

  if (state.status === "unavailable") {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState title="Asset unavailable" description={state.message} />
      </div>
    );
  }

  if (state.status === "error" || !state.asset) {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <ErrorState
          title="Could not load asset"
          message={state.message ?? "The asset is unavailable."}
          requestId={state.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      </div>
    );
  }

  const asset: FixedAsset = state.asset;
  const isDraft = asset.status === "DRAFT";
  const isActive = asset.status === "ACTIVE";
  const canDisposeNow = asset.status === "ACTIVE" || asset.status === "FULLY_DEPRECIATED";

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-4">
        {backLink}
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="text-2xl font-semibold text-foreground">{asset.asset_number}</h1>
              <FixedAssetStatusBadge status={asset.status} />
              <Badge variant="neutral">
                {asset.acquisition_method === "CASH" ? "Cash" : "Supplier credit"}
              </Badge>
            </div>
            <p className="text-sm text-muted-foreground">{asset.name}</p>
          </div>

          <div className="flex flex-wrap gap-2">
            {canUpdate && isDraft ? (
              <Link href={`/fixed-assets/${asset.id}/edit`}>
                <Button variant="secondary">Edit</Button>
              </Link>
            ) : null}
            {canCapitalize && isDraft ? (
              <Button onClick={() => setCapitalizeOpen(true)}>Capitalise</Button>
            ) : null}
            {canDepreciate && isActive ? (
              <Button onClick={() => setDepreciateOpen(true)}>Post depreciation</Button>
            ) : null}
            {canDispose && canDisposeNow ? (
              <Button variant="danger" onClick={() => setDisposeOpen(true)}>
                Dispose
              </Button>
            ) : null}
            {canDelete && isDraft ? (
              <Button variant="danger" onClick={() => setDeleteOpen(true)}>
                Delete
              </Button>
            ) : null}
          </div>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Asset</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="grid grid-cols-2 gap-4">
              <SummaryItem label="Category">
                {asset.category ? `${asset.category.code} — ${asset.category.name}` : "—"}
              </SummaryItem>
              <SummaryItem label="Serial number">{asset.serial_number ?? "—"}</SummaryItem>
              <SummaryItem label="Supplier reference">{asset.supplier_reference ?? "—"}</SummaryItem>
              <SummaryItem label="Acquired">{formatDateOnly(asset.acquisition_date)}</SummaryItem>
              <SummaryItem label="Depreciation start">
                {formatDateOnly(asset.depreciation_start_date)}
              </SummaryItem>
              <SummaryItem label="Method">Straight line</SummaryItem>
              <div className="col-span-2">
                <SummaryItem label="Description">{asset.description ?? "—"}</SummaryItem>
              </div>
            </dl>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Book values</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="grid grid-cols-2 gap-4">
              <SummaryItem label="Original cost">
                <span className="tabular-nums">{money(asset.original_cost)}</span>
              </SummaryItem>
              <SummaryItem label="Residual value">
                <span className="tabular-nums">{money(asset.salvage_value)}</span>
              </SummaryItem>
              <SummaryItem label="Accumulated depreciation">
                <span className="tabular-nums">{money(asset.accumulated_depreciation)}</span>
              </SummaryItem>
              <SummaryItem label="Net book value">
                <span className="tabular-nums">{money(asset.carrying_value)}</span>
              </SummaryItem>
              <SummaryItem label="Monthly charge">
                <span className="tabular-nums">{money(asset.monthly_charge)}</span>
              </SummaryItem>
              <SummaryItem label="Periods">
                {asset.period_count - asset.remaining_period_count} of {asset.period_count} posted
              </SummaryItem>
            </dl>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Accounts</CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <SummaryItem label="Asset account">{accountLabel(asset.asset_account)}</SummaryItem>
            <SummaryItem label="Accumulated depreciation">
              {accountLabel(asset.accumulated_depreciation_account)}
            </SummaryItem>
            <SummaryItem label="Depreciation expense">
              {accountLabel(asset.depreciation_expense_account)}
            </SummaryItem>
            <SummaryItem label="Acquisition account">{accountLabel(asset.acquisition_account)}</SummaryItem>
            <SummaryItem label="Acquisition journal">
              {asset.journal ? asset.journal.journal_number : "—"}
            </SummaryItem>
            <SummaryItem label="Capitalised">
              {asset.capitalised_at ? formatTimestamp(asset.capitalised_at) : "—"}
            </SummaryItem>
          </dl>
        </CardContent>
      </Card>

      {asset.disposal ? (
        <Card>
          <CardHeader>
            <CardTitle>Disposal</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <SummaryItem label="Disposal date">{formatDateOnly(asset.disposal.disposal_date)}</SummaryItem>
              <SummaryItem label="Proceeds">
                <span className="tabular-nums">{money(asset.disposal.proceeds)}</span>
              </SummaryItem>
              <SummaryItem label="Carrying value at disposal">
                <span className="tabular-nums">{money(asset.disposal.carrying_value_at_disposal)}</span>
              </SummaryItem>
              <SummaryItem label="Gain">
                <span className="tabular-nums">{money(asset.disposal.gain)}</span>
              </SummaryItem>
              <SummaryItem label="Loss">
                <span className="tabular-nums">{money(asset.disposal.loss)}</span>
              </SummaryItem>
              <SummaryItem label="Net result">
                <span className="tabular-nums">{money(asset.disposal.net_result)}</span>
              </SummaryItem>
              <SummaryItem label="Proceeds account">{accountLabel(asset.disposal.proceeds_account)}</SummaryItem>
              <SummaryItem label="Journal">
                {asset.disposal.journal ? asset.disposal.journal.journal_number : "—"}
              </SummaryItem>
              <div className="sm:col-span-2 lg:col-span-3">
                <SummaryItem label="Reason">{asset.disposal.reason ?? "—"}</SummaryItem>
              </div>
            </dl>
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Depreciation history</CardTitle>
        </CardHeader>
        <CardContent>
          {asset.depreciations && asset.depreciations.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[44rem] border-collapse text-sm">
                <caption className="sr-only">Posted depreciation charges</caption>
                <thead>
                  <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th scope="col" className="px-3 py-2 font-medium">Period</th>
                    <th scope="col" className="px-3 py-2 font-medium">From</th>
                    <th scope="col" className="px-3 py-2 font-medium">To</th>
                    <th scope="col" className="px-3 py-2 text-right font-medium">Amount</th>
                    <th scope="col" className="px-3 py-2 font-medium">Journal</th>
                    <th scope="col" className="px-3 py-2 font-medium">Posted</th>
                  </tr>
                </thead>
                <tbody>
                  {asset.depreciations.map((entry) => (
                    <tr key={entry.id} className="border-b border-border/70">
                      <td className="px-3 py-2 tabular-nums">
                        {entry.period_number}
                        {entry.is_final_period ? (
                          <span className="ml-2 text-xs text-muted-foreground">final</span>
                        ) : null}
                      </td>
                      <td className="px-3 py-2 text-muted-foreground">
                        {formatDateOnly(entry.period_start_date)}
                      </td>
                      <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(entry.period_end_date)}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{money(entry.amount)}</td>
                      <td className="px-3 py-2 text-muted-foreground">
                        {entry.journal ? entry.journal.journal_number : "—"}
                      </td>
                      <td className="px-3 py-2 text-muted-foreground">
                        {entry.posted_at ? formatTimestamp(entry.posted_at) : "—"}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">No depreciation has been posted yet.</p>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Upcoming schedule</CardTitle>
        </CardHeader>
        <CardContent>
          <DepreciationSchedule
            assetId={asset.id}
            companyId={companyId}
            enabled={canView}
            reloadToken={reloadToken}
          />
        </CardContent>
      </Card>

      <CapitalizeDialog
        open={capitalizeOpen}
        asset={asset}
        onClose={() => setCapitalizeOpen(false)}
        onDone={() => {
          setCapitalizeOpen(false);
          reload();
        }}
      />
      <DepreciateDialog
        open={depreciateOpen}
        asset={asset}
        onClose={() => setDepreciateOpen(false)}
        onDone={() => {
          setDepreciateOpen(false);
          reload();
        }}
      />
      <DisposeDialog
        open={disposeOpen}
        asset={asset}
        companyId={companyId}
        onClose={() => setDisposeOpen(false)}
        onDone={() => {
          setDisposeOpen(false);
          reload();
        }}
      />
      <DeleteFixedAssetDialog
        open={deleteOpen}
        asset={asset}
        onClose={() => setDeleteOpen(false)}
        onDeleted={() => {
          setDeleteOpen(false);
          router.push("/fixed-assets");
          router.refresh();
        }}
      />
    </div>
  );
}
