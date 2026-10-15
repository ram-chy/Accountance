import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { FixedAssetEditView } from "@/components/fixed-assets/fixed-asset-edit-view";

export const metadata: Metadata = {
  title: "Edit fixed asset",
};

type FixedAssetEditPageProps = {
  params: Promise<{ id: string }>;
};

export default async function FixedAssetEditPage({ params }: FixedAssetEditPageProps) {
  const { id } = await params;
  const assetId = Number(id);

  if (!Number.isInteger(assetId) || assetId <= 0) {
    notFound();
  }

  return <FixedAssetEditView assetId={assetId} />;
}
