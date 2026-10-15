import type { Metadata } from "next";

import { DepreciationReportView } from "@/components/fixed-assets/depreciation-report-view";

export const metadata: Metadata = {
  title: "Depreciation report",
};

export default function DepreciationReportPage() {
  return <DepreciationReportView />;
}
