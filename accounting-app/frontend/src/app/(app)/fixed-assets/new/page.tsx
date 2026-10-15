import type { Metadata } from "next";

import { FixedAssetForm } from "@/components/fixed-assets/fixed-asset-form";

export const metadata: Metadata = {
  title: "New fixed asset",
};

export default function NewFixedAssetPage() {
  return <FixedAssetForm />;
}
