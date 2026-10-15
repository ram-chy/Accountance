import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { FixedAssetDetailView } from "@/components/fixed-assets/fixed-asset-detail-view";

export const metadata: Metadata = {
  title: "Fixed asset",
};

type FixedAssetPageProps = {
  params: Promise<{ id: string }>;
};

export default async function FixedAssetPage({ params }: FixedAssetPageProps) {
  const { id } = await params;
  const assetId = Number(id);

  if (!Number.isInteger(assetId) || assetId <= 0) {
    notFound();
  }

  return <FixedAssetDetailView assetId={assetId} />;
}
