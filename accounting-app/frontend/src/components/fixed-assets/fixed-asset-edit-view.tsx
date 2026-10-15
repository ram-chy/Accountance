"use client";

import Link from "next/link";
import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { LoadingState } from "@/components/ui/loading-state";
import { useFixedAsset } from "@/lib/fixed-assets/use-fixed-asset";

import { FixedAssetForm } from "./fixed-asset-form";

/**
 * Edit route host.
 *
 * Loads the asset, then renders the form only while the asset is still a draft —
 * the backend refuses updates once an asset is capitalised, so the UI does not
 * offer the form in that state.
 */
export function FixedAssetEditView({ assetId }: { assetId: number }) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.fixed_asset.view");
  const canUpdate = can("accounting.fixed_asset.update");

  const [reloadToken, setReloadToken] = useState(0);
  const state = useFixedAsset({ id: assetId, companyId, enabled: canView, reloadToken });

  const backLink = (
    <Link href="/fixed-assets" className="text-sm font-medium text-primary hover:underline">
      ← Fixed assets
    </Link>
  );

  if (!activeCompany) {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState title="No company selected" description="Choose a company to edit this asset." />
      </div>
    );
  }

  if (!canView || !canUpdate) {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState
          title="Editing unavailable"
          description="Your role does not include permission to edit fixed assets."
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
            <Button variant="secondary" onClick={() => setReloadToken((token) => token + 1)}>
              Try again
            </Button>
          }
        />
      </div>
    );
  }

  if (state.asset.status !== "DRAFT") {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState
          title="This asset cannot be edited"
          description="Only draft assets can be edited. Capitalised assets are immutable in the ledger."
          action={
            <Link href={`/fixed-assets/${state.asset.id}`}>
              <Button variant="secondary">View asset</Button>
            </Link>
          }
        />
      </div>
    );
  }

  return <FixedAssetForm asset={state.asset} />;
}
