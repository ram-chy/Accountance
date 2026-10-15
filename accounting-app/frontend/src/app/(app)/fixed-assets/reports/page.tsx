import type { Metadata } from "next";

import { FixedAssetReportsHubView } from "@/components/fixed-assets/reports-hub-view";

export const metadata: Metadata = {
  title: "Fixed asset reports",
};

export default function FixedAssetReportsPage() {
  return <FixedAssetReportsHubView />;
}
