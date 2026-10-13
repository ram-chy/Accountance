import type { Metadata } from "next";

import { ReceivablesAgingView } from "@/components/reports/receivables-aging-view";

export const metadata: Metadata = {
  title: "Aged Receivables",
};

export default function ReceivablesAgingPage() {
  return <ReceivablesAgingView />;
}
