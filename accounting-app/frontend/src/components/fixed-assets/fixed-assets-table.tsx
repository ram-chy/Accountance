"use client";

import Link from "next/link";

import { formatDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import type { FixedAsset } from "@/lib/fixed-assets/types";

import { FixedAssetStatusBadge } from "./fixed-asset-status-badge";

/**
 * Responsive fixed-asset list: a table on medium+ screens and stacked cards
 * below.
 *
 * Columns mirror the verified `FixedAssetResource` only — asset number, name,
 * category, acquisition date, cost, residual, useful life, and the
 * server-computed accumulated depreciation and net book value. No total row is
 * shown because the list endpoint returns a flat array with no totals.
 *
 * Edit is offered for drafts only, matching the backend rule that update and
 * delete are refused once an asset is capitalised.
 */

type FixedAssetsTableProps = {
  items: FixedAsset[];
  canUpdate: boolean;
};

function money(value: string | null): string {
  return formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });
}

function usefulLife(months: number): string {
  if (months % 12 === 0) {
    const years = months / 12;
    return `${years} ${years === 1 ? "year" : "years"}`;
  }
  return `${months} ${months === 1 ? "month" : "months"}`;
}

export function FixedAssetsTable({ items, canUpdate }: FixedAssetsTableProps) {
  return (
    <>
      {/* Table for md+ */}
      <table className="hidden w-full min-w-[64rem] border-collapse text-sm md:table">
        <caption className="sr-only">Fixed assets</caption>
        <thead>
          <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
            <th scope="col" className="px-3 py-2 font-medium">Asset</th>
            <th scope="col" className="px-3 py-2 font-medium">Category</th>
            <th scope="col" className="px-3 py-2 font-medium">Acquired</th>
            <th scope="col" className="px-3 py-2 text-right font-medium">Cost</th>
            <th scope="col" className="px-3 py-2 text-right font-medium">Residual</th>
            <th scope="col" className="px-3 py-2 font-medium">Useful life</th>
            <th scope="col" className="px-3 py-2 text-right font-medium">Accum. dep.</th>
            <th scope="col" className="px-3 py-2 text-right font-medium">Net book value</th>
            <th scope="col" className="px-3 py-2 font-medium">Status</th>
            <th scope="col" className="px-3 py-2 text-right font-medium">Actions</th>
          </tr>
        </thead>
        <tbody>
          {items.map((asset) => (
            <tr key={asset.id} className="border-b border-border/70 align-top">
              <td className="px-3 py-3">
                <Link href={`/fixed-assets/${asset.id}`} className="font-medium text-primary hover:underline">
                  {asset.asset_number}
                </Link>
                <div className="text-muted-foreground">{asset.name}</div>
              </td>
              <td className="px-3 py-3 text-muted-foreground">
                {asset.category ? `${asset.category.code} — ${asset.category.name}` : "—"}
              </td>
              <td className="px-3 py-3 text-muted-foreground">{formatDateOnly(asset.acquisition_date)}</td>
              <td className="px-3 py-3 text-right tabular-nums">{money(asset.original_cost)}</td>
              <td className="px-3 py-3 text-right tabular-nums text-muted-foreground">{money(asset.salvage_value)}</td>
              <td className="px-3 py-3 text-muted-foreground">{usefulLife(asset.useful_life_months)}</td>
              <td className="px-3 py-3 text-right tabular-nums">{money(asset.accumulated_depreciation)}</td>
              <td className="px-3 py-3 text-right tabular-nums">{money(asset.carrying_value)}</td>
              <td className="px-3 py-3">
                <FixedAssetStatusBadge status={asset.status} />
              </td>
              <td className="px-3 py-3">
                <div className="flex justify-end gap-3">
                  <Link
                    href={`/fixed-assets/${asset.id}`}
                    className="text-sm font-medium text-primary hover:underline"
                  >
                    View
                  </Link>
                  {canUpdate && asset.status === "DRAFT" ? (
                    <Link
                      href={`/fixed-assets/${asset.id}/edit`}
                      className="text-sm font-medium text-primary hover:underline"
                    >
                      Edit
                    </Link>
                  ) : null}
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {/* Cards below md */}
      <ul className="flex flex-col gap-3 md:hidden">
        {items.map((asset) => (
          <li key={asset.id} className="rounded-lg border border-border bg-surface p-4">
            <div className="flex items-start justify-between gap-3">
              <div>
                <Link href={`/fixed-assets/${asset.id}`} className="font-medium text-primary hover:underline">
                  {asset.asset_number}
                </Link>
                <p className="text-sm text-muted-foreground">{asset.name}</p>
              </div>
              <FixedAssetStatusBadge status={asset.status} />
            </div>
            <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
              <div>
                <dt className="text-xs uppercase tracking-wide text-muted-foreground">Category</dt>
                <dd>{asset.category ? asset.category.name : "—"}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-muted-foreground">Acquired</dt>
                <dd>{formatDateOnly(asset.acquisition_date)}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-muted-foreground">Cost</dt>
                <dd className="tabular-nums">{money(asset.original_cost)}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-muted-foreground">Net book value</dt>
                <dd className="tabular-nums">{money(asset.carrying_value)}</dd>
              </div>
            </dl>
            <div className="mt-3 flex gap-3">
              <Link href={`/fixed-assets/${asset.id}`} className="text-sm font-medium text-primary hover:underline">
                View
              </Link>
              {canUpdate && asset.status === "DRAFT" ? (
                <Link href={`/fixed-assets/${asset.id}/edit`} className="text-sm font-medium text-primary hover:underline">
                  Edit
                </Link>
              ) : null}
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
