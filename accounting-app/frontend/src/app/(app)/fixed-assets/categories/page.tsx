import type { Metadata } from "next";

import { FixedAssetCategoriesView } from "@/components/fixed-asset-categories/fixed-asset-categories-view";

export const metadata: Metadata = {
  title: "Fixed asset categories",
};

export default function FixedAssetCategoriesPage() {
  return <FixedAssetCategoriesView />;
}
